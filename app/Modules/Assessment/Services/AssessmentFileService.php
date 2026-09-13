<?php

namespace App\Modules\Assessment\Services;

use App\Models\Assessment;
use App\Models\AssessmentService as AssessmentServiceModel;
use App\Models\AssessmentServiceValue;
use App\Models\File;
use App\Services\Storage\StorageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class AssessmentFileService
{
    public function __construct(
        protected StorageService $storageService,
    ) {
    }

    /**
     * ============================================================
     * STORE UPLOADED FILES
     * ============================================================
     */

    /**
     * Store files submitted for a dynamic service field.
     *
     * Supports:
     *
     * - UploadedFile
     * - UploadedFile[]
     * - Existing file UUID
     * - Existing file UUID[]
     */
    public function storeUploadedFiles(
        AssessmentServiceModel $assessmentService,
        $serviceField,
        mixed $value,
        string $inputType
    ): mixed {
        $isMultiple = $inputType === 'MULTI_FILE';

        $values = $isMultiple
            ? (is_array($value) ? $value : [$value])
            : [$value];

        $storedFileIds = [];

        foreach ($values as $fileValue) {
            if ($fileValue instanceof UploadedFile) {
                $file = $this->uploadAssessmentFile(
                    $assessmentService,
                    $fileValue
                );

                $storedFileIds[] = $file->id;

                continue;
            }

            if (
                is_string($fileValue)
                && $fileValue !== ''
            ) {
                $fileUuid = $this->resolveFileUuid(
                    $fileValue
                );

                $file = File::query()
                    ->findOrFail($fileUuid);

                $this->assertFileCanBeAttached(
                    $file,
                    $assessmentService
                );

                $storedFileIds[] = $file->id;

                continue;
            }

            throw ValidationException::withMessages([
                'services' => [
                    'Invalid file value supplied.',
                ],
            ]);
        }

        return $isMultiple
            ? $storedFileIds
            : ($storedFileIds[0] ?? null);
    }

    /**
     * ============================================================
     * UPLOAD
     * ============================================================
     */

    /**
     * Upload a new assessment file.
     */
    public function uploadAssessmentFile(
        AssessmentServiceModel $assessmentService,
        UploadedFile $uploadedFile
    ): File {
        try {
            /*
            |--------------------------------------------------------------------------
            | Storage
            |--------------------------------------------------------------------------
            */

            $stored = $this->storageService->store(
                $uploadedFile,
                'assessments'
            );

            if (! $stored) {
                throw new RuntimeException(
                    'Failed to store assessment file.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | File Record
            |--------------------------------------------------------------------------
            */

            return File::create([
                'name' => $uploadedFile->getClientOriginalName(),

                'path' => $stored['path'],

                'disk' => $stored['disk'] ?? config(
                    'filesystems.default'
                ),

                'mime_type' => $uploadedFile->getClientMimeType(),

                'size' => $uploadedFile->getSize(),

                'extension' => $uploadedFile->getClientOriginalExtension(),

                'uploaded_by' => auth()->id(),
            ]);
        } catch (Throwable $e) {
            throw new RuntimeException(
                'Failed to upload assessment file.',
                previous: $e
            );
        }
    }

    /**
     * ============================================================
     * ATTACH FILES
     * ============================================================
     */

    /**
     * Attach stored files to an assessment service value.
     */
    public function attachValueFiles(
        AssessmentServiceValue $value,
        mixed $fileIds
    ): void {
        if ($fileIds === null) {
            return;
        }

        $ids = is_array($fileIds)
            ? $fileIds
            : [$fileIds];

        foreach ($ids as $fileId) {
            if (! $fileId) {
                continue;
            }

            $file = File::query()->findOrFail($fileId);

            /*
            |--------------------------------------------------------------------------
            | Prevent Duplicate Attachment
            |--------------------------------------------------------------------------
            */

            if (
                $value->files()
                    ->whereKey($file->id)
                    ->exists()
            ) {
                continue;
            }

            $value->files()->attach($file->id);
        }
    }

    /**
     * ============================================================
     * COLLECT FILES
     * ============================================================
     */

    /**
     * Collect all file IDs currently attached to an assessment.
     */
    public function collectAssessmentFiles(
        Assessment $assessment
    ): array {
        return File::query()
            ->whereHas(
                'assessmentServiceValues.assessmentService',
                function ($query) use ($assessment) {
                    $query->where(
                        'assessment_id',
                        $assessment->id
                    );
                }
            )
            ->pluck('id')
            ->map(
                static fn ($id) => (string) $id
            )
            ->all();
    }

    /**
     * ============================================================
     * FIND OBSOLETE FILES
     * ============================================================
     */

    /**
     * Find files that existed before service replacement but
     * are no longer attached afterward.
     */
    public function findObsoleteFiles(
        array $existingFileIds,
        array $currentFileIds
    ): array {
        return array_values(
            array_diff(
                $existingFileIds,
                $currentFileIds
            )
        );
    }

    /**
     * ============================================================
     * REMOVE ASSESSMENT SERVICES
     * ============================================================
     */

    /**
     * Remove all services and values belonging to an assessment.
     *
     * Database records are removed here.
     * Physical files are cleaned separately after the transaction.
     */
    public function removeAssessmentServices(
        Assessment $assessment
    ): void {
        $services = AssessmentServiceModel::query()
            ->where('assessment_id', $assessment->id)
            ->with('values.files')
            ->get();

        foreach ($services as $assessmentService) {
            foreach ($assessmentService->values as $value) {
                /*
                |--------------------------------------------------------------------------
                | Detach Files
                |--------------------------------------------------------------------------
                */

                $value->files()->detach();

                /*
                |--------------------------------------------------------------------------
                | Delete Value
                |--------------------------------------------------------------------------
                */

                $value->delete();
            }

            /*
            |--------------------------------------------------------------------------
            | Delete Service
            |--------------------------------------------------------------------------
            */

            $assessmentService->delete();
        }
    }

    /**
     * ============================================================
     * CLEANUP
     * ============================================================
     */

    /**
     * Clean up files that are no longer referenced.
     */
    public function cleanupObsoleteFiles(
        array $fileIds
    ): void {
        if ($fileIds === []) {
            return;
        }

        $files = File::query()
            ->whereIn('id', $fileIds)
            ->get();

        foreach ($files as $file) {
            /*
            |--------------------------------------------------------------------------
            | Safety Check
            |--------------------------------------------------------------------------
            |
            | Never physically delete a file that has been re-attached
            | elsewhere after the assessment update.
            |
            */

            if ($this->fileIsStillReferenced($file)) {
                continue;
            }

            $this->deleteFile($file);
        }
    }

    /**
     * Clean up files created during a failed operation.
     */
    public function cleanupCreatedFiles(
        array $fileIds
    ): void {
        if ($fileIds === []) {
            return;
        }

        $files = File::query()
            ->whereIn('id', $fileIds)
            ->get();

        foreach ($files as $file) {
            if ($this->fileIsStillReferenced($file)) {
                continue;
            }

            $this->deleteFile($file);
        }
    }

    /**
     * ============================================================
     * FILE REFERENCE
     * ============================================================
     */

    /**
     * Determine whether a file is still attached to any value.
     */
    protected function fileIsStillReferenced(
        File $file
    ): bool {
        return DB::table(
            'assessment_service_value_file'
        )
            ->where(
                'file_id',
                $file->id
            )
            ->exists();
    }

    /**
     * ============================================================
     * DELETE
     * ============================================================
     */

    /**
     * Delete the physical file and its database record.
     */
    protected function deleteFile(
        File $file
    ): void {
        try {
            /*
            |--------------------------------------------------------------------------
            | Delete Physical File
            |--------------------------------------------------------------------------
            */

            $this->storageService->delete(
                $file->path,
                $file->disk
            );

            /*
            |--------------------------------------------------------------------------
            | Delete Database Record
            |--------------------------------------------------------------------------
            */

            $file->delete();
        } catch (Throwable $e) {
            /*
            |--------------------------------------------------------------------------
            | Do Not Break Main Workflow
            |--------------------------------------------------------------------------
            |
            | File cleanup is secondary to the assessment transaction.
            | Log the failure and leave the database record available
            | for later cleanup/reconciliation.
            |
            */

            report($e);
        }
    }

    /**
     * ============================================================
     * RESOLVE FILE UUID
     * ============================================================
     */

    /**
     * Resolve a file identifier.
     */
    protected function resolveFileUuid(
        string $value
    ): string {
        $value = trim($value);

        if ($value === '') {
            throw ValidationException::withMessages([
                'services' => [
                    'File identifier cannot be empty.',
                ],
            ]);
        }

        if (! preg_match(
            '/^[0-9a-fA-F-]{36}$/',
            $value
        )) {
            throw ValidationException::withMessages([
                'services' => [
                    'Invalid file identifier.',
                ],
            ]);
        }

        return $value;
    }

    /**
     * ============================================================
     * ATTACHMENT VALIDATION
     * ============================================================
     */

    /**
     * Ensure an existing file can be attached to the assessment.
     */
    protected function assertFileCanBeAttached(
        File $file,
        AssessmentServiceModel $assessmentService
    ): void {
        /*
        |--------------------------------------------------------------------------
        | Basic File State
        |--------------------------------------------------------------------------
        */

        if (! $file->path) {
            throw ValidationException::withMessages([
                'services' => [
                    'The selected file does not have a valid storage path.',
                ],
            ]);
        }

        if (! $file->disk) {
            throw ValidationException::withMessages([
                'services' => [
                    'The selected file does not have a valid storage disk.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Ownership / Reuse
        |--------------------------------------------------------------------------
        |
        | Existing files should only be attachable when they belong to
        | the current authenticated user or are otherwise explicitly
        | marked as reusable.
        |
        | Adapt this rule if your File model uses a different ownership
        | model.
        |
        */

        if (
            isset($file->uploaded_by)
            && $file->uploaded_by !== null
            && (string) $file->uploaded_by
                !== (string) auth()->id()
        ) {
            throw ValidationException::withMessages([
                'services' => [
                    'You are not allowed to attach this file.',
                ],
            ]);
        }
    }
}