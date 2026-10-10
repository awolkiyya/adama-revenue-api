<?php

namespace App\Modules\System\MobileAppRelease\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MobileAppReleaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $file = $this->relationLoaded('apkFile')
            ? $this->getRelation('apkFile')
            : null;

        $downloadable = $this->isDownloadable();

        return [
            /*
            |--------------------------------------------------------------------------
            | Release Identity
            |--------------------------------------------------------------------------
            */

            'id' => (string) $this->getKey(),

            /*
            |--------------------------------------------------------------------------
            | Release Information
            |--------------------------------------------------------------------------
            */

            'version_name' => $this->version_name,
            'version_code' => (int) $this->version_code,
            'release_notes' => $this->release_notes,

            /*
            |--------------------------------------------------------------------------
            | Release Flags
            |--------------------------------------------------------------------------
            */

            'is_latest' => (bool) $this->is_latest,
            'is_mandatory' => (bool) $this->is_mandatory,

            /*
            |--------------------------------------------------------------------------
            | Publication
            |--------------------------------------------------------------------------
            */

            'status' => $this->status,
            'published_at' => $this->published_at?->toISOString(),

            /*
            |--------------------------------------------------------------------------
            | APK Download
            |--------------------------------------------------------------------------
            */

            'is_downloadable' => $downloadable,

            'apk' => $file ? [
                'uuid' => $file->uuid,
                'original_name' => $file->original_name,
                'extension' => $file->extension,
                'size_bytes' => (int) $file->size_bytes,
                'checksum' => $file->checksum,
                'mime_type' => $file->mime_type,
                'status' => $file->status,
            ] : null,

            'download_url' => $downloadable
                ? route('mobile-app-releases.download', [
                    'mobile_app_release' => $this->getKey(),
                ])
                : null,

            /*
            |--------------------------------------------------------------------------
            | Creator
            |--------------------------------------------------------------------------
            */

            'creator' => $this->whenLoaded(
                'creator',
                fn () => $this->creator ? [
                    'id' => (string) $this->creator->getKey(),
                    'name' => $this->creator->name,
                ] : null
            ),

            /*
            |--------------------------------------------------------------------------
            | Timestamps
            |--------------------------------------------------------------------------
            */

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
