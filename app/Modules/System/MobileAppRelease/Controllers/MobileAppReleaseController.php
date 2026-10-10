
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMobileAppReleaseRequest;
use App\Http\Requests\UpdateMobileAppReleaseRequest;
use App\Http\Resources\MobileAppReleaseResource;
use App\Models\MobileAppRelease;
use App\Services\MobileAppReleaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MobileAppReleaseController extends Controller
{
    public function __construct(
        private readonly MobileAppReleaseService $service
    ) {
    }

    /**
     * List releases with optional search and status filters.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => [
                'sometimes',
                'nullable',
                'in:draft,published,withdrawn',
            ],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = MobileAppRelease::query()
            ->with(['creator', 'apkFile'])
            ->orderByDesc('created_at');

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['search'])) {
            $search = $validated['search'];

            $query->where(function ($builder) use ($search) {
                $builder
                    ->where('version_name', 'like', "%{$search}%")
                    ->orWhere('version_code', 'like', "%{$search}%");
            });
        }

        return MobileAppReleaseResource::collection(
            $query->paginate($validated['per_page'] ?? 15)
        );
    }

    /**
     * Return the latest published release.
     *
     * Intended for the Android app's update check.
     */
    public function latest(): MobileAppReleaseResource|JsonResponse
    {
        $release = MobileAppRelease::query()
            ->with(['creator', 'apkFile'])
            ->latestRelease()
            ->orderByDesc('version_code')
            ->first();

        if ($release === null) {
            return response()->json([
                'message' => 'No published mobile app release is available.',
            ], 404);
        }

        return new MobileAppReleaseResource($release);
    }

    /**
     * Display one release.
     */
    public function show(
        MobileAppRelease $mobile_app_release
    ): MobileAppReleaseResource {
        return new MobileAppReleaseResource(
            $mobile_app_release->load(['creator', 'apkFile'])
        );
    }

    /**
     * Create a draft release and upload its APK.
     */
    public function store(
        StoreMobileAppReleaseRequest $request
    ): JsonResponse {
        $validated = $request->validated();

        $apk = $validated['apk'];
        unset($validated['apk']);

        $release = $this->service->create(
            $validated,
            $apk,
            (string) $request->user()->getKey()
        );

        return (new MobileAppReleaseResource($release))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Update a draft release.
     */
    public function update(
        UpdateMobileAppReleaseRequest $request,
        MobileAppRelease $mobile_app_release
    ): MobileAppReleaseResource {
        $validated = $request->validated();

        $apk = $validated['apk'] ?? null;
        unset($validated['apk']);

        $release = $this->service->update(
            $mobile_app_release,
            $validated,
            $apk,
            (string) $request->user()->getKey()
        );

        return new MobileAppReleaseResource($release);
    }

    /**
     * Publish a draft and make it the latest release.
     */
    public function publish(
        MobileAppRelease $mobile_app_release
    ): MobileAppReleaseResource {
        $release = $this->service->publish($mobile_app_release);

        return new MobileAppReleaseResource($release);
    }

    /**
     * Withdraw a release.
     */
    public function withdraw(
        MobileAppRelease $mobile_app_release
    ): MobileAppReleaseResource {
        $release = $this->service->withdraw($mobile_app_release);

        return new MobileAppReleaseResource($release);
    }

    /**
     * Download a published APK.
     */
    public function download(
        MobileAppRelease $mobile_app_release
    ): StreamedResponse|JsonResponse {
        abort_unless(
            $mobile_app_release->status === 'published',
            404,
            'This release is not available for download.'
        );

        $file = $mobile_app_release->apkFile;

        if (
            $file === null
            || $file->status !== 'READY'
            || strtolower((string) $file->extension) !== 'apk'
            || empty($file->path)
            || ! Storage::disk($file->disk)->exists($file->path)
        ) {
            throw ValidationException::withMessages([
                'apk' => 'The APK file is not available for download.',
            ]);
        }

        return Storage::disk($file->disk)->download(
            $file->path,
            $file->original_name,
            [
                'Content-Type' => 'application/vnd.android.package-archive',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store',
            ]
        );
    }
}