<?php

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
    /**
     * APK storage must use a private Laravel disk.
     */
    private function disk(): string
    {
        return config('filesystems.mobile_app_disk', 'local');
    }

    /**
     * Build consistent log context for release operations.
     */
    private function releaseContext(
        ?MobileAppRelease $release = null,
        array $extra = []
    ): array {
        return array_merge([
            'module' => 'mobile_app_releases',
            'release_id' => $release?->getKey(),
            'version_name' => $release?->version_name,
            'version_code' => $release?->version_code,
            'release_status' => $release?->status,
        ], $extra);
    }

    /**
     * Log an operation failure without exposing secrets.
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

    /**
     * Create a draft release and attach its APK.
     */
    public function create(
        array $data,
        UploadedFile $apk,
        ?string $userId
    ): MobileAppRelease {
        $startedAt = microtime(true);
        $disk = $this->disk();
        $path = null;

        $context = [
            'operation' => 'create',
            'module' => 'mobile_app_releases',
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

        try {
            /*
            |--------------------------------------------------------------------------
            | Store APK
            |--------------------------------------------------------------------------
            */

            Log::info(
                'Mobile app release: storing APK file.',
                $context
            );

            $path = $apk->storeAs(
                'mobile-app-releases',
                Str::uuid() . '.apk',
                $disk
            );

            if (! is_string($path)) {
                throw new RuntimeException(
                    'Unable to store the APK file.'
                );
            }

            Log::info(
                'Mobile app release: APK stored successfully.',
                array_merge($context, [
                    'stored_path' => $path,
                ])
            );

            /*
            |--------------------------------------------------------------------------
            | Create Release And File Record
            |--------------------------------------------------------------------------
            */

            $release = DB::transaction(function () use (
                $data,
                $apk,
                $userId,
                $disk,
                $path,
                $context
            ) {
                Log::info(
                    'Mobile app release: database transaction started.',
                    $context
                );

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

                Log::info(
                    'Mobile app release: release record created.',
                    $this->releaseContext($release, $context)
                );

                $file = $this->createFileRecord(
                    $release,
                    $apk,
                    $disk,
                    $path,
                    $userId
                );

                Log::info(
                    'Mobile app release: file record prepared.',
                    $this->releaseContext($release, [
                        'file_uuid' => $file->uuid,
                        'stored_filename' => $file->stored_name,
                        'disk' => $disk,
                        'stored_path' => $path,
                        'file_size_bytes' => $file->size_bytes,
                        'checksum_sha256' => $file->checksum,
                    ])
                );

                $release->apkFile()->save($file);

                Log::info(
                    'Mobile app release: APK attached to release.',
                    $this->releaseContext($release, [
                        'file_id' => $file->getKey(),
                        'file_uuid' => $file->uuid,
                        'file_status' => $file->status,
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
                    'duration_ms' => round(
                        (microtime(true) - $startedAt) * 1000,
                        2
                    ),
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

            /*
            |--------------------------------------------------------------------------
            | Clean Up APK If Creation Failed
            |--------------------------------------------------------------------------
            */

            if (is_string($path)) {
                try {
                    $deleted = Storage::disk($disk)->delete($path);

                    Log::info(
                        'Mobile app release: creation cleanup completed.',
                        array_merge($context, [
                            'stored_path' => $path,
                            'file_deleted' => $deleted,
                        ])
                    );
                } catch (Throwable $cleanupException) {
                    $this->logFailure(
                        'create_cleanup',
                        $cleanupException,
                        array_merge($context, [
                            'stored_path' => $path,
                        ])
                    );
                }
            }

            Log::warning(
                'Mobile app release: creation terminated with an error.',
                array_merge($context, [
                    'duration_ms' => round(
                        (microtime(true) - $startedAt) * 1000,
                        2
                    ),
                ])
            );

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
        $startedAt = microtime(true);

        $context = $this->releaseContext($release, [
            'operation' => 'update',
            'updated_by' => $userId,
            'apk_replacement_requested' => $apk !== null,
            'submitted_fields' => array_values(array_diff(
                array_keys($data),
                ['release_notes']
            )),
        ]);

        Log::info(
            'Mobile app release: update started.',
            $context
        );

        $disk = $this->disk();
        $newPath = null;
        $oldFile = null;

        try {
            /*
            |--------------------------------------------------------------------------
            | Validate Current Release
            |--------------------------------------------------------------------------
            */

            $this->ensureDraft($release);

            /*
            |--------------------------------------------------------------------------
            | Store Replacement APK
            |--------------------------------------------------------------------------
            */

            if ($apk !== null) {
                Log::info(
                    'Mobile app release: storing replacement APK.',
                    array_merge($context, [
                        'disk' => $disk,
                        'original_filename' => $apk->getClientOriginalName(),
                        'file_size_bytes' => $apk->getSize(),
                    ])
                );

                $newPath = $apk->storeAs(
                    'mobile-app-releases',
                    Str::uuid() . '.apk',
                    $disk
                );

                if (! is_string($newPath)) {
                    throw new RuntimeException(
                        'Unable to store the APK file.'
                    );
                }

                Log::info(
                    'Mobile app release: replacement APK stored.',
                    array_merge($context, [
                        'disk' => $disk,
                        'new_path' => $newPath,
                    ])
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Update Database Records
            |--------------------------------------------------------------------------
            */

            $updatedRelease = DB::transaction(function () use (
                $release,
                $data,
                $apk,
                $userId,
                $disk,
                $newPath,
                &$oldFile,
                $context
            ) {
                Log::info(
                    'Mobile app release: update transaction started.',
                    $context
                );

                $lockedRelease = MobileAppRelease::query()
                    ->whereKey($release->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->ensureDraft($lockedRelease);

                $previousValues = [
                    'version_name' => $lockedRelease->version_name,
                    'version_code' => $lockedRelease->version_code,
                    'release_notes' => $lockedRelease->release_notes,
                    'is_mandatory' => $lockedRelease->is_mandatory,
                ];

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

                Log::info(
                    'Mobile app release: release metadata updated.',
                    $this->releaseContext($lockedRelease, [
                        'previous_values' => $previousValues,
                        'updated_values' => [
                            'version_name' => $lockedRelease->version_name,
                            'version_code' => $lockedRelease->version_code,
                            'release_notes' => $lockedRelease->release_notes,
                            'is_mandatory' => $lockedRelease->is_mandatory,
                        ],
                    ])
                );

                /*
                |--------------------------------------------------------------------------
                | Replace APK Attachment If Requested
                |--------------------------------------------------------------------------
                */

                if ($apk !== null && $newPath !== null) {
                    $oldFile = $lockedRelease->apkFile()->first();

                    if ($oldFile !== null) {
                        Log::info(
                            'Mobile app release: detaching previous APK.',
                            $this->releaseContext($lockedRelease, [
                                'old_file_id' => $oldFile->getKey(),
                                'old_file_uuid' => $oldFile->uuid,
                                'old_disk' => $oldFile->disk,
                                'old_path' => $oldFile->path,
                            ])
                        );

                        // Detach and soft-delete the old file record.
                        $oldFile->fileable_id = null;
                        $oldFile->fileable_type = null;
                        $oldFile->save();
                        $oldFile->delete();

                        Log::info(
                            'Mobile app release: previous APK record soft-deleted.',
                            $this->releaseContext($lockedRelease, [
                                'old_file_id' => $oldFile->getKey(),
                                'old_file_uuid' => $oldFile->uuid,
                            ])
                        );
                    } else {
                        Log::warning(
                            'Mobile app release: no previous APK attachment found.',
                            $this->releaseContext($lockedRelease)
                        );
                    }

                    $file = $this->createFileRecord(
                        $lockedRelease,
                        $apk,
                        $disk,
                        $newPath,
                        $userId
                    );

                    $lockedRelease->apkFile()->save($file);

                    Log::info(
                        'Mobile app release: replacement APK attached.',
                        $this->releaseContext($lockedRelease, [
                            'new_file_id' => $file->getKey(),
                            'new_file_uuid' => $file->uuid,
                            'new_path' => $newPath,
                            'file_size_bytes' => $file->size_bytes,
                            'checksum_sha256' => $file->checksum,
                        ])
                    );
                }

                return $lockedRelease->load([
                    'creator',
                    'apkFile',
                ]);
            });

            /*
            |--------------------------------------------------------------------------
            | Delete Replaced Physical File After Commit
            |--------------------------------------------------------------------------
            */

            if ($oldFile !== null) {
                try {
                    $deleted = Storage::disk($oldFile->disk)
                        ->delete($oldFile->path);

                    if ($deleted) {
                        Log::info(
                            'Mobile app release: previous APK physically deleted.',
                            $this->releaseContext($updatedRelease, [
                                'old_file_id' => $oldFile->getKey(),
                                'old_file_uuid' => $oldFile->uuid,
                                'old_disk' => $oldFile->disk,
                                'old_path' => $oldFile->path,
                            ])
                        );
                    } else {
                        Log::warning(
                            'Mobile app release: previous APK deletion returned false.',
                            $this->releaseContext($updatedRelease, [
                                'old_file_id' => $oldFile->getKey(),
                                'old_disk' => $oldFile->disk,
                                'old_path' => $oldFile->path,
                            ])
                        );
                    }
                } catch (Throwable $cleanupException) {
                    /*
                     * The database transaction has already succeeded.
                     * Do not report the entire update as failed just because
                     * deleting the obsolete physical file failed.
                     */
                    $this->logFailure(
                        'update_old_apk_cleanup',
                        $cleanupException,
                        $this->releaseContext($updatedRelease, [
                            'old_file_id' => $oldFile->getKey(),
                            'old_disk' => $oldFile->disk,
                            'old_path' => $oldFile->path,
                        ])
                    );
                }
            }

            Log::info(
                'Mobile app release: update completed successfully.',
                $this->releaseContext($updatedRelease, [
                    'apk_replaced' => $apk !== null,
                    'duration_ms' => round(
                        (microtime(true) - $startedAt) * 1000,
                        2
                    ),
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
            |--------------------------------------------------------------------------
            | Clean Up New APK If Update Failed
            |--------------------------------------------------------------------------
            */

            if ($newPath !== null) {
                try {
                    $deleted = Storage::disk($disk)->delete($newPath);

                    Log::info(
                        'Mobile app release: replacement APK cleanup completed.',
                        array_merge($context, [
                            'new_path' => $newPath,
                            'file_deleted' => $deleted,
                        ])
                    );
                } catch (Throwable $cleanupException) {
                    $this->logFailure(
                        'update_new_apk_cleanup',
                        $cleanupException,
                        array_merge($context, [
                            'new_path' => $newPath,
                        ])
                    );
                }
            }

            Log::warning(
                'Mobile app release: update terminated with an error.',
                array_merge($context, [
                    'duration_ms' => round(
                        (microtime(true) - $startedAt) * 1000,
                        2
                    ),
                ])
            );

            throw $exception;
        }
    }

    /**
     * Publish a draft release and make it the latest release.
     */
    public function publish(
        MobileAppRelease $release
    ): MobileAppRelease {
        $startedAt = microtime(true);

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
                $context
            ) {
                Log::info(
                    'Mobile app release: publish transaction started.',
                    $context
                );

                $lockedRelease = MobileAppRelease::query()
                    ->whereKey($release->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedRelease->status !== 'draft') {
                    Log::warning(
                        'Mobile app release: publishing rejected because release is not a draft.',
                        $this->releaseContext($lockedRelease)
                    );

                    throw ValidationException::withMessages([
                        'status' => 'Only draft releases can be published.',
                    ]);
                }

                $file = $lockedRelease->apkFile()->first();

                $fileIsValid = $file !== null
                    && ! $file->trashed()
                    && $file->status === 'READY'
                    && strtolower((string) $file->extension) === 'apk'
                    && ! empty($file->path);

                $fileExists = $fileIsValid
                    && Storage::disk($file->disk)->exists($file->path);

                if (! $fileExists) {
                    Log::warning(
                        'Mobile app release: publishing rejected because APK is missing or invalid.',
                        $this->releaseContext($lockedRelease, [
                            'file_attached' => $file !== null,
                            'file_status' => $file?->status,
                            'file_extension' => $file?->extension,
                            'file_path_present' => ! empty($file?->path),
                            'file_exists' => $fileExists,
                            'disk' => $file?->disk,
                        ])
                    );

                    throw ValidationException::withMessages([
                        'apk' => 'A valid, ready APK file is required before publishing.',
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Clear Existing Latest Release
                |--------------------------------------------------------------------------
                */

                $previousLatestIds = MobileAppRelease::query()
                    ->where('is_latest', true)
                    ->pluck('id')
                    ->all();

                MobileAppRelease::query()
                    ->where('is_latest', true)
                    ->update(['is_latest' => false]);

                Log::info(
                    'Mobile app release: previous latest flags cleared.',
                    $this->releaseContext($lockedRelease, [
                        'previous_latest_ids' => $previousLatestIds,
                    ])
                );

                /*
                |--------------------------------------------------------------------------
                | Publish Release
                |--------------------------------------------------------------------------
                */

                $lockedRelease->status = 'published';
                $lockedRelease->is_latest = true;
                $lockedRelease->published_at = now();
                $lockedRelease->save();

                Log::info(
                    'Mobile app release: release marked as published.',
                    $this->releaseContext($lockedRelease, [
                        'published_at' => $lockedRelease->published_at?->toIso8601String(),
                        'is_latest' => $lockedRelease->is_latest,
                        'file_id' => $file->getKey(),
                        'file_uuid' => $file->uuid,
                    ])
                );

                return $lockedRelease->load([
                    'creator',
                    'apkFile',
                ]);
            });

            Log::info(
                'Mobile app release: publishing completed successfully.',
                $this->releaseContext($publishedRelease, [
                    'duration_ms' => round(
                        (microtime(true) - $startedAt) * 1000,
                        2
                    ),
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

    /**
     * Withdraw a release.
     */
    public function withdraw(
        MobileAppRelease $release
    ): MobileAppRelease {
        $startedAt = microtime(true);

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
                $context
            ) {
                Log::info(
                    'Mobile app release: withdrawal transaction started.',
                    $context
                );

                $lockedRelease = MobileAppRelease::query()
                    ->whereKey($release->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedRelease->status === 'withdrawn') {
                    Log::info(
                        'Mobile app release: release is already withdrawn.',
                        $this->releaseContext($lockedRelease)
                    );

                    return $lockedRelease->load([
                        'creator',
                        'apkFile',
                    ]);
                }

                $wasLatest = (bool) $lockedRelease->is_latest;

                Log::info(
                    'Mobile app release: marking release as withdrawn.',
                    $this->releaseContext($lockedRelease, [
                        'was_latest' => $wasLatest,
                    ])
                );

                $lockedRelease->status = 'withdrawn';
                $lockedRelease->is_latest = false;
                $lockedRelease->save();

                /*
                |--------------------------------------------------------------------------
                | Select Replacement Latest Release
                |--------------------------------------------------------------------------
                */

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

                        Log::info(
                            'Mobile app release: replacement latest release selected.',
                            $this->releaseContext($replacement, [
                                'previous_latest_release_id' => $lockedRelease->getKey(),
                                'replacement_latest_release_id' => $replacement->getKey(),
                            ])
                        );
                    } else {
                        Log::warning(
                            'Mobile app release: no published replacement was available.',
                            $this->releaseContext($lockedRelease)
                        );
                    }
                }

                return $lockedRelease->load([
                    'creator',
                    'apkFile',
                ]);
            });

            Log::info(
                'Mobile app release: withdrawal completed successfully.',
                $this->releaseContext($withdrawnRelease, [
                    'duration_ms' => round(
                        (microtime(true) - $startedAt) * 1000,
                        2
                    ),
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
        $context = $this->releaseContext($release, [
            'operation' => 'create_file_record',
            'disk' => $disk,
            'stored_path' => $path,
            'original_filename' => $apk->getClientOriginalName(),
            'file_size_bytes' => $apk->getSize(),
            'uploaded_by' => $userId,
        ]);

        Log::info(
            'Mobile app release: preparing APK file metadata.',
            $context
        );

        $realPath = $apk->getRealPath();

        if (! is_string($realPath) || ! is_file($realPath)) {
            Log::error(
                'Mobile app release: uploaded APK temporary file is inaccessible.',
                $context
            );

            throw new RuntimeException(
                'The uploaded APK is not accessible.'
            );
        }

        $checksum = hash_file('sha256', $realPath);

        if (! is_string($checksum)) {
            Log::error(
                'Mobile app release: APK checksum calculation failed.',
                $context
            );

            throw new RuntimeException(
                'Unable to calculate the APK checksum.'
            );
        }

        $file = new File([
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

        Log::info(
            'Mobile app release: APK file metadata prepared successfully.',
            array_merge($context, [
                'file_uuid' => $file->uuid,
                'stored_filename' => $file->stored_name,
                'mime_type' => $file->mime_type,
                'extension' => $file->extension,
                'checksum_sha256' => $checksum,
                'visibility' => $file->visibility,
                'file_status' => $file->status,
            ])
        );

        return $file;
    }

    /**
     * Prevent modification of published or withdrawn releases.
     */
    private function ensureDraft(
        MobileAppRelease $release
    ): void {
        if ($release->status !== 'draft') {
            Log::warning(
                'Mobile app release: draft-only operation rejected.',
                $this->releaseContext($release, [
                    'operation' => 'ensure_draft',
                    'reason' => 'Release status is not draft.',
                ])
            );

            throw ValidationException::withMessages([
                'status' => 'Only draft releases can be edited.',
            ]);
        }
    }
}