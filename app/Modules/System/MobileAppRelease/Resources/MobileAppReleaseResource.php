
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class MobileAppReleaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $file = $this->relationLoaded('apkFile')
            ? $this->apkFile
            : null;

        $fileIsReady = $file !== null
            && $file->status === 'READY'
            && strtolower((string) $file->extension) === 'apk'
            && ! empty($file->path)
            && Storage::disk($file->disk)->exists($file->path);

        $downloadable = $this->status === 'published'
            && $fileIsReady;

        return [
            'id' => (string) $this->getKey(),

            'version_name' => $this->version_name,
            'version_code' => (int) $this->version_code,

            'release_notes' => $this->release_notes,

            'is_latest' => (bool) $this->is_latest,
            'is_mandatory' => (bool) $this->is_mandatory,

            'status' => $this->status,
            'published_at' => $this->published_at?->toISOString(),

            'is_downloadable' => $downloadable,

            'apk' => $file ? [
                'uuid' => $file->uuid,
                'original_name' => $file->original_name,
                'size_bytes' => (int) $file->size_bytes,
                'checksum' => $file->checksum,
                'mime_type' => $file->mime_type,
            ] : null,

            'download_url' => $downloadable
                ? route('mobile-app-releases.download', [
                    'mobile_app_release' => $this->getKey(),
                ])
                : null,

            'creator' => $this->whenLoaded(
                'creator',
                fn () => $this->creator ? [
                    'id' => (string) $this->creator->getKey(),
                    'name' => $this->creator->name,
                ] : null
            ),

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}