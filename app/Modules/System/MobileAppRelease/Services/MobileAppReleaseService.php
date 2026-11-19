<?php

declare(strict_types=1);

namespace App\Modules\System\MobileAppRelease\Services;

use App\Models\File;
use App\Models\MobileAppRelease;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class MobileAppReleaseService
{
    /*
    |--------------------------------------------------------------------------
    | Configuration
    |--------------------------------------------------------------------------
    */

    private const STORAGE_DIRECTORY = 'mobile-app-releases';

    private const FILE_COLLECTION = 'mobile_app_release';

    private const APK_MIME_TYPE = 'application/vnd.android.package-archive';

    private const DEFAULT_MAX_APK_SIZE = 536870912;

    /**
     * Return the configured private storage disk.
     */
    private function disk(): string
    {
        $disk = config('filesystems.mobile_app_disk', 'local');

        if (! is_string($disk) || trim($disk) === '') {
            throw new RuntimeException(
                'The mobile application storage disk is not configured.'
            );
        }

        $diskConfig = config("filesystems.disks.{$disk}");

        if (! is_array($diskConfig)) {
            throw new RuntimeException(
                'The configured mobile application storage disk does not exist.'
            );
        }

        if (($diskConfig['visibility'] ?? 'private') === 'public') {
            throw new RuntimeException(
                'Mobile application APK storage must not use a public disk.'
            );
        }

        return $disk;
    }

    /**
     * Build consistent log context.
     */
    private function releaseContext(
        ?MobileAppRelease $release = null,
        array $extra = []
    ): array {
        return array_merge([
            'module' => 'mobile_app_releases',
            'release_id' => $release?->getKey(),
            'app_type' => $release?->app_type,
            'version_name' => $release?->version_name,
            'version_code' => $release?->version_code,
            'release_status' => $release?->status,
        ], $extra);
    }

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    */

    private function logFailure(
        string $operation,
        Throwable $exception,
        array $context = []
    ): void {
        Log::error(
            "Mobile app release: {$operation} failed.",
            array_merge($context, [
                'operation' => $operation,
                'exception_class' => get_class($exception),
                'exception_message' => $exception->getMessage(),
                'exception_file' => $exception->getFile(),
                'exception_line' => $exception->getLine(),
            ])
        );
    }

    private function durationMs(float $startedAt): float
    {
        return round((microtime(true) - $startedAt) * 1000, 2);
    }

    /*
    |--------------------------------------------------------------------------
    | APK Validation
    |--------------------------------------------------------------------------
    */

    /**
     * Validate an uploaded or assembled APK before permanent storage.
     *
     * This checks file integrity at the filesystem level, extension,
     * and configured size limit. It does not perform Android package
     * signature or package metadata verification.
     */
    private function validateUploadedApk(UploadedFile $apk): void
    {
        $realPath = $apk->getRealPath();

        if (
            ! is_string($realPath)
            || ! is_file($realPath)
            || ! is_readable($realPath)
        ) {
            throw ValidationException::withMessages([
                'apk' => 'The APK file is missing or unreadable.',
            ]);
        }

        if (! $apk->isValid()) {
            throw ValidationException::withMessages([
                'apk' => 'The APK upload is invalid.',
            ]);
        }

        if (
            strtolower($apk->getClientOriginalExtension()) !== 'apk'
        ) {
            throw ValidationException::withMessages([
                'apk' => 'Only APK files are supported.',
            ]);
        }

        clearstatcache(true, $realPath);

        $actualSize = filesize($realPath);

        if ($actualSize === false || $actualSize <= 0) {
            throw ValidationException::withMessages([
                'apk' => 'The APK file is empty or invalid.',
            ]);
        }

        $reportedSize = $apk->getSize();

        if (
            $reportedSize === false
            || $reportedSize <= 0
            || $reportedSize !== $actualSize
        ) {
            throw ValidationException::withMessages([
                'apk' => 'The APK file size could not be verified.',
            ]);
        }

        $maximumSize = (int) config(
            'mobile_apps.max_apk_size_bytes',
            self::DEFAULT_MAX_APK_SIZE
        );

        if ($maximumSize > 0 && $actualSize > $maximumSize) {
            throw ValidationException::withMessages([
                'apk' => 'The APK file exceeds the maximum permitted size.',
            ]);
        }
    }

    /**
     * Calculate the SHA-256 checksum of an uploaded APK.
     */
    private function calculateChecksum(UploadedFile $apk): string
    {
        $realPath = $apk->getRealPath();

        if (
            ! is_string($realPath)
            || ! is_file($realPath)
            || ! is_readable($realPath)
        ) {
            throw new RuntimeException(
                'Unable to access the APK file for checksum calculation.'
            );
        }

        $checksum = hash_file('sha256', $realPath);

        if (! is_string($checksum)) {
            throw new RuntimeException(
                'Unable to calculate the APK checksum.'
            );
        }

        return strtolower($checksum);
    }

    /**
     * Verify the permanently stored APK.
     */
    private function verifyStoredApk(
        string $disk,
        string $path,
        int $expectedSize
    ): void {
        try {
            $storage = Storage::disk($disk);

            if (! $storage->exists($path)) {
                throw new RuntimeException(
                    'The stored APK file could not be found.'
                );
            }

            $storedSize = $storage->size($path);

            if ($storedSize !== $expectedSize) {
                throw new RuntimeException(
                    'The stored APK size does not match the original APK size.'
                );
            }
        } catch (Throwable $exception) {
            Log::error(
                'Mobile app release: permanent APK verification failed.',
                [
                    'disk' => $disk,
                    'path' => $path,
                    'expected_size_bytes' => $expectedSize,
                    'exception_class' => get_class($exception),
                    'exception_message' => $exception->getMessage(),
                ]
            );

            throw new RuntimeException(
                'Unable to verify the permanently stored APK.',
                previous: $exception
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    */

    /**
     * Store an APK under a generated filename.
     */
    private function storeApk(
        UploadedFile $apk,
        string $disk
    ): string {
        $filename = Str::uuid() . '.apk';

        $path = $apk->storeAs(
            self::STORAGE_DIRECTORY,
            $filename,
            $disk
        );

        if (! is_string($path) || $path === '') {
            throw new RuntimeException(
                'Unable to store the APK file.'
            );
        }

        try {
            $this->verifyStoredApk(
                $disk,
                $path,
                (int) $apk->getSize()
            );
        } catch (Throwable $exception) {
            $this->deleteStoredApk(
                $disk,
                $path,
                'failed_storage_verification'
            );

            throw $exception;
        }

        return $path;
    }

    /**
     * Delete a stored APK safely.
     *
     * Returns true when the file is confirmed absent.
     */
    private function deleteStoredApk(
        string $disk,
        string $path,
        string $operation,
        array $context = []
    ): bool {
        try {
            $storage = Storage::disk($disk);

            if (! $storage->exists($path)) {
                return true;
            }

            if (! $storage->delete($path)) {
                Log::warning(
                    'Mobile app release: physical APK cleanup returned false.',
                    array_merge($context, [
                        'operation' => $operation,
                        'disk' => $disk,
                        'path' => $path,
                    ])
                );

                return false;
            }

            Log::info(
                'Mobile app release: physical APK cleanup completed.',
                array_merge($context, [
                    'operation' => $operation,
                    'disk' => $disk,
                    'path' => $path,
                ])
            );

            return ! $storage->exists($path);
        } catch (Throwable $exception) {
            $this->logFailure(
                $operation,
                $exception,
                array_merge($context, [
                    'disk' => $disk,
                    'path' => $path,
                ])
            );

            return false;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Application Locking
    |--------------------------------------------------------------------------
    */

    /**
     * Lock the application releases in a consistent order.
     *
     * This helps serialize operations when records already exist.
     * For an application with no releases, this query does not lock
     * a stable parent row. See the note following this implementation.
     */
    private function lockApplicationReleases(string $appType)
    {
        return MobileAppRelease::query()
            ->where('app_type', $appType)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /*
    |--------------------------------------------------------------------------
    | File Record
    |--------------------------------------------------------------------------
    */

    /**
     * Create the centralized File record for a release APK.
     */
    private function createFileRecord(
        MobileAppRelease $release,
        UploadedFile $apk,
        string $disk,
        string $path,
        ?string $userId,
        string $checksum
    ): File {
        $context = $this->releaseContext($release, [
            'operation' => 'create_file_record',
            'disk' => $disk,
            'stored_path' => $path,
            'original_filename' => $apk->getClientOriginalName(),
            'file_size_bytes' => $apk->getSize(),
            'uploaded_by' => $userId,
        ]);

        $originalName = mb_substr(
            $apk->getClientOriginalName(),
            0,
            255
        );

        $file = new File([
            'uuid' => (string) Str::uuid(),
            'collection' => self::FILE_COLLECTION,
            'original_name' => $originalName,
            'stored_name' => basename($path),
            'path' => $path,
            'disk' => $disk,
            'mime_type' => self::APK_MIME_TYPE,
            'extension' => 'apk',
            'size_bytes' => (int) $apk->getSize(),
            'checksum' => $checksum,
            'uploaded_by' => $userId,
            'source' => $userId !== null ? 'OFFICER' : 'SYSTEM',
            'visibility' => 'PRIVATE',
            'status' => 'READY',
            'uploaded_at' => now(),
        ]);

        Log::info(
            'Mobile app release: APK file metadata prepared.',
            array_merge($context, [
                'file_uuid' => $file->uuid,
                'stored_filename' => $file->stored_name,
                'mime_type' => $file->mime_type,
                'checksum_sha256' => $checksum,
            ])
        );

        return $file;
    }

    /*
    |--------------------------------------------------------------------------
    | Draft Validation
    |--------------------------------------------------------------------------
    */

    /**
     * Ensure that only draft releases can be edited.
     */
    private function ensureDraft(MobileAppRelease $release): void
    {
        if ($release->status !== 'draft') {
            throw ValidationException::withMessages([
                'status' => 'Only draft releases can be edited.',
            ]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Application Type Validation
    |--------------------------------------------------------------------------
    */

    private function validateAppType(mixed $appType): string
    {
        if (
            ! is_string($appType)
            || ! array_key_exists(
                $appType,
                config('mobile_apps.types', [])
            )
        ) {
            throw ValidationException::withMessages([
                'app_type' => 'The selected mobile application is not supported.',
            ]);
        }

        return $appType;
    }

    /*
    |--------------------------------------------------------------------------
    | Create Release
    |--------------------------------------------------------------------------
    */

    /**
     * Create a draft release and attach its APK.
     */
    public function create(
        array $data,
        UploadedFile $apk,
        ?string $userId
    ): MobileAppRelease {
        $startedAt = microtime(true);

        $appType = $this->validateAppType(
            $data['app_type'] ?? null
        );

        $this->validateUploadedApk($apk);

        $disk = $this->disk();

        $context = [
            'operation' => 'create',
            'module' => 'mobile_app_releases',
            'app_type' => $appType,
            'version_name' => $data['version_name'] ?? null,
            'version_code' => $data['version_code'] ?? null,
            'uploaded_by' => $userId,
            'disk' => $disk,
            'original_filename' => $apk->getClientOriginalName(),
            'file_size_bytes' => $apk->getSize(),
            'is_mandatory' => $data['is_mandatory'] ?? false,
        ];

        Log::info(
            'Mobile app release: creation started.',
            $context
        );

        $path = null;

        try {
            $checksum = $this->calculateChecksum($apk);

            /*
             * Store the physical file before the database transaction.
             * If the database operation fails, cleanup is attempted below.
             */
            $path = $this->storeApk($apk, $disk);

            $release = DB::transaction(function () use (
                $data,
                $apk,
                $userId,
                $disk,
                $path,
                $checksum,
                $context
            ): MobileAppRelease {
                $release = MobileAppRelease::create([
                    'app_type' => $data['app_type'],
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
                    $userId,
                    $checksum
                );

                $release->apkFile()->save($file);

                Log::info(
                    'Mobile app release: release and APK records created.',
                    $this->releaseContext($release, [
                        'file_id' => $file->getKey(),
                        'file_uuid' => $file->uuid,
                        'stored_path' => $path,
                    ])
                );

                return $release->load([
                    'creator',
                    'apkFile',
                ]);
            });

            Log::info(
                'Mobile app release: creation completed successfully.',
                $this->releaseContext($release, [
                    'file_id' => $release->apkFile?->getKey(),
                    'disk' => $disk,
                    'stored_path' => $path,
                    'checksum_sha256' => $checksum,
                    'duration_ms' => $this->durationMs($startedAt),
                ])
            );

            return $release;
        } catch (Throwable $exception) {
            $this->logFailure(
                'create',
                $exception,
                array_merge($context, [
                    'stored_path' => $path,
                ])
            );

            if (is_string($path)) {
                $this->deleteStoredApk(
                    $disk,
                    $path,
                    'create_cleanup',
                    $context
                );
            }

            throw $exception;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Update Release
    |--------------------------------------------------------------------------
    */

    /**
     * Update a draft release.
     *
     * If an APK is supplied, it replaces the existing attachment.
     * If null, only metadata is updated.
     */
    public function update(
        MobileAppRelease $release,
        array $data,
        ?UploadedFile $apk,
        ?string $userId
    ): MobileAppRelease {
        $startedAt = microtime(true);

        $this->ensureDraft($release);

        if ($apk !== null) {
            $this->validateUploadedApk($apk);
        }

        $disk = $this->disk();

        $context = $this->releaseContext($release, [
            'operation' => 'update',
            'updated_by' => $userId,
            'apk_replacement_requested' => $apk !== null,
            'submitted_fields' => array_keys($data),
        ]);

        Log::info(
            'Mobile app release: update started.',
            $context
        );

        $newPath = null;
        $oldFile = null;

        try {
            $checksum = null;

            if ($apk !== null) {
                $checksum = $this->calculateChecksum($apk);
                $newPath = $this->storeApk($apk, $disk);
            }

            $updatedRelease = DB::transaction(function () use (
                $release,
                $data,
                $apk,
                $userId,
                $disk,
                $newPath,
                $checksum,
                &$oldFile
            ): MobileAppRelease {
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

                    'release_notes' => array_key_exists(
                        'release_notes',
                        $data
                    )
                        ? $data['release_notes']
                        : $lockedRelease->release_notes,

                    'is_mandatory' => $data['is_mandatory']
                        ?? $lockedRelease->is_mandatory,
                ]);

                $lockedRelease->save();

                if ($apk !== null && $newPath !== null && $checksum !== null) {
                    $oldFile = $lockedRelease->apkFile()->first();

                    if ($oldFile !== null) {
                        /*
                         * Detach the old file record before soft deletion.
                         * The old physical file is deleted only after commit.
                         */
                        $oldFile->fileable_id = null;
                        $oldFile->fileable_type = null;
                        $oldFile->save();

                        $oldFile->delete();
                    }

                    $newFile = $this->createFileRecord(
                        $lockedRelease,
                        $apk,
                        $disk,
                        $newPath,
                        $userId,
                        $checksum
                    );

                    $lockedRelease->apkFile()->save($newFile);
                }

                return $lockedRelease->load([
                    'creator',
                    'apkFile',
                ]);
            });

            /*
             * Database transaction has committed. It is now safe to
             * remove the old physical APK.
             */
            if ($oldFile !== null) {
                $oldDisk = $oldFile->disk;
                $oldPath = $oldFile->path;

                if (
                    is_string($oldDisk)
                    && $oldDisk !== ''
                    && is_string($oldPath)
                    && $oldPath !== ''
                ) {
                    $this->deleteStoredApk(
                        $oldDisk,
                        $oldPath,
                        'update_old_apk_cleanup',
                        [
                            'release_id' => $updatedRelease->getKey(),
                            'old_file_id' => $oldFile->getKey(),
                        ]
                    );
                }
            }

            Log::info(
                'Mobile app release: update completed successfully.',
                $this->releaseContext($updatedRelease, [
                    'apk_replaced' => $apk !== null,
                    'duration_ms' => $this->durationMs($startedAt),
                ])
            );

            return $updatedRelease;
        } catch (Throwable $exception) {
            $this->logFailure(
                'update',
                $exception,
                $context
            );

            /*
             * If the database transaction failed, the new APK may be
             * orphaned. Attempt cleanup without masking the original error.
             */
            if (is_string($newPath)) {
                $this->deleteStoredApk(
                    $disk,
                    $newPath,
                    'update_new_apk_cleanup',
                    $context
                );
            }

            throw $exception;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Publish Release
    |--------------------------------------------------------------------------
    */

    /**
     * Publish a draft release and mark it latest for its application.
     */
    public function publish(
        MobileAppRelease $release
    ): MobileAppRelease {
        $startedAt = microtime(true);

        $appType = $release->app_type;

        $context = $this->releaseContext($release, [
            'operation' => 'publish',
        ]);

        Log::info(
            'Mobile app release: publishing started.',
            $context
        );

        try {
            $publishedRelease = DB::transaction(function () use (
                $release,
                $appType
            ): MobileAppRelease {
                $applicationReleases = $this->lockApplicationReleases(
                    $appType
                );

                $lockedRelease = $applicationReleases->firstWhere(
                    'id',
                    $release->getKey()
                );

                if ($lockedRelease === null) {
                    throw ValidationException::withMessages([
                        'release' => 'The requested release was not found for this application.',
                    ]);
                }

                if ($lockedRelease->status !== 'draft') {
                    throw ValidationException::withMessages([
                        'status' => 'Only draft releases can be published.',
                    ]);
                }

                $file = $lockedRelease->apkFile()->first();

                if (! $this->isValidApk($file)) {
                    throw ValidationException::withMessages([
                        'apk' => 'A valid, ready APK file is required before publishing.',
                    ]);
                }

                /*
                 * Clear previous latest flags only for this application.
                 */
                MobileAppRelease::query()
                    ->where('app_type', $appType)
                    ->where('is_latest', true)
                    ->update([
                        'is_latest' => false,
                    ]);

                $lockedRelease->status = 'published';
                $lockedRelease->is_latest = true;
                $lockedRelease->published_at = now();

                $lockedRelease->save();

                Log::info(
                    'Mobile app release: release published.',
                    $this->releaseContext($lockedRelease, [
                        'file_id' => $file->getKey(),
                        'published_at' => $lockedRelease->published_at?->toIso8601String(),
                    ])
                );

                return $lockedRelease->load([
                    'creator',
                    'apkFile',
                ]);
            }, 3);

            Log::info(
                'Mobile app release: publishing completed successfully.',
                $this->releaseContext($publishedRelease, [
                    'duration_ms' => $this->durationMs($startedAt),
                ])
            );

            return $publishedRelease;
        } catch (Throwable $exception) {
            $this->logFailure(
                'publish',
                $exception,
                $context
            );

            throw $exception;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Withdraw Release
    |--------------------------------------------------------------------------
    */

    /**
     * Withdraw a release.
     *
     * If it was latest, select the highest-version published release
     * with a valid APK for the same application.
     */
    public function withdraw(
        MobileAppRelease $release
    ): MobileAppRelease {
        $startedAt = microtime(true);

        $appType = $release->app_type;

        $context = $this->releaseContext($release, [
            'operation' => 'withdraw',
        ]);

        Log::info(
            'Mobile app release: withdrawal started.',
            $context
        );

        try {
            $withdrawnRelease = DB::transaction(function () use (
                $release,
                $appType
            ): MobileAppRelease {
                $applicationReleases = $this->lockApplicationReleases(
                    $appType
                );

                $lockedRelease = $applicationReleases->firstWhere(
                    'id',
                    $release->getKey()
                );

                if ($lockedRelease === null) {
                    throw ValidationException::withMessages([
                        'release' => 'The requested release was not found for this application.',
                    ]);
                }

                if ($lockedRelease->status === 'withdrawn') {
                    return $lockedRelease->load([
                        'creator',
                        'apkFile',
                    ]);
                }

                $wasLatest = (bool) $lockedRelease->is_latest;

                $lockedRelease->status = 'withdrawn';
                $lockedRelease->is_latest = false;

                $lockedRelease->save();

                if ($wasLatest) {
                    $replacement = null;

                    $candidates = MobileAppRelease::query()
                        ->with('apkFile')
                        ->where('app_type', $appType)
                        ->where('status', 'published')
                        ->where('is_latest', false)
                        ->orderByDesc('version_code')
                        ->orderByDesc('created_at')
                        ->get();

                    foreach ($candidates as $candidate) {
                        if ($this->isValidApk($candidate->apkFile)) {
                            $replacement = $candidate;

                            break;
                        }
                    }

                    if ($replacement !== null) {
                        $replacement->is_latest = true;
                        $replacement->save();

                        Log::info(
                            'Mobile app release: replacement latest release selected.',
                            $this->releaseContext($replacement, [
                                'previous_latest_release_id' => $lockedRelease->getKey(),
                            ])
                        );
                    } else {
                        Log::warning(
                            'Mobile app release: no valid published replacement was available.',
                            $this->releaseContext($lockedRelease)
                        );
                    }
                }

                return $lockedRelease->load([
                    'creator',
                    'apkFile',
                ]);
            }, 3);

            Log::info(
                'Mobile app release: withdrawal completed successfully.',
                $this->releaseContext($withdrawnRelease, [
                    'duration_ms' => $this->durationMs($startedAt),
                ])
            );

            return $withdrawnRelease;
        } catch (Throwable $exception) {
            $this->logFailure(
                'withdraw',
                $exception,
                $context
            );

            throw $exception;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | APK Record Validation
    |--------------------------------------------------------------------------
    */

    /**
     * Determine whether a File record is a valid, physically available APK.
     */
    private function isValidApk(?File $file): bool
    {
        if ($file === null) {
            return false;
        }

        if (
            $file->status !== 'READY'
            || strtolower((string) $file->extension) !== 'apk'
            || empty($file->path)
            || empty($file->disk)
            || (int) $file->size_bytes <= 0
        ) {
            return false;
        }

        try {
            $storage = Storage::disk($file->disk);

            if (! $storage->exists($file->path)) {
                return false;
            }

            $storedSize = $storage->size($file->path);

            return $storedSize > 0
                && (int) $storedSize === (int) $file->size_bytes;
        } catch (Throwable $exception) {
            Log::warning(
                'Mobile app release: APK storage verification failed.',
                [
                    'file_id' => $file->getKey(),
                    'file_uuid' => $file->uuid,
                    'disk' => $file->disk,
                    'path' => $file->path,
                    'exception_class' => get_class($exception),
                    'exception_message' => $exception->getMessage(),
                ]
            );

            return false;
        }
    }
}
