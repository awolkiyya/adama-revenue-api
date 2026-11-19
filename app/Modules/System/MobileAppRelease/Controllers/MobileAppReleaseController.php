<?php

declare(strict_types=1);

namespace App\Modules\System\MobileAppRelease\Controllers;

use App\Http\Controllers\Controller;
use App\Models\MobileAppRelease;
use App\Models\MobileAppReleaseUpload;
use App\Modules\System\MobileAppRelease\Requests\StoreMobileAppReleaseRequest;
use App\Modules\System\MobileAppRelease\Requests\UpdateMobileAppReleaseRequest;
use App\Modules\System\MobileAppRelease\Resources\MobileAppReleaseResource;
use App\Modules\System\MobileAppRelease\Services\MobileAppReleaseService;
use App\Services\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class MobileAppReleaseController extends Controller
{
    public function __construct(
        private readonly MobileAppReleaseService $service
    ) {
    }

    /**
     * List releases with pagination, filters, and global statistics.
     */
    public function index(Request $request): JsonResponse
    {
        $startedAt = microtime(true);

        $validated = $request->validate([
            'search' => [
                'sometimes',
                'nullable',
                'string',
                'max:100',
            ],
            'status' => [
                'sometimes',
                'nullable',
                Rule::in(['draft', 'published', 'withdrawn']),
            ],
            'per_page' => [
                'sometimes',
                'integer',
                'min:1',
                'max:100',
            ],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 15);

        $search = isset($validated['search'])
            ? trim($validated['search'])
            : null;

        $status = $validated['status'] ?? null;

        Log::info(
            'Mobile app release controller: listing releases.',
            [
                'user_id' => $request->user()?->getKey(),
                'search_applied' => $search !== null && $search !== '',
                'status_filter' => $status,
                'per_page' => $perPage,
                'ip_address' => $request->ip(),
                'request_id' => $request->header('X-Request-ID'),
            ]
        );

        try {
            $query = MobileAppRelease::query()
                ->with(['creator', 'apkFile'])
                ->orderByDesc('created_at');

            if ($status !== null && $status !== '') {
                $query->where('status', $status);
            }

            if ($search !== null && $search !== '') {
                $query->where(function ($builder) use ($search) {
                    $builder
                        ->where('version_name', 'ILIKE', "%{$search}%")
                        ->orWhereRaw(
                            'CAST(version_code AS TEXT) ILIKE ?',
                            ["%{$search}%"]
                        );
                });
            }

            $releases = $query->paginate($perPage);

            /*
             * These statistics are global and are intentionally not
             * restricted by the current list filters.
             */
            $summaryQuery = MobileAppRelease::query();

            $summary = [
                'total' => (clone $summaryQuery)->count(),

                'drafts' => (clone $summaryQuery)
                    ->where('status', 'draft')
                    ->count(),

                'published' => (clone $summaryQuery)
                    ->where('status', 'published')
                    ->count(),

                'withdrawn' => (clone $summaryQuery)
                    ->where('status', 'withdrawn')
                    ->count(),

                'mandatory' => (clone $summaryQuery)
                    ->where('is_mandatory', true)
                    ->count(),

                'optional' => (clone $summaryQuery)
                    ->where('is_mandatory', false)
                    ->count(),

                'latest' => (clone $summaryQuery)
                    ->where('status', 'published')
                    ->where('is_latest', true)
                    ->orderByDesc('version_code')
                    ->value('version_name'),
            ];

            Log::info(
                'Mobile app release controller: releases retrieved successfully.',
                [
                    'user_id' => $request->user()?->getKey(),
                    'returned_count' => $releases->count(),
                    'matching_count' => $releases->total(),
                    'summary' => $summary,
                    'duration_ms' => $this->durationMs($startedAt),
                ]
            );

            return ApiResponse::success(
                data: MobileAppReleaseResource::collection($releases),
                message: 'Mobile app releases retrieved successfully.',
                summary: $summary,
            );
        } catch (Throwable $exception) {
            $this->logFailure('index', $exception, [
                'user_id' => $request->user()?->getKey(),
                'status_filter' => $status,
                'per_page' => $perPage,
                'duration_ms' => $this->durationMs($startedAt),
            ]);

            throw $exception;
        }
    }

    /**
     * Return the latest published release for a specific application.
     *
     * GET /api/v1/mobile-app-releases/latest?app_type=field_collector
     */
    public function latest(Request $request): JsonResponse
    {
        $startedAt = microtime(true);

        try {
            $validated = $request->validate([
                'app_type' => [
                    'required',
                    'string',
                    'max:50',
                    Rule::in(array_keys(config('mobile_apps.types', []))),
                ],
            ]);

            $appType = $validated['app_type'];

            $release = MobileAppRelease::query()
                ->with(['creator', 'apkFile'])
                ->where('app_type', $appType)
                ->where('status', 'published')
                ->where('is_latest', true)
                ->first();

            if ($release === null) {
                Log::warning(
                    'Mobile app release controller: no published release available.',
                    [
                        'app_type' => $appType,
                    ]
                );

                return ApiResponse::notFound(
                    "No published release is available for the {$appType} application."
                );
            }

            Log::info(
                'Mobile app release controller: latest release retrieved.',
                [
                    'release_id' => $release->getKey(),
                    'app_type' => $release->app_type,
                    'version_name' => $release->version_name,
                    'version_code' => $release->version_code,
                    'is_latest' => $release->is_latest,
                    'is_downloadable' => $release->isDownloadable(),
                    'duration_ms' => $this->durationMs($startedAt),
                ]
            );

            return ApiResponse::success(
                data: new MobileAppReleaseResource($release),
                message: 'Latest mobile app release retrieved successfully.'
            );
        } catch (Throwable $exception) {
            $this->logFailure('latest', $exception, [
                'app_type' => $request->input('app_type'),
                'duration_ms' => $this->durationMs($startedAt),
            ]);

            throw $exception;
        }
    }

    /**
     * Display a single release.
     *
     * Route model binding uses the release model's configured route key.
     */
    public function show(
        MobileAppRelease $mobile_app_release
    ): JsonResponse {
        $startedAt = microtime(true);

        try {
            $release = $mobile_app_release->load([
                'creator',
                'apkFile',
            ]);

            return ApiResponse::success(
                data: new MobileAppReleaseResource($release),
                message: 'Mobile app release retrieved successfully.'
            );
        } catch (Throwable $exception) {
            $this->logFailure(
                'show',
                $exception,
                $this->releaseContext($mobile_app_release, [
                    'duration_ms' => $this->durationMs($startedAt),
                ])
            );

            throw $exception;
        }
    }

    /**
     * Create a draft release using a completed chunked APK upload.
     *
     * The request must contain upload_uuid instead of an APK file.
     */
    public function store(
        StoreMobileAppReleaseRequest $request
    ): JsonResponse {
        $startedAt = microtime(true);
        $validated = $request->validated();

        $uploadUuid = $validated['upload_uuid'];

        unset($validated['upload_uuid']);

        $userId = $request->user()
            ? (string) $request->user()->getKey()
            : null;

        Log::info(
            'Mobile app release controller: create request received.',
            [
                'user_id' => $userId,
                'version_name' => $validated['version_name'] ?? null,
                'version_code' => $validated['version_code'] ?? null,
                'is_mandatory' => $validated['is_mandatory'] ?? null,
                'upload_uuid' => $uploadUuid,
                'ip_address' => $request->ip(),
                'request_id' => $request->header('X-Request-ID'),
            ]
        );

        try {
            $release = $this->consumeCompletedUpload(
                uploadUuid: $uploadUuid,
                request: $request,
                expectedAppType: $validated['app_type'],
                callback: function (UploadedFile $apk) use (
                    $validated,
                    $userId
                ): MobileAppRelease {
                    return $this->service->create(
                        $validated,
                        $apk,
                        $userId
                    );
                }
            );

            $release->load(['creator', 'apkFile']);

            Log::info(
                'Mobile app release controller: release created successfully.',
                [
                    'user_id' => $userId,
                    'release_id' => $release->getKey(),
                    'version_name' => $release->version_name,
                    'version_code' => $release->version_code,
                    'status' => $release->status,
                    'apk_file_id' => $release->apkFile?->getKey(),
                    'upload_uuid' => $uploadUuid,
                    'duration_ms' => $this->durationMs($startedAt),
                ]
            );

            return ApiResponse::created(
                data: new MobileAppReleaseResource($release),
                message: 'Mobile app release created successfully.'
            );
        } catch (Throwable $exception) {
            $this->logFailure('store', $exception, [
                'user_id' => $userId,
                'version_name' => $validated['version_name'] ?? null,
                'version_code' => $validated['version_code'] ?? null,
                'upload_uuid' => $uploadUuid,
                'duration_ms' => $this->durationMs($startedAt),
            ]);

            throw $exception;
        }
    }

    /**
     * Update a release and optionally replace its APK.
     *
     * If upload_uuid is omitted, only release metadata is updated.
     */
    public function update(
        UpdateMobileAppReleaseRequest $request,
        MobileAppRelease $mobile_app_release
    ): JsonResponse {
        $startedAt = microtime(true);
        $validated = $request->validated();

        $uploadUuid = $validated['upload_uuid'] ?? null;

        unset($validated['upload_uuid']);

        $userId = $request->user()
            ? (string) $request->user()->getKey()
            : null;

        try {
            if ($uploadUuid !== null) {
                $release = $this->consumeCompletedUpload(
                    uploadUuid: $uploadUuid,
                    request: $request,
                    expectedAppType: $mobile_app_release->app_type,
                    callback: function (UploadedFile $apk) use (
                        $mobile_app_release,
                        $validated,
                        $userId
                    ): MobileAppRelease {
                        return $this->service->update(
                            $mobile_app_release,
                            $validated,
                            $apk,
                            $userId
                        );
                    }
                );
            } else {
                $release = $this->service->update(
                    $mobile_app_release,
                    $validated,
                    null,
                    $userId
                );
            }

            $release->load(['creator', 'apkFile']);

            Log::info(
                'Mobile app release controller: release updated successfully.',
                [
                    'user_id' => $userId,
                    'release_id' => $release->getKey(),
                    'version_name' => $release->version_name,
                    'version_code' => $release->version_code,
                    'status' => $release->status,
                    'apk_replaced' => $uploadUuid !== null,
                    'upload_uuid' => $uploadUuid,
                    'duration_ms' => $this->durationMs($startedAt),
                ]
            );

            return ApiResponse::updated(
                data: new MobileAppReleaseResource($release),
                message: 'Mobile app release updated successfully.'
            );
        } catch (Throwable $exception) {
            $this->logFailure(
                'update',
                $exception,
                $this->releaseContext($mobile_app_release, [
                    'user_id' => $userId,
                    'updated_field_names' => array_keys($validated),
                    'apk_replacement_requested' => $uploadUuid !== null,
                    'upload_uuid' => $uploadUuid,
                    'duration_ms' => $this->durationMs($startedAt),
                ])
            );

            throw $exception;
        }
    }

    /**
     * Publish a release.
     */
    public function publish(
        MobileAppRelease $mobile_app_release
    ): JsonResponse {
        $startedAt = microtime(true);
        $userId = auth()->id();

        try {
            $release = $this->service->publish($mobile_app_release);

            $release->load(['creator', 'apkFile']);

            Log::info(
                'Mobile app release controller: release published successfully.',
                [
                    'user_id' => $userId,
                    'release_id' => $release->getKey(),
                    'version_name' => $release->version_name,
                    'version_code' => $release->version_code,
                    'status' => $release->status,
                    'is_latest' => $release->is_latest,
                    'published_at' => $release->published_at?->toIso8601String(),
                    'duration_ms' => $this->durationMs($startedAt),
                ]
            );

            return ApiResponse::success(
                data: new MobileAppReleaseResource($release),
                message: 'Mobile app release published successfully.'
            );
        } catch (Throwable $exception) {
            $this->logFailure(
                'publish',
                $exception,
                $this->releaseContext($mobile_app_release, [
                    'user_id' => $userId,
                    'duration_ms' => $this->durationMs($startedAt),
                ])
            );

            throw $exception;
        }
    }

    /**
     * Withdraw a release.
     */
    public function withdraw(
        MobileAppRelease $mobile_app_release
    ): JsonResponse {
        $startedAt = microtime(true);
        $userId = auth()->id();

        try {
            $release = $this->service->withdraw($mobile_app_release);

            $release->load(['creator', 'apkFile']);

            Log::info(
                'Mobile app release controller: release withdrawn successfully.',
                [
                    'user_id' => $userId,
                    'release_id' => $release->getKey(),
                    'version_name' => $release->version_name,
                    'version_code' => $release->version_code,
                    'status' => $release->status,
                    'is_latest' => $release->is_latest,
                    'duration_ms' => $this->durationMs($startedAt),
                ]
            );

            return ApiResponse::success(
                data: new MobileAppReleaseResource($release),
                message: 'Mobile app release withdrawn successfully.'
            );
        } catch (Throwable $exception) {
            $this->logFailure(
                'withdraw',
                $exception,
                $this->releaseContext($mobile_app_release, [
                    'user_id' => $userId,
                    'duration_ms' => $this->durationMs($startedAt),
                ])
            );

            throw $exception;
        }
    }

   
/**
 * Download a published APK.
 */
public function download(
    MobileAppRelease $mobile_app_release
): StreamedResponse|JsonResponse {
    $startedAt = microtime(true);
    $userId = auth()->id();

    try {
        $release = $mobile_app_release->loadMissing('apkFile');

        /*
        |--------------------------------------------------------------------------
        | Validate Release Availability
        |--------------------------------------------------------------------------
        */

        if (! $release->isDownloadable()) {
            Log::warning(
                'Mobile app release controller: download unavailable.',
                [
                    'user_id' => $userId,
                    'release_id' => $release->getKey(),
                    'status' => $release->status,
                    'apk_file_id' => $release->apkFile?->getKey(),
                    'ip_address' => request()->ip(),
                ]
            );

            return ApiResponse::notFound(
                'The APK is not available for download.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Validate APK File
        |--------------------------------------------------------------------------
        */

        $file = $release->apkFile;

        if (
            $file === null
            || ! Storage::disk($file->disk)->exists($file->path)
        ) {
            Log::warning(
                'Mobile app release controller: APK disappeared before download.',
                [
                    'release_id' => $release->getKey(),
                    'apk_file_id' => $file?->getKey(),
                    'ip_address' => request()->ip(),
                ]
            );

            return ApiResponse::notFound(
                'The APK file is no longer available.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Prepare Download Filename
        |--------------------------------------------------------------------------
        */

        $fileName = basename(
            $file->original_name ?: 'adama-field-collector.apk'
        );

        if (! str_ends_with(strtolower($fileName), '.apk')) {
            $fileName .= '.apk';
        }

        /*
        |--------------------------------------------------------------------------
        | Log Download
        |--------------------------------------------------------------------------
        */

        Log::info(
            'Mobile app release controller: APK download started.',
            [
                'user_id' => $userId,
                'release_id' => $release->getKey(),
                'version_name' => $release->version_name,
                'version_code' => $release->version_code,
                'apk_file_id' => $file->getKey(),
                'file_name' => $fileName,
                'ip_address' => request()->ip(),
                'duration_ms' => $this->durationMs($startedAt),
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Stream APK Download
        |--------------------------------------------------------------------------
        */

        return Storage::disk($file->disk)->download(
            $file->path,
            $fileName,
            [
                'Content-Type' => 'application/vnd.android.package-archive',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store, no-cache, must-revalidate',
                'Pragma' => 'no-cache',
            ]
        );
    } catch (Throwable $exception) {
        $this->logFailure(
            'download',
            $exception,
            $this->releaseContext($mobile_app_release, [
                'user_id' => $userId,
                'ip_address' => request()->ip(),
                'duration_ms' => $this->durationMs($startedAt),
            ])
        );

        throw $exception;
    }
}


    /*
    |--------------------------------------------------------------------------
    | Chunked Upload Integration
    |--------------------------------------------------------------------------
    */

    /**
     * Consume a completed upload and create/update a release from its APK.
     *
     * The upload row is locked for the duration of the transaction.
     * The release operation and CONSUMED status update commit together.
     *
     * The uploaded APK is deleted only after the database transaction
     * successfully commits.
     *
     * @param callable(UploadedFile): MobileAppRelease $callback
     */
    private function consumeCompletedUpload(
        string $uploadUuid,
        Request $request,
        string $expectedAppType,
        callable $callback
    ): MobileAppRelease {
        $user = $request->user();

        if ($user === null) {
            throw ValidationException::withMessages([
                'upload_uuid' => 'Authentication is required to use this upload.',
            ]);
        }

        /*
         * The authenticated user's primary key is a UUID.
         * Do not cast it to an integer.
         */
        $userId = (string) $user->getKey();

        $consumedUpload = DB::transaction(function () use (
            $uploadUuid,
            $userId,
            $expectedAppType,
            $callback
        ): array {
            $upload = MobileAppReleaseUpload::query()
                ->where('uuid', $uploadUuid)
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();

            if ($upload === null) {
                throw ValidationException::withMessages([
                    'upload_uuid' => 'The APK upload session was not found.',
                ]);
            }

            if ($upload->status !== MobileAppReleaseUpload::STATUS_COMPLETED) {
                throw ValidationException::withMessages([
                    'upload_uuid' => 'This APK upload has already been used or is not completed.',
                ]);
            }

            if ($upload->isExpired()) {
                throw ValidationException::withMessages([
                    'upload_uuid' => 'This APK upload session has expired.',
                ]);
            }

            if ($upload->app_type !== $expectedAppType) {
                throw ValidationException::withMessages([
                    'upload_uuid' => 'The uploaded APK belongs to a different application type.',
                ]);
            }

            if (empty($upload->assembled_path)) {
                throw ValidationException::withMessages([
                    'upload_uuid' => 'The assembled APK path is missing.',
                ]);
            }

            $disk = Storage::disk($upload->disk);

            if (! $disk->exists($upload->assembled_path)) {
                throw ValidationException::withMessages([
                    'upload_uuid' => 'The assembled APK file is no longer available.',
                ]);
            }

            /*
             * This upload implementation uses local storage.
             * The configured disk must expose an absolute filesystem path.
             */
            if (! method_exists($disk, 'path')) {
                throw new RuntimeException(
                    'The configured APK disk does not support local file paths.'
                );
            }

            $absolutePath = $disk->path($upload->assembled_path);

            if (
                ! is_file($absolutePath)
                || ! is_readable($absolutePath)
            ) {
                throw ValidationException::withMessages([
                    'upload_uuid' => 'The assembled APK cannot be accessed.',
                ]);
            }

            clearstatcache(true, $absolutePath);

            $actualSize = filesize($absolutePath);

            if (
                $actualSize === false
                || $actualSize !== (int) $upload->total_size
            ) {
                throw ValidationException::withMessages([
                    'upload_uuid' => 'The assembled APK size does not match the upload session.',
                ]);
            }

            /*
             * Verify the assembled file against the checksum recorded
             * during upload completion, when a checksum is available.
             */
            if (! empty($upload->checksum)) {
                $actualChecksum = hash_file('sha256', $absolutePath);

                if (
                    $actualChecksum === false
                    || ! hash_equals(
                        strtolower($upload->checksum),
                        strtolower($actualChecksum)
                    )
                ) {
                    throw ValidationException::withMessages([
                        'upload_uuid' => 'The assembled APK checksum verification failed.',
                    ]);
                }
            }

            $apk = new UploadedFile(
                $absolutePath,
                $upload->original_name,
                'application/vnd.android.package-archive',
                null,
                true
            );

            /*
             * The existing release service remains responsible for:
             * - release validation and lifecycle rules;
             * - permanent APK storage;
             * - File model creation/update;
             * - release database operations.
             *
             * Its transaction, if any, is nested within this transaction.
             */
            $release = $callback($apk);

            if (! $release instanceof MobileAppRelease) {
                throw new RuntimeException(
                    'The mobile app release operation did not return a valid release.'
                );
            }

            /*
             * Mark the upload consumed in the same transaction as the
             * release operation. The row lock prevents concurrent reuse.
             *
             * Keep assembled_path until filesystem cleanup succeeds.
             */
            $upload->status = MobileAppReleaseUpload::STATUS_CONSUMED;
            $upload->save();

            return [
                'release' => $release,
                'upload_id' => $upload->getKey(),
                'upload_uuid' => $upload->uuid,
                'disk' => $upload->disk,
                'assembled_path' => $upload->assembled_path,
            ];
        });

        /*
         * The transaction has committed successfully at this point.
         * Filesystem cleanup must not roll back the committed release.
         */
        $this->cleanupConsumedUpload($consumedUpload);

        return $consumedUpload['release'];
    }

    /**
     * Delete the temporary assembled APK after successful consumption.
     *
     * If deletion fails, assembled_path remains in the database so a
     * scheduled cleanup process can retry it.
     */
    private function cleanupConsumedUpload(array $temporaryFile): void
    {
        $diskName = $temporaryFile['disk'];
        $path = $temporaryFile['assembled_path'];

        if (empty($path)) {
            return;
        }

        try {
            $disk = Storage::disk($diskName);

            if ($disk->exists($path) && ! $disk->delete($path)) {
                Log::warning(
                    'Mobile app release controller: consumed upload temporary file could not be deleted.',
                    [
                        'upload_uuid' => $temporaryFile['upload_uuid'],
                        'upload_id' => $temporaryFile['upload_id'],
                        'disk' => $diskName,
                        'path' => $path,
                    ]
                );

                return;
            }

            /*
             * Clear the path only after the file is confirmed deleted
             * or is already absent.
             */
            MobileAppReleaseUpload::query()
                ->whereKey($temporaryFile['upload_id'])
                ->where(
                    'status',
                    MobileAppReleaseUpload::STATUS_CONSUMED
                )
                ->where('assembled_path', $path)
                ->update([
                    'assembled_path' => null,
                    'updated_at' => now(),
                ]);
        } catch (Throwable $exception) {
            /*
             * Do not fail the API response after the release has already
             * been committed. A cleanup job can retry this operation.
             */
            Log::warning(
                'Mobile app release controller: temporary upload cleanup failed.',
                [
                    'upload_uuid' => $temporaryFile['upload_uuid'],
                    'upload_id' => $temporaryFile['upload_id'],
                    'disk' => $diskName,
                    'path' => $path,
                    'exception_class' => get_class($exception),
                    'exception_message' => $exception->getMessage(),
                ]
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Logging Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Build consistent release logging context.
     */
    private function releaseContext(
        ?MobileAppRelease $release = null,
        array $extra = []
    ): array {
        return array_merge([
            'release_id' => $release?->getKey(),
            'version_name' => $release?->version_name,
            'version_code' => $release?->version_code,
            'status' => $release?->status,
            'is_latest' => $release?->is_latest,
        ], $extra);
    }

    /**
     * Log a release-related event.
     */
    private function logReleaseEvent(
        string $level,
        string $message,
        ?MobileAppRelease $release = null,
        array $extra = []
    ): void {
        Log::log(
            $level,
            'Mobile app release controller: ' . $message,
            $this->releaseContext($release, $extra)
        );
    }

    /**
     * Log unexpected controller failures.
     */
    private function logFailure(
        string $operation,
        Throwable $exception,
        array $context = []
    ): void {
        Log::error(
            'Mobile app release controller: operation failed.',
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
        return round((microtime(true) - $startedAt) * 1000, 2);
    }
}
