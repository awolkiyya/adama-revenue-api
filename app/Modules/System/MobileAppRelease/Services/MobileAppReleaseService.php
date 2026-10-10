
<?php

namespace App\Services;

use App\Models\File;
use App\Models\MobileAppRelease;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class MobileAppReleaseService
{
    /**
     * APK storage must use a private Laravel disk.
     */
    private function disk(): string
    {
        return config('filesystems.mobile_app_disk', 'local');
    }

    /**
     * Create a draft release and attach its APK.
     */
    public function create(
        array $data,
        UploadedFile $apk,
        ?string $userId
    ): MobileAppRelease {
        $disk = $this->disk();

        $path = $apk->storeAs(
            'mobile-app-releases',
            Str::uuid() . '.apk',
            $disk
        );

        if (! is_string($path)) {
            throw new RuntimeException('Unable to store the APK file.');
        }

        try {
            return DB::transaction(function () use (
                $data,
                $apk,
                $userId,
                $disk,
                $path
            ) {
                $release = MobileAppRelease::create([
                    'version_name' => $data['version_name'],
                    'version_code' => $data['version_code'],
                    'release_notes' => $data['release_notes'] ?? null,
                    'is_latest' => false,
                    'is_mandatory' => $data['is_mandatory'] ?? false,
                    'status' => 'draft',
                    'published_at' => null,
                    'created_by' => $userId,
                ]);

                $file = $this->createFileRecord(
                    $release,
                    $apk,
                    $disk,
                    $path,
                    $userId
                );

                $release->apkFile()->save($file);

                return $release->load(['creator', 'apkFile']);
            });
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($path);

            throw $exception;
        }
    }

    /**
     * Update a draft release.
     *
     * A new APK replaces the previous attachment.
     */
    public function update(
        MobileAppRelease $release,
        array $data,
        ?UploadedFile $apk,
        ?string $userId
    ): MobileAppRelease {
        $this->ensureDraft($release);

        $disk = $this->disk();
        $newPath = null;
        $oldFile = null;

        if ($apk !== null) {
            $newPath = $apk->storeAs(
                'mobile-app-releases',
                Str::uuid() . '.apk',
                $disk
            );

            if (! is_string($newPath)) {
                throw new RuntimeException('Unable to store the APK file.');
            }
        }

        try {
            $updatedRelease = DB::transaction(function () use (
                $release,
                $data,
                $apk,
                $userId,
                $disk,
                $newPath,
                &$oldFile
            ) {
                $lockedRelease = MobileAppRelease::query()
                    ->whereKey($release->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->ensureDraft($lockedRelease);

                $lockedRelease->fill([
                    'version_name' => $data['version_name']
                        ?? $lockedRelease->version_name,
                    'version_code' => $data['version_code']
                        ?? $lockedRelease->version_code,
                    'release_notes' => array_key_exists('release_notes', $data)
                        ? $data['release_notes']
                        : $lockedRelease->release_notes,
                    'is_mandatory' => $data['is_mandatory']
                        ?? $lockedRelease->is_mandatory,
                ]);

                $lockedRelease->save();

                if ($apk !== null && $newPath !== null) {
                    $oldFile = $lockedRelease->apkFile()->first();

                    if ($oldFile !== null) {
                        // Detach and soft-delete the old file record.
                        $oldFile->fileable_id = null;
                        $oldFile->fileable_type = null;
                        $oldFile->save();
                        $oldFile->delete();
                    }

                    $file = $this->createFileRecord(
                        $lockedRelease,
                        $apk,
                        $disk,
                        $newPath,
                        $userId
                    );

                    $lockedRelease->apkFile()->save($file);
                }

                return $lockedRelease->load(['creator', 'apkFile']);
            });

            // Delete the replaced physical file only after the DB transaction
            // succeeds. The old file record is retained as a soft-deleted row.
            if ($oldFile !== null) {
                Storage::disk($oldFile->disk)->delete($oldFile->path);
            }

            return $updatedRelease;
        } catch (Throwable $exception) {
            if ($newPath !== null) {
                Storage::disk($disk)->delete($newPath);
            }

            throw $exception;
        }
    }

    /**
     * Publish a draft release and make it the latest release.
     */
    public function publish(
        MobileAppRelease $release
    ): MobileAppRelease {
        return DB::transaction(function () use ($release) {
            $lockedRelease = MobileAppRelease::query()
                ->whereKey($release->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedRelease->status !== 'draft') {
                throw ValidationException::withMessages([
                    'status' => 'Only draft releases can be published.',
                ]);
            }

            $file = $lockedRelease->apkFile()->first();

            if (
                $file === null
                || $file->trashed()
                || $file->status !== 'READY'
                || strtolower((string) $file->extension) !== 'apk'
                || empty($file->path)
                || ! Storage::disk($file->disk)->exists($file->path)
            ) {
                throw ValidationException::withMessages([
                    'apk' => 'A valid, ready APK file is required before publishing.',
                ]);
            }

            // Clear the current latest flag before publishing this release.
            MobileAppRelease::query()
                ->where('is_latest', true)
                ->update(['is_latest' => false]);

            $lockedRelease->status = 'published';
            $lockedRelease->is_latest = true;
            $lockedRelease->published_at = now();
            $lockedRelease->save();

            return $lockedRelease->load(['creator', 'apkFile']);
        });
    }

    /**
     * Withdraw a release.
     */
    public function withdraw(
        MobileAppRelease $release
    ): MobileAppRelease {
        return DB::transaction(function () use ($release) {
            $lockedRelease = MobileAppRelease::query()
                ->whereKey($release->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedRelease->status === 'withdrawn') {
                return $lockedRelease->load(['creator', 'apkFile']);
            }

            $wasLatest = $lockedRelease->is_latest;

            $lockedRelease->status = 'withdrawn';
            $lockedRelease->is_latest = false;
            $lockedRelease->save();

            if ($wasLatest) {
                $replacement = MobileAppRelease::query()
                    ->where('status', 'published')
                    ->where('is_latest', false)
                    ->orderByDesc('version_code')
                    ->lockForUpdate()
                    ->first();

                if ($replacement !== null) {
                    $replacement->is_latest = true;
                    $replacement->save();
                }
            }

            return $lockedRelease->load(['creator', 'apkFile']);
        });
    }

    /**
     * Build the centralized File model record.
     */
    private function createFileRecord(
        MobileAppRelease $release,
        UploadedFile $apk,
        string $disk,
        string $path,
        ?string $userId
    ): File {
        $realPath = $apk->getRealPath();

        if (! is_string($realPath) || ! is_file($realPath)) {
            throw new RuntimeException('The uploaded APK is not accessible.');
        }

        $checksum = hash_file('sha256', $realPath);

        if (! is_string($checksum)) {
            throw new RuntimeException('Unable to calculate the APK checksum.');
        }

        return new File([
            'uuid' => (string) Str::uuid(),
            'collection' => 'mobile_app_release',
            'original_name' => mb_substr(
                $apk->getClientOriginalName(),
                0,
                255
            ),
            'stored_name' => basename($path),
            'path' => $path,
            'disk' => $disk,
            'mime_type' => $apk->getMimeType(),
            'extension' => 'apk',
            'size_bytes' => $apk->getSize() ?: 0,
            'checksum' => $checksum,
            'uploaded_by' => $userId,
            'source' => $userId !== null ? 'OFFICER' : 'SYSTEM',
            'visibility' => 'PRIVATE',
            'status' => 'READY',
            'uploaded_at' => now(),
        ]);
    }

    /**
     * Prevent modification of published or withdrawn releases.
     */
    private function ensureDraft(
        MobileAppRelease $release
    ): void {
        if ($release->status !== 'draft') {
            throw ValidationException::withMessages([
                'status' => 'Only draft releases can be edited.',
            ]);
        }
    }
}