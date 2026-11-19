<?php

declare(strict_types=1);

namespace App\Modules\System\MobileAppRelease\Controllers;

use App\Http\Controllers\Controller;
use App\Models\MobileAppReleaseUpload;
use App\Services\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class MobileAppReleaseUploadController extends Controller
{
    private const UPLOAD_DIRECTORY = 'mobile-app-releases/uploads';

    private const DEFAULT_MAX_UPLOAD_SIZE = 536870912; // 512 MB

    private const DEFAULT_MAX_CHUNK_SIZE = 10485760; // 10 MB

    private const DEFAULT_CHUNK_SIZE = 5242880; // 5 MB

    private const DEFAULT_EXPIRATION_HOURS = 24;

    private const DEFAULT_MAX_CHUNKS = 2048;

    /*
    |--------------------------------------------------------------------------
    | Create Upload Session
    |--------------------------------------------------------------------------
    */

    /**
     * Create a resumable APK upload session.
     *
     * POST /api/v1/mobile-app-releases/uploads
     *
     * Required:
     * - app_type
     * - original_name
     * - total_size
     *
     * Optional:
     * - chunk_size
     * - expected_checksum
     */
    public function store(Request $request): JsonResponse
    {
        $startedAt = microtime(true);

        $user = $request->user();

        if ($user === null) {
            return ApiResponse::unauthorized(
                'Authentication is required to create an upload session.'
            );
        }

        $maxUploadSize = (int) config(
            'mobile_apps.upload.max_size',
            self::DEFAULT_MAX_UPLOAD_SIZE
        );

        $maxChunkSize = (int) config(
            'mobile_apps.upload.max_chunk_size',
            self::DEFAULT_MAX_CHUNK_SIZE
        );

        $defaultChunkSize = (int) config(
            'mobile_apps.upload.chunk_size',
            self::DEFAULT_CHUNK_SIZE
        );

        $maximumChunks = (int) config(
            'mobile_apps.upload.max_chunks',
            self::DEFAULT_MAX_CHUNKS
        );

        $expirationHours = (int) config(
            'mobile_apps.upload.expiration_hours',
            self::DEFAULT_EXPIRATION_HOURS
        );

        if (
            $maxUploadSize < 1
            || $maxChunkSize < 1
            || $defaultChunkSize < 1
            || $maximumChunks < 1
            || $expirationHours < 1
        ) {
            throw new RuntimeException(
                'The mobile application upload configuration is invalid.'
            );
        }

        $validated = $request->validate([
            'app_type' => [
                'required',
                'string',
                'max:50',
                Rule::in(array_keys(config('mobile_apps.types', []))),
            ],

            'original_name' => [
                'required',
                'string',
                'max:255',
                'regex:/\.apk$/i',
            ],

            'total_size' => [
                'required',
                'integer',
                'min:1',
                'max:' . $maxUploadSize,
            ],

            'chunk_size' => [
                'sometimes',
                'integer',
                'min:1',
                'max:' . $maxChunkSize,
            ],

            'expected_checksum' => [
                'sometimes',
                'nullable',
                'string',
                'regex:/^[a-fA-F0-9]{64}$/',
            ],
        ]);

        $totalSize = (int) $validated['total_size'];

        $chunkSize = (int) (
            $validated['chunk_size'] ?? $defaultChunkSize
        );

        if ($chunkSize < 1 || $chunkSize > $maxChunkSize) {
            throw ValidationException::withMessages([
                'chunk_size' => 'The requested chunk size is invalid.',
            ]);
        }

        $totalChunks = (int) ceil($totalSize / $chunkSize);

        if ($totalChunks < 1) {
            throw ValidationException::withMessages([
                'total_size' => 'The uploaded file size is invalid.',
            ]);
        }

        if ($totalChunks > $maximumChunks) {
            throw ValidationException::withMessages([
                'chunk_size' => 'The selected chunk size produces too many upload chunks.',
            ]);
        }

        $userId = (string) $user->getKey();

        $diskName = (string) config(
            'filesystems.mobile_app_disk',
            'local'
        );

        try {
            $this->assertLocalFilesystemDisk($diskName);

            $uuid = (string) Str::uuid();

            $upload = DB::transaction(function () use (
                $uuid,
                $userId,
                $validated,
                $totalSize,
                $chunkSize,
                $totalChunks,
                $diskName,
                $expirationHours
            ): MobileAppReleaseUpload {
                return MobileAppReleaseUpload::query()->create([
                    'uuid' => $uuid,
                    'user_id' => $userId,
                    'app_type' => $validated['app_type'],
                    'original_name' => $this->sanitizeOriginalFilename(
                        $validated['original_name']
                    ),
                    'total_size' => $totalSize,
                    'chunk_size' => $chunkSize,
                    'total_chunks' => $totalChunks,
                    'uploaded_chunks' => [],
                    'status' => MobileAppReleaseUpload::STATUS_UPLOADING,
                    'disk' => $diskName,
                    'assembled_path' => null,
                    'expected_checksum' => isset($validated['expected_checksum'])
                        ? strtolower($validated['expected_checksum'])
                        : null,
                    'checksum' => null,
                    'failure_reason' => null,
                    'expires_at' => now()->addHours($expirationHours),
                    'completed_at' => null,
                ]);
            });

            Log::info(
                'Mobile app release upload: upload session created.',
                [
                    'upload_id' => $upload->getKey(),
                    'upload_uuid' => $upload->uuid,
                    'user_id' => $userId,
                    'app_type' => $upload->app_type,
                    'total_size' => $upload->total_size,
                    'chunk_size' => $upload->chunk_size,
                    'total_chunks' => $upload->total_chunks,
                    'disk' => $upload->disk,
                    'duration_ms' => $this->durationMs($startedAt),
                ]
            );

            return ApiResponse::created(
                data: $this->uploadPayload($upload),
                message: 'APK upload session created successfully.'
            );
        } catch (Throwable $exception) {
            $this->logFailure('store', $exception, [
                'user_id' => $userId,
                'app_type' => $validated['app_type'],
                'duration_ms' => $this->durationMs($startedAt),
            ]);

            throw $exception;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Upload Individual Chunk
    |--------------------------------------------------------------------------
    */

    /**
     * Upload an individual chunk.
     *
     * PUT /api/v1/mobile-app-releases/uploads/{upload}/chunks/{chunkIndex}
     *
     * Multipart field: chunk
     *
     * Chunk indexes are zero-based.
     */
    public function uploadChunk(
        Request $request,
        MobileAppReleaseUpload $upload,
        int $chunkIndex
    ): JsonResponse {
        $startedAt = microtime(true);

        $this->ensureUploadOwnership($request, $upload);

        $upload->refresh();

        $this->ensureUploadCanAcceptChunks($upload);

        if (
            $chunkIndex < 0
            || $chunkIndex >= (int) $upload->total_chunks
        ) {
            throw ValidationException::withMessages([
                'chunkIndex' => 'The requested chunk index is invalid.',
            ]);
        }

        $maxChunkSize = (int) config(
            'mobile_apps.upload.max_chunk_size',
            self::DEFAULT_MAX_CHUNK_SIZE
        );

        $request->validate([
            'chunk' => [
                'required',
                'file',
                'max:' . (int) ceil($maxChunkSize / 1024),
            ],
        ]);

        /** @var UploadedFile|null $chunk */
        $chunk = $request->file('chunk');

        if ($chunk === null || ! $chunk->isValid()) {
            throw ValidationException::withMessages([
                'chunk' => 'The uploaded chunk is invalid.',
            ]);
        }

        $expectedChunkSize = $this->expectedChunkSize(
            $upload,
            $chunkIndex
        );

        $actualChunkSize = $chunk->getSize();

        if (
            $actualChunkSize === false
            || (int) $actualChunkSize !== $expectedChunkSize
        ) {
            throw ValidationException::withMessages([
                'chunk' => sprintf(
                    'Invalid chunk size. Expected %d bytes.',
                    $expectedChunkSize
                ),
            ]);
        }

        $diskName = $upload->disk;

        $chunkPath = $this->chunkPath(
            $upload->uuid,
            $chunkIndex
        );

        try {
            $this->assertLocalFilesystemDisk($diskName);

            $result = DB::transaction(function () use (
                $request,
                $upload,
                $chunk,
                $chunkIndex,
                $chunkPath,
                $diskName,
                $expectedChunkSize
            ): MobileAppReleaseUpload {
                $lockedUpload = MobileAppReleaseUpload::query()
                    ->whereKey($upload->getKey())
                    ->lockForUpdate()
                    ->first();

                if ($lockedUpload === null) {
                    abort(404, 'Upload session not found.');
                }

                $this->ensureUploadOwnership(
                    $request,
                    $lockedUpload
                );

                $this->ensureUploadCanAcceptChunks(
                    $lockedUpload
                );

                if (
                    $chunkIndex < 0
                    || $chunkIndex >= (int) $lockedUpload->total_chunks
                ) {
                    throw ValidationException::withMessages([
                        'chunkIndex' => 'The requested chunk index is invalid.',
                    ]);
                }

                $currentExpectedSize = $this->expectedChunkSize(
                    $lockedUpload,
                    $chunkIndex
                );

                if ($currentExpectedSize !== $expectedChunkSize) {
                    throw ValidationException::withMessages([
                        'chunk' => 'The upload session changed. Please retry the upload.',
                    ]);
                }

                $uploadedChunks = $this->uploadedChunkIndexes(
                    $lockedUpload
                );

                $disk = Storage::disk($diskName);

                /*
                 * Idempotent retry:
                 * If the chunk is already registered and exists,
                 * do not overwrite it.
                 */
                if (
                    in_array($chunkIndex, $uploadedChunks, true)
                    && $disk->exists($chunkPath)
                ) {
                    $existingSize = $disk->size($chunkPath);

                    if ($existingSize !== $expectedChunkSize) {
                        throw new RuntimeException(
                            'The previously uploaded chunk has an invalid size.'
                        );
                    }

                    return $lockedUpload;
                }

                $directory = dirname($chunkPath);

                if (! $disk->exists($directory)) {
                    $disk->makeDirectory($directory);
                }

                $storedPath = $disk->putFileAs(
                    $directory,
                    $chunk,
                    basename($chunkPath)
                );

                if ($storedPath === false) {
                    throw new RuntimeException(
                        'The uploaded chunk could not be stored.'
                    );
                }

                if (! $disk->exists($chunkPath)) {
                    throw new RuntimeException(
                        'The uploaded chunk could not be verified after storage.'
                    );
                }

                $storedSize = $disk->size($chunkPath);

                if ($storedSize !== $expectedChunkSize) {
                    $disk->delete($chunkPath);

                    throw new RuntimeException(
                        'The stored chunk size does not match the expected size.'
                    );
                }

                if (! in_array($chunkIndex, $uploadedChunks, true)) {
                    $uploadedChunks[] = $chunkIndex;
                }

                sort($uploadedChunks, SORT_NUMERIC);

                $lockedUpload->uploaded_chunks = array_values(
                    array_unique($uploadedChunks)
                );

                $lockedUpload->save();

                return $lockedUpload->refresh();
            });

            Log::info(
                'Mobile app release upload: chunk uploaded.',
                [
                    'upload_id' => $result->getKey(),
                    'upload_uuid' => $result->uuid,
                    'user_id' => $request->user()?->getKey(),
                    'chunk_index' => $chunkIndex,
                    'uploaded_chunks' => count(
                        $this->uploadedChunkIndexes($result)
                    ),
                    'total_chunks' => $result->total_chunks,
                    'duration_ms' => $this->durationMs($startedAt),
                ]
            );

            return ApiResponse::success(
                data: $this->uploadPayload($result),
                message: 'APK chunk uploaded successfully.'
            );
        } catch (Throwable $exception) {
            if (! $exception instanceof ValidationException) {
                $this->logFailure('uploadChunk', $exception, [
                    'upload_uuid' => $upload->uuid,
                    'chunk_index' => $chunkIndex,
                    'user_id' => $request->user()?->getKey(),
                    'duration_ms' => $this->durationMs($startedAt),
                ]);
            }

            throw $exception;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Show Upload Progress
    |--------------------------------------------------------------------------
    */

    /**
     * Retrieve upload session status and progress.
     *
     * GET /api/v1/mobile-app-releases/uploads/{upload}
     */
    public function show(
        Request $request,
        MobileAppReleaseUpload $upload
    ): JsonResponse {
        $startedAt = microtime(true);

        $this->ensureUploadOwnership($request, $upload);

        /*
         * Expire only if the row is still UPLOADING.
         *
         * The conditional update prevents a progress request from
         * overwriting a concurrent state transition.
         */
        if (
            $upload->status === MobileAppReleaseUpload::STATUS_UPLOADING
            && $upload->isExpired()
        ) {
            MobileAppReleaseUpload::query()
                ->whereKey($upload->getKey())
                ->where(
                    'status',
                    MobileAppReleaseUpload::STATUS_UPLOADING
                )
                ->where('expires_at', '<=', now())
                ->update([
                    'status' => MobileAppReleaseUpload::STATUS_EXPIRED,
                    'failure_reason' => 'The upload session has expired.',
                    'updated_at' => now(),
                ]);
        }

        $upload->refresh();

        Log::info(
            'Mobile app release upload: upload progress retrieved.',
            [
                'upload_id' => $upload->getKey(),
                'upload_uuid' => $upload->uuid,
                'user_id' => $request->user()?->getKey(),
                'status' => $upload->status,
                'uploaded_chunks' => count(
                    $this->uploadedChunkIndexes($upload)
                ),
                'total_chunks' => $upload->total_chunks,
                'duration_ms' => $this->durationMs($startedAt),
            ]
        );

        return ApiResponse::success(
            data: $this->uploadPayload($upload),
            message: 'APK upload progress retrieved successfully.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Complete Upload
    |--------------------------------------------------------------------------
    */

    /**
     * Assemble uploaded chunks into the final APK.
     *
     * POST /api/v1/mobile-app-releases/uploads/{upload}/complete
     *
     * Verifies:
     * - All chunk indexes are present.
     * - Every chunk has the expected byte size.
     * - The assembled file has the expected total size.
     * - The final SHA-256 checksum matches the expected checksum,
     *   when one was provided.
     */
    public function complete(
        Request $request,
        MobileAppReleaseUpload $upload
    ): JsonResponse {
        $startedAt = microtime(true);

        $this->ensureUploadOwnership($request, $upload);

        $userId = (string) $request->user()->getKey();

        /*
         * Reserve the upload before doing filesystem work.
         *
         * This transaction intentionally runs outside the assembly
         * try/catch. A concurrent request that sees ASSEMBLING must
         * not mark the first request's assembly as FAILED.
         */
        $upload = DB::transaction(function () use (
            $upload,
            $request
        ): MobileAppReleaseUpload {
            $lockedUpload = MobileAppReleaseUpload::query()
                ->whereKey($upload->getKey())
                ->lockForUpdate()
                ->first();

            if ($lockedUpload === null) {
                abort(404, 'Upload session not found.');
            }

            $this->ensureUploadOwnership(
                $request,
                $lockedUpload
            );

            if (
                $lockedUpload->status
                === MobileAppReleaseUpload::STATUS_COMPLETED
            ) {
                return $lockedUpload;
            }

            if (
                $lockedUpload->status
                === MobileAppReleaseUpload::STATUS_ASSEMBLING
            ) {
                abort(
                    409,
                    'This upload is already being assembled. Please check the upload progress.'
                );
            }

            if (
                $lockedUpload->status
                !== MobileAppReleaseUpload::STATUS_UPLOADING
            ) {
                abort(
                    409,
                    'This upload session cannot be completed in its current state.'
                );
            }

            /*
             * Persist expiration before returning from the transaction.
             * Do not throw inside this transaction because that would
             * roll back the EXPIRED status.
             */
            if ($lockedUpload->isExpired()) {
                $lockedUpload->status = MobileAppReleaseUpload::STATUS_EXPIRED;
                $lockedUpload->failure_reason = 'The upload session has expired.';
                $lockedUpload->save();

                return $lockedUpload->refresh();
            }

            if (! $lockedUpload->hasAllChunksUploaded()) {
                throw ValidationException::withMessages([
                    'upload' => sprintf(
                        '%d upload chunks are still missing.',
                        $lockedUpload->getRemainingChunksCount()
                    ),
                ]);
            }

            $uploadedChunks = $this->uploadedChunkIndexes(
                $lockedUpload
            );

            $expectedIndexes = range(
                0,
                (int) $lockedUpload->total_chunks - 1
            );

            if ($uploadedChunks !== $expectedIndexes) {
                throw ValidationException::withMessages([
                    'upload' => 'The uploaded chunks are incomplete or invalid.',
                ]);
            }

            $lockedUpload->status = MobileAppReleaseUpload::STATUS_ASSEMBLING;
            $lockedUpload->failure_reason = null;
            $lockedUpload->save();

            return $lockedUpload->refresh();
        });

        /*
         * Return an existing completed result idempotently.
         */
        if (
            $upload->status === MobileAppReleaseUpload::STATUS_COMPLETED
        ) {
            return ApiResponse::success(
                data: $this->uploadPayload($upload),
                message: 'APK upload has already been completed.'
            );
        }

        /*
         * The expiration update above has already committed.
         */
        if (
            $upload->status === MobileAppReleaseUpload::STATUS_EXPIRED
        ) {
            throw ValidationException::withMessages([
                'upload' => 'This upload session has expired.',
            ]);
        }

        try {
            $this->assertLocalFilesystemDisk($upload->disk);

            $disk = Storage::disk($upload->disk);

            $assembledDirectory = self::UPLOAD_DIRECTORY
                . '/'
                . $upload->uuid
                . '/assembled';

            $temporaryPath = $assembledDirectory
                . '/'
                . Str::uuid()
                . '.assembling';

            $assembledPath = $assembledDirectory
                . '/'
                . $upload->uuid
                . '.apk';

            $disk->makeDirectory($assembledDirectory);

            $temporaryAbsolutePath = $disk->path($temporaryPath);

            $output = fopen($temporaryAbsolutePath, 'wb');

            if ($output === false) {
                throw new RuntimeException(
                    'Unable to create the assembled APK file.'
                );
            }

            $totalBytesWritten = 0;

            try {
                for (
                    $chunkIndex = 0;
                    $chunkIndex < (int) $upload->total_chunks;
                    $chunkIndex++
                ) {
                    $chunkPath = $this->chunkPath(
                        $upload->uuid,
                        $chunkIndex
                    );

                    if (! $disk->exists($chunkPath)) {
                        throw new RuntimeException(
                            "Upload chunk {$chunkIndex} is missing."
                        );
                    }

                    $chunkAbsolutePath = $disk->path($chunkPath);

                    if (
                        ! is_file($chunkAbsolutePath)
                        || ! is_readable($chunkAbsolutePath)
                    ) {
                        throw new RuntimeException(
                            "Upload chunk {$chunkIndex} cannot be read."
                        );
                    }

                    clearstatcache(true, $chunkAbsolutePath);

                    $expectedSize = $this->expectedChunkSize(
                        $upload,
                        $chunkIndex
                    );

                    $actualSize = filesize($chunkAbsolutePath);

                    if (
                        $actualSize === false
                        || (int) $actualSize !== $expectedSize
                    ) {
                        throw new RuntimeException(
                            "Upload chunk {$chunkIndex} has an invalid size."
                        );
                    }

                    $input = fopen($chunkAbsolutePath, 'rb');

                    if ($input === false) {
                        throw new RuntimeException(
                            "Unable to open upload chunk {$chunkIndex}."
                        );
                    }

                    try {
                        $copied = stream_copy_to_stream(
                            $input,
                            $output
                        );

                        if (
                            $copied === false
                            || $copied !== $expectedSize
                        ) {
                            throw new RuntimeException(
                                "Failed to assemble upload chunk {$chunkIndex}."
                            );
                        }

                        $totalBytesWritten += $copied;
                    } finally {
                        fclose($input);
                    }
                }

                if (! fflush($output)) {
                    throw new RuntimeException(
                        'Unable to flush the assembled APK to disk.'
                    );
                }
            } finally {
                fclose($output);
            }

            if ($totalBytesWritten !== (int) $upload->total_size) {
                throw new RuntimeException(
                    'The assembled APK size does not match the upload session.'
                );
            }

            clearstatcache(true, $temporaryAbsolutePath);

            $actualFileSize = filesize($temporaryAbsolutePath);

            if (
                $actualFileSize === false
                || (int) $actualFileSize !== (int) $upload->total_size
            ) {
                throw new RuntimeException(
                    'The assembled APK failed file size verification.'
                );
            }

            /*
             * Calculate the checksum from the actual assembled file.
             * No additional chunk streams are necessary.
             */
            $checksum = hash_file(
                'sha256',
                $temporaryAbsolutePath
            );

            if ($checksum === false) {
                throw new RuntimeException(
                    'Unable to calculate the assembled APK checksum.'
                );
            }

            if (
                ! empty($upload->expected_checksum)
                && ! hash_equals(
                    strtolower($upload->expected_checksum),
                    strtolower($checksum)
                )
            ) {
                throw new RuntimeException(
                    'The assembled APK checksum does not match the expected checksum.'
                );
            }

            /*
             * Replace any stale final artifact only after the new
             * temporary artifact has passed verification.
             */
            if ($disk->exists($assembledPath)) {
                if (! $disk->delete($assembledPath)) {
                    throw new RuntimeException(
                        'Unable to replace the existing assembled APK.'
                    );
                }
            }

            if (! $disk->move($temporaryPath, $assembledPath)) {
                throw new RuntimeException(
                    'Unable to finalize the assembled APK file.'
                );
            }

            if (! $disk->exists($assembledPath)) {
                throw new RuntimeException(
                    'The finalized APK could not be verified.'
                );
            }

            $finalAbsolutePath = $disk->path($assembledPath);

            clearstatcache(true, $finalAbsolutePath);

            $finalSize = filesize($finalAbsolutePath);

            if (
                $finalSize === false
                || (int) $finalSize !== (int) $upload->total_size
            ) {
                throw new RuntimeException(
                    'The finalized APK has an invalid size.'
                );
            }

            /*
             * Recalculate the checksum after moving the file.
             * This verifies the final artifact at its permanent path.
             */
            $finalChecksum = hash_file(
                'sha256',
                $finalAbsolutePath
            );

            if (
                $finalChecksum === false
                || ! hash_equals($checksum, $finalChecksum)
            ) {
                throw new RuntimeException(
                    'The finalized APK checksum verification failed.'
                );
            }

            /*
             * Commit the completed state only after the final file
             * has passed all filesystem and checksum verification.
             */
            $completedUpload = DB::transaction(function () use (
                $upload,
                $assembledPath,
                $finalChecksum
            ): MobileAppReleaseUpload {
                $lockedUpload = MobileAppReleaseUpload::query()
                    ->whereKey($upload->getKey())
                    ->lockForUpdate()
                    ->first();

                if ($lockedUpload === null) {
                    throw new RuntimeException(
                        'The upload session disappeared during assembly.'
                    );
                }

                if (
                    $lockedUpload->status
                    !== MobileAppReleaseUpload::STATUS_ASSEMBLING
                ) {
                    throw new RuntimeException(
                        'The upload session state changed during assembly.'
                    );
                }

                $lockedUpload->assembled_path = $assembledPath;
                $lockedUpload->checksum = $finalChecksum;
                $lockedUpload->status = MobileAppReleaseUpload::STATUS_COMPLETED;
                $lockedUpload->completed_at = now();
                $lockedUpload->failure_reason = null;
                $lockedUpload->save();

                return $lockedUpload->refresh();
            });

            /*
             * The assembled APK is now safely stored.
             * Chunk cleanup failure must not invalidate completion.
             */
            $this->cleanupChunkFiles($completedUpload);

            Log::info(
                'Mobile app release upload: upload completed successfully.',
                [
                    'upload_id' => $completedUpload->getKey(),
                    'upload_uuid' => $completedUpload->uuid,
                    'user_id' => $userId,
                    'app_type' => $completedUpload->app_type,
                    'total_size' => $completedUpload->total_size,
                    'total_chunks' => $completedUpload->total_chunks,
                    'checksum' => $completedUpload->checksum,
                    'assembled_path' => $completedUpload->assembled_path,
                    'duration_ms' => $this->durationMs($startedAt),
                ]
            );

            return ApiResponse::success(
                data: $this->uploadPayload($completedUpload),
                message: 'APK upload completed successfully.'
            );
        } catch (Throwable $exception) {
            /*
             * Only transition an upload that is still ASSEMBLING.
             * This prevents overwriting a state changed by another process.
             */
            try {
                MobileAppReleaseUpload::query()
                    ->whereKey($upload->getKey())
                    ->where(
                        'status',
                        MobileAppReleaseUpload::STATUS_ASSEMBLING
                    )
                    ->update([
                        'status' => MobileAppReleaseUpload::STATUS_FAILED,
                        'failure_reason' => mb_substr(
                            $exception->getMessage(),
                            0,
                            1000
                        ),
                        'updated_at' => now(),
                    ]);
            } catch (Throwable $statusException) {
                Log::error(
                    'Mobile app release upload: failed to update upload status after assembly failure.',
                    [
                        'upload_uuid' => $upload->uuid,
                        'exception_class' => get_class($statusException),
                        'exception_message' => $statusException->getMessage(),
                    ]
                );
            }

            /*
             * Remove temporary and partial assembled artifacts.
             * Original chunks are retained for diagnosis and cleanup.
             */
            try {
                $disk = Storage::disk($upload->disk);

                $directory = self::UPLOAD_DIRECTORY
                    . '/'
                    . $upload->uuid
                    . '/assembled';

                if ($disk->exists($directory)) {
                    $disk->deleteDirectory($directory);
                }
            } catch (Throwable $cleanupException) {
                Log::warning(
                    'Mobile app release upload: partial assembly cleanup failed.',
                    [
                        'upload_uuid' => $upload->uuid,
                        'exception_class' => get_class($cleanupException),
                        'exception_message' => $cleanupException->getMessage(),
                    ]
                );
            }

            $this->logFailure('complete', $exception, [
                'upload_uuid' => $upload->uuid,
                'user_id' => $userId,
                'duration_ms' => $this->durationMs($startedAt),
            ]);

            throw $exception;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Upload Ownership and State
    |--------------------------------------------------------------------------
    */

    /**
     * Ensure the upload belongs to the authenticated user.
     *
     * Return 404 rather than 403 to avoid disclosing another user's
     * upload session.
     */
    private function ensureUploadOwnership(
        Request $request,
        MobileAppReleaseUpload $upload
    ): void {
        $user = $request->user();

        if (
            $user === null
            || (string) $upload->user_id !== (string) $user->getKey()
        ) {
            abort(404, 'Upload session not found.');
        }
    }

    /**
     * Ensure the upload is still accepting chunks.
     */
    private function ensureUploadCanAcceptChunks(
        MobileAppReleaseUpload $upload
    ): void {
        if ($upload->isExpired()) {
            if (
                $upload->status
                === MobileAppReleaseUpload::STATUS_UPLOADING
            ) {
                MobileAppReleaseUpload::query()
                    ->whereKey($upload->getKey())
                    ->where(
                        'status',
                        MobileAppReleaseUpload::STATUS_UPLOADING
                    )
                    ->update([
                        'status' => MobileAppReleaseUpload::STATUS_EXPIRED,
                        'failure_reason' => 'The upload session has expired.',
                        'updated_at' => now(),
                    ]);
            }

            throw ValidationException::withMessages([
                'upload' => 'This upload session has expired.',
            ]);
        }

        if (! $upload->canAcceptChunks()) {
            throw ValidationException::withMessages([
                'upload' => 'This upload session is not accepting chunks.',
            ]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Filesystem Validation
    |--------------------------------------------------------------------------
    */

    /**
     * Ensure the configured disk uses the local filesystem driver.
     *
     * This upload implementation requires real filesystem paths
     * for stream-based APK assembly.
     */
    private function assertLocalFilesystemDisk(
        string $diskName
    ): void {
        $driver = config(
            "filesystems.disks.{$diskName}.driver"
        );

        if ($driver !== 'local') {
            throw new RuntimeException(
                'The mobile application upload disk must use the local filesystem driver.'
            );
        }

        $disk = Storage::disk($diskName);

        if (! method_exists($disk, 'path')) {
            throw new RuntimeException(
                'The configured upload disk does not support local filesystem paths.'
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Chunk Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Calculate the expected byte size of a chunk.
     *
     * All chunks have chunk_size bytes except the final chunk,
     * which may be smaller.
     */
    private function expectedChunkSize(
        MobileAppReleaseUpload $upload,
        int $chunkIndex
    ): int {
        $remainingBytes = (int) $upload->total_size
            - ($chunkIndex * (int) $upload->chunk_size);

        return min(
            (int) $upload->chunk_size,
            max(0, $remainingBytes)
        );
    }

    /**
     * Return the relative storage path for a chunk.
     */
    private function chunkPath(
        string $uploadUuid,
        int $chunkIndex
    ): string {
        return self::UPLOAD_DIRECTORY
            . '/'
            . $uploadUuid
            . '/chunks/'
            . $chunkIndex
            . '.part';
    }

    /**
     * Normalize uploaded chunk indexes.
     *
     * @return array<int, int>
     */
    private function uploadedChunkIndexes(
        MobileAppReleaseUpload $upload
    ): array {
        $chunks = $upload->uploaded_chunks ?? [];

        if (! is_array($chunks)) {
            return [];
        }

        $indexes = [];

        foreach ($chunks as $chunkIndex) {
            if (
                filter_var(
                    $chunkIndex,
                    FILTER_VALIDATE_INT
                ) !== false
                && (int) $chunkIndex >= 0
            ) {
                $indexes[] = (int) $chunkIndex;
            }
        }

        $indexes = array_values(array_unique($indexes));

        sort($indexes, SORT_NUMERIC);

        return $indexes;
    }

    /**
     * Remove individual chunk files after successful assembly.
     *
     * The assembled APK is retained.
     */
    private function cleanupChunkFiles(
        MobileAppReleaseUpload $upload
    ): void {
        try {
            $disk = Storage::disk($upload->disk);

            $chunkDirectory = self::UPLOAD_DIRECTORY
                . '/'
                . $upload->uuid
                . '/chunks';

            if (
                $disk->exists($chunkDirectory)
                && ! $disk->deleteDirectory($chunkDirectory)
            ) {
                Log::warning(
                    'Mobile app release upload: chunk directory cleanup failed.',
                    [
                        'upload_uuid' => $upload->uuid,
                        'directory' => $chunkDirectory,
                    ]
                );
            }
        } catch (Throwable $exception) {
            Log::warning(
                'Mobile app release upload: chunk cleanup encountered an exception.',
                [
                    'upload_uuid' => $upload->uuid,
                    'exception_class' => get_class($exception),
                    'exception_message' => $exception->getMessage(),
                ]
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Response Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Build a consistent upload-session response.
     */
    private function uploadPayload(
        MobileAppReleaseUpload $upload
    ): array {
        $uploadedChunks = $this->uploadedChunkIndexes($upload);

        $totalChunks = (int) $upload->total_chunks;

        $uploadedCount = count($uploadedChunks);

        $progress = $totalChunks > 0
            ? round(($uploadedCount / $totalChunks) * 100, 2)
            : 0;

        return [
            'uuid' => $upload->uuid,
            'app_type' => $upload->app_type,
            'original_name' => $upload->original_name,

            'total_size' => (int) $upload->total_size,
            'chunk_size' => (int) $upload->chunk_size,

            'total_chunks' => $totalChunks,
            'uploaded_chunks' => $uploadedChunks,
            'uploaded_chunks_count' => $uploadedCount,
            'remaining_chunks_count' => max(
                0,
                $totalChunks - $uploadedCount
            ),

            'progress' => $progress,

            'status' => $upload->status,

            'expected_checksum' => $upload->expected_checksum,
            'checksum' => $upload->checksum,

            'expires_at' => $upload->expires_at?->toIso8601String(),
            'completed_at' => $upload->completed_at?->toIso8601String(),

            'failure_reason' => $upload->failure_reason,
        ];
    }

    /**
     * Sanitize the original filename.
     */
    private function sanitizeOriginalFilename(
        string $filename
    ): string {
        $filename = str_replace('\\', '/', $filename);

        $filename = basename($filename);

        $filename = preg_replace(
            '/[\x00-\x1F\x7F]/u',
            '',
            $filename
        ) ?? '';

        $filename = trim($filename);

        if ($filename === '') {
            return 'application.apk';
        }

        return mb_substr($filename, 0, 255);
    }

    /*
    |--------------------------------------------------------------------------
    | Logging Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Log unexpected upload failures.
     */
    private function logFailure(
        string $operation,
        Throwable $exception,
        array $context = []
    ): void {
        Log::error(
            'Mobile app release upload: operation failed.',
            array_merge($context, [
                'operation' => $operation,
                'exception_class' => get_class($exception),
                'exception_message' => $exception->getMessage(),
                'exception_file' => $exception->getFile(),
                'exception_line' => $exception->getLine(),
                'exception_code' => $exception->getCode(),
            ])
        );
    }

    /**
     * Calculate request duration in milliseconds.
     */
    private function durationMs(float $startedAt): float
    {
        return round(
            (microtime(true) - $startedAt) * 1000,
            2
        );
    }
}
