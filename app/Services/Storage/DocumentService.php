<?php

namespace App\Services\Storage;

use Illuminate\Http\UploadedFile;
use App\Models\File;

class DocumentService extends StorageService
{
    /**
     * Upload any template file (PLAN / REPORT / future types)
     */
    public function uploadTemplate(
        UploadedFile $file,
        string $type,
        int $uploadedBy
    ): ?File {

        return parent::upload(
            uploadedFile: $file,
            folder: $this->resolveFolder($type),
            uploadedBy: $uploadedBy,
            category: $this->resolveCategory($type),
            visibility: 'private'
        );
    }

    /**
     * Resolve storage folder dynamically
     */
    private function resolveFolder(string $type): string
    {
        return match (strtoupper($type)) {
            'PLAN' => 'plans/templates',
            'REPORT' => 'reports/templates',
            default => 'templates/others'
        };
    }

    /**
     * Resolve file category dynamically
     */
    private function resolveCategory(string $type): string
    {
        return strtolower($type) . '_template';
    }
}