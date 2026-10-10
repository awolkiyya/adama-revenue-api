<?php

namespace App\Modules\System\MobileAppRelease\Controllers;

use App\Http\Controllers\Controller;
use App\Models\MobileAppRelease;
use App\Modules\System\MobileAppRelease\Requests\StoreMobileAppReleaseRequest;
use App\Modules\System\MobileAppRelease\Requests\UpdateMobileAppReleaseRequest;
use App\Modules\System\MobileAppRelease\Resources\MobileAppReleaseResource;
use App\Modules\System\MobileAppRelease\Services\MobileAppReleaseService;
use App\Services\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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
                'in:draft,published,withdrawn',
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
                'user_id' => auth()->id(),
                'search_applied' => $search !== null && $search !== '',
                'status_filter' => $status,
                'per_page' => $perPage,
                'ip_address' => $request->ip(),
                'request_id' => $request->header('X-Request-ID'),
            ]
        );

        try {
            /*
            |--------------------------------------------------------------------------
            | Releases Query
            |--------------------------------------------------------------------------
            */

            $query = MobileAppRelease::query()
                ->with(['creator', 'apkFile'])
                ->orderByDesc('created_at');

            if (! empty($status)) {
                $query->where('status', $status);
            }

            if ($search !== null && $search !== '') {
                $query->where(function ($builder) use ($search) {
                    $builder
                        ->where(
                            'version_name',
                            'ILIKE',
                            "%{$search}%"
                        )
                        ->orWhereRaw(
                            'CAST(version_code AS TEXT) ILIKE ?',
                            ["%{$search}%"]
                        );
                });
            }

            /*
            |--------------------------------------------------------------------------
            | Pagination
            |--------------------------------------------------------------------------
            */

            $releases = $query->paginate($perPage);

            /*
            |--------------------------------------------------------------------------
            | Global Summary Statistics
            |--------------------------------------------------------------------------
            |
            | These statistics are independent of the current filters
            | and pagination page.
            |
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
                    'user_id' => auth()->id(),
                    'returned_count' => $releases->count(),
                    'matching_count' => $releases->total(),
                    'summary' => $summary,
                    'duration_ms' => round(
                        (microtime(true) - $startedAt) * 1000,
                        2
                    ),
                ]
            );

            return ApiResponse::success(
                data: MobileAppReleaseResource::collection($releases),
                message: 'Mobile app releases retrieved successfully.',
                summary: $summary,
            );
        } catch (Throwable $exception) {
            $this->logFailure('index', $exception, [
                'user_id' => auth()->id(),
                'status_filter' => $status,
                'per_page' => $perPage,
                'duration_ms' => round(
                    (microtime(true) - $startedAt) * 1000,
                    2
                ),
            ]);

            throw $exception;
        }
    }

    /**
     * Return the latest published release.
     */
    public function latest(): JsonResponse
    {
        $startedAt = microtime(true);

        try {
            $release = MobileAppRelease::query()
                ->with(['creator', 'apkFile'])
                ->latestRelease()
                ->orderByDesc('version_code')
                ->first();

            if ($release === null) {
                Log::warning(
                    'Mobile app release controller: no published release available.'
                );

                return ApiResponse::notFound(
                    'No published mobile app release is available.'
                );
            }

            Log::info(
                'Mobile app release controller: latest release retrieved.',
                [
                    'release_id' => $release->getKey(),
                    'version_name' => $release->version_name,
                    'version_code' => $release->version_code,
                    'is_downloadable' => $release->isDownloadable(),
                    'duration_ms' => round(
                        (microtime(true) - $startedAt) * 1000,
                        2
                    ),
                ]
            );

            return ApiResponse::success(
                data: new MobileAppReleaseResource($release),
                message: 'Latest mobile app release retrieved successfully.'
            );
        } catch (Throwable $exception) {
            $this->logFailure('latest', $exception, [
                'duration_ms' => round(
                    (microtime(true) - $startedAt) * 1000,
                    2
                ),
            ]);

            throw $exception;
        }
    }

    /**
     * Display a single release.
     *
     * Implicit route model binding uses the model's UUID primary key.
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
     * Create a draft release and store its APK.
     */
    public function store(
        StoreMobileAppReleaseRequest $request
    ): JsonResponse {
        $startedAt = microtime(true);
        $validated = $request->validated();

        $apk = $validated['apk'];

        unset($validated['apk']);

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
                'apk_original_name' => $apk->getClientOriginalName(),
                'apk_size_bytes' => $apk->getSize(),
                'apk_extension' => $apk->getClientOriginalExtension(),
                'ip_address' => $request->ip(),
                'request_id' => $request->header('X-Request-ID'),
            ]
        );

        try {
            $release = $this->service->create(
                $validated,
                $apk,
                $userId
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
                    'duration_ms' => round(
                        (microtime(true) - $startedAt) * 1000,
                        2
                    ),
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
                'apk_original_name' => $apk->getClientOriginalName(),
                'apk_size_bytes' => $apk->getSize(),
                'duration_ms' => round(
                    (microtime(true) - $startedAt) * 1000,
                    2
                ),
            ]);

            throw $exception;
        }
    }

    /**
     * Update a release and optionally replace its APK.
     */
    public function update(
        UpdateMobileAppReleaseRequest $request,
        MobileAppRelease $mobile_app_release
    ): JsonResponse {
        $startedAt = microtime(true);
        $validated = $request->validated();

        $apk = $validated['apk'] ?? null;

        unset($validated['apk']);

        $userId = $request->user()
            ? (string) $request->user()->getKey()
            : null;

        try {
            $release = $this->service->update(
                $mobile_app_release,
                $validated,
                $apk,
                $userId
            );

            $release->load(['creator', 'apkFile']);

            Log::info(
                'Mobile app release controller: release updated successfully.',
                [
                    'user_id' => $userId,
                    'release_id' => $release->getKey(),
                    'version_name' => $release->version_name,
                    'version_code' => $release->version_code,
                    'status' => $release->status,
                    'apk_replaced' => $apk !== null,
                    'duration_ms' => round(
                        (microtime(true) - $startedAt) * 1000,
                        2
                    ),
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
                    'apk_replacement_requested' => $apk !== null,
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
                    'duration_ms' => round(
                        (microtime(true) - $startedAt) * 1000,
                        2
                    ),
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
                    'duration_ms' => round(
                        (microtime(true) - $startedAt) * 1000,
                        2
                    ),
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
     * Download a published APK.
     *
     * The model centralizes the downloadability checks.
     */
    public function download(
        MobileAppRelease $mobile_app_release
    ): StreamedResponse|JsonResponse {
        $startedAt = microtime(true);
        $userId = auth()->id();

        try {
            $release = $mobile_app_release->loadMissing('apkFile');

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

            $file = $release->apkFile;

            /*
             * isDownloadable() has already verified the release and file.
             * Check storage again immediately before streaming to reduce
             * the chance of a stale file-existence result.
             */
            if (! Storage::disk($file->disk)->exists($file->path)) {
                Log::warning(
                    'Mobile app release controller: APK disappeared before download.',
                    [
                        'release_id' => $release->getKey(),
                        'apk_file_id' => $file->getKey(),
                        'ip_address' => request()->ip(),
                    ]
                );

                return ApiResponse::notFound(
                    'The APK file is no longer available.'
                );
            }

            Log::info(
                'Mobile app release controller: APK download started.',
                [
                    'user_id' => $userId,
                    'release_id' => $release->getKey(),
                    'version_name' => $release->version_name,
                    'version_code' => $release->version_code,
                    'apk_file_id' => $file->getKey(),
                    'ip_address' => request()->ip(),
                    'duration_ms' => round(
                        (microtime(true) - $startedAt) * 1000,
                        2
                    ),
                ]
            );

            return Storage::disk($file->disk)->download(
                $file->path,
                $file->original_name,
                [
                    'Content-Type' => 'application/vnd.android.package-archive',
                    'X-Content-Type-Options' => 'nosniff',
                    'Cache-Control' => 'private, no-store',
                ]
            );
        } catch (Throwable $exception) {
            $this->logFailure(
                'download',
                $exception,
                $this->releaseContext($mobile_app_release, [
                    'user_id' => $userId,
                    'ip_address' => request()->ip(),
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
}