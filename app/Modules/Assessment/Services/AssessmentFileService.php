<?php

namespace App\Modules\Assessment\Services;

use App\Models\Assessment;
use App\Models\AssessmentService as AssessmentServiceModel;
use App\Models\AssessmentServiceValue;
use App\Models\File;
use App\Services\Storage\StorageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class AssessmentFileService
{
    public function __construct(
        protected StorageService $storageService,
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | STORE UPLOADED FILES
    |--------------------------------------------------------------------------
    */

    /**
     * Store files submitted for a dynamic service field.
     *
     * Supported values:
     * - UploadedFile
     * - UploadedFile[]
     * - {"__file": "multipart-field-name"}
     * - [{"__file": "multipart-field-name"}]
     * - {"__existingFile": "existing-file-uuid"}
     * - Existing file UUID
     * - Existing file UUID[]
     *
     * FILE returns one file database ID.
     * MULTI_FILE returns an array of file database IDs.
     */
    public function storeUploadedFiles(
        AssessmentServiceModel $assessmentService,
        $serviceField,
        mixed $value,
        string $inputType
    ): mixed {
        $inputType = strtoupper(trim($inputType));

        if (! in_array($inputType, ['FILE', 'MULTI_FILE'], true)) {
            throw ValidationException::withMessages([
                'services' => [
                    'Unsupported file input type.',
                ],
            ]);
        }

        $isMultiple = $inputType === 'MULTI_FILE';

        if ($value === null || $value === '') {
            throw ValidationException::withMessages([
                'services' => [
                    'No file was supplied.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | NORMALIZE INPUT
        |--------------------------------------------------------------------------
        */

        if ($isMultiple) {
            if (
                is_array($value)
                && (
                    array_key_exists('__file', $value)
                    || array_key_exists('__existingFile', $value)
                )
            ) {
                $values = [$value];
            } elseif (is_array($value)) {
                $values = array_values($value);
            } else {
                $values = [$value];
            }
        } else {
            /*
            |--------------------------------------------------------------------------
            | SINGLE FILE
            |--------------------------------------------------------------------------
            */

            if (
                is_array($value)
                && array_key_exists('__existingFile', $value)
            ) {
                $existingFileId = $value['__existingFile'];

                if (
                    ! is_string($existingFileId)
                    || trim($existingFileId) === ''
                ) {
                    throw ValidationException::withMessages([
                        'services' => [
                            'Invalid existing file reference.',
                        ],
                    ]);
                }

                // Preserve one existing file reference.
                $values = [trim($existingFileId)];
            } elseif (
                is_array($value)
                && array_key_exists('__file', $value)
            ) {
                // New multipart upload reference.
                $values = [$value];
            } elseif (is_array($value)) {
                // A single-file field may arrive as ['UUID'].
                if (
                    count($value) === 1
                    && array_is_list($value)
                ) {
                    $values = [array_values($value)[0]];
                } else {
                    throw ValidationException::withMessages([
                        'services' => [
                            'Only one file can be supplied for this field.',
                        ],
                    ]);
                }
            } else {
                $values = [$value];
            }
        }

        $storedFileIds = [];

        /*
        |--------------------------------------------------------------------------
        | PROCESS FILES
        |--------------------------------------------------------------------------
        */

        foreach ($values as $fileValue) {
            if ($fileValue === null || $fileValue === '') {
                throw ValidationException::withMessages([
                    'services' => [
                        'An empty file value was supplied.',
                    ],
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | RESOLVE MULTIPART REFERENCE
            |--------------------------------------------------------------------------
            */

            $fileValue = $this->resolveUploadedFileReference(
                $fileValue
            );

            /*
            |--------------------------------------------------------------------------
            | NEW UPLOADED FILE
            |--------------------------------------------------------------------------
            */

            if ($fileValue instanceof UploadedFile) {
                if (! $fileValue->isValid()) {
                    throw ValidationException::withMessages([
                        'services' => [
                            'The uploaded file is invalid.',
                        ],
                    ]);
                }

                $file = $this->uploadAssessmentFile(
                    $assessmentService,
                    $fileValue
                );

                $storedFileIds[] = $file->id;

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | EXISTING FILE REFERENCE
            |--------------------------------------------------------------------------
            */

            if (
                is_string($fileValue)
                && trim($fileValue) !== ''
            ) {
                $file = $this->resolveExistingFile($fileValue);

                $this->assertFileCanBeAttached(
                    $file,
                    $assessmentService
                );

                $storedFileIds[] = $file->id;

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | INVALID VALUE
            |--------------------------------------------------------------------------
            */

            throw ValidationException::withMessages([
                'services' => [
                    'Invalid file value supplied.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | RETURN CORRECT VALUE SHAPE
        |--------------------------------------------------------------------------
        */

        $storedFileIds = array_values(
            array_unique($storedFileIds)
        );

        if ($isMultiple) {
            return $storedFileIds;
        }

        return $storedFileIds[0] ?? null;
    }

    /*
    |--------------------------------------------------------------------------
    | RESOLVE UPLOADED FILE REFERENCE
    |--------------------------------------------------------------------------
    */

    /**
     * Resolve frontend multipart file reference.
     *
     * Frontend:
     * {"__file": "file__FIELD_ID__FIELD_CODE"}
     *
     * Multipart request:
     * file__FIELD_ID__FIELD_CODE = UploadedFile
     *
     * Existing-file references are resolved in storeUploadedFiles().
     */
    protected function resolveUploadedFileReference(
        mixed $value
    ): mixed {
        if ($value instanceof UploadedFile) {
            return $value;
        }

        if (! is_array($value)) {
            return $value;
        }

        /*
        |--------------------------------------------------------------------------
        | EXISTING FILE MARKER
        |--------------------------------------------------------------------------
        */

        if (array_key_exists('__existingFile', $value)) {
            $existingFileId = $value['__existingFile'];

            if (
                ! is_string($existingFileId)
                || trim($existingFileId) === ''
            ) {
                throw ValidationException::withMessages([
                    'services' => [
                        'Invalid existing file reference.',
                    ],
                ]);
            }

            return trim($existingFileId);
        }

        /*
        |--------------------------------------------------------------------------
        | MULTIPART FILE REFERENCE
        |--------------------------------------------------------------------------
        */

        if (
            array_key_exists('__file', $value)
            && is_string($value['__file'])
        ) {
            $fieldName = trim($value['__file']);

            if ($fieldName === '') {
                throw ValidationException::withMessages([
                    'services' => [
                        'The uploaded file reference cannot be empty.',
                    ],
                ]);
            }

            $uploadedFile = request()->file($fieldName);

            if (! $uploadedFile instanceof UploadedFile) {
                throw ValidationException::withMessages([
                    'services' => [
                        "The referenced uploaded file [{$fieldName}] was not found.",
                    ],
                ]);
            }

            return $uploadedFile;
        }

        /*
        |--------------------------------------------------------------------------
        | DIRECT UPLOADEDFILE INSIDE ARRAY
        |--------------------------------------------------------------------------
        */

        if (
            count($value) === 1
            && array_is_list($value)
        ) {
            $firstValue = array_values($value)[0];

            if ($firstValue instanceof UploadedFile) {
                return $firstValue;
            }
        }

        return $value;
    }

    /*
    |--------------------------------------------------------------------------
    | UPLOAD
    |--------------------------------------------------------------------------
    */

    /**
     * Upload a new assessment file.
     */
    public function uploadAssessmentFile(
        AssessmentServiceModel $assessmentService,
        UploadedFile $uploadedFile
    ): File {
        try {
            if (! $uploadedFile->isValid()) {
                throw new RuntimeException(
                    'The uploaded file is invalid.'
                );
            }

            return $this->storageService->upload(
                uploadedFile: $uploadedFile,
                folder: 'assessments',
                uploadedBy: auth()->id(),
                category: 'ASSESSMENT',
                visibility: 'private'
            );
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);

            throw new RuntimeException(
                'Failed to upload assessment file.',
                previous: $e
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | RESOLVE EXISTING FILE
    |--------------------------------------------------------------------------
    */

    /**
     * Resolve an existing file by UUID or database ID.
     */
    protected function resolveExistingFile(
        string $reference
    ): File {
        $reference = trim($reference);

        if ($reference === '') {
            throw ValidationException::withMessages([
                'services' => [
                    'File identifier cannot be empty.',
                ],
            ]);
        }

        $file = $this->storageService->find($reference);

        if (! $file) {
            throw ValidationException::withMessages([
                'services' => [
                    "The selected file [{$reference}] was not found.",
                ],
            ]);
        }

        return $file;
    }

    /*
    |--------------------------------------------------------------------------
    | ATTACH FILES
    |--------------------------------------------------------------------------
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

            $file = File::query()->find($fileId);

            if (! $file) {
                throw ValidationException::withMessages([
                    'services' => [
                        "File [{$fileId}] was not found.",
                    ],
                ]);
            }

            if (strtoupper((string) $file->status) !== 'READY') {
                throw ValidationException::withMessages([
                    'services' => [
                        "File [{$fileId}] is not ready for attachment.",
                    ],
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | ALREADY ATTACHED TO THIS VALUE
            |--------------------------------------------------------------------------
            */

            if (
                $file->fileable_type === $value->getMorphClass()
                && (string) $file->fileable_id === (string) $value->getKey()
            ) {
                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | PREVENT REASSIGNMENT
            |--------------------------------------------------------------------------
            */

            if (
                $file->fileable_id !== null
                || $file->fileable_type !== null
            ) {
                throw ValidationException::withMessages([
                    'services' => [
                        "File [{$fileId}] is already attached to another record.",
                    ],
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | ATTACH THROUGH POLYMORPHIC RELATIONSHIP
            |--------------------------------------------------------------------------
            */

            $file->fileable()->associate($value);
            $file->save();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | COLLECT FILES
    |--------------------------------------------------------------------------
    */

    /**
     * Collect all file IDs currently attached to an assessment.
     */
    public function collectAssessmentFiles(
        Assessment $assessment
    ): array {
        return AssessmentServiceValue::query()
            ->whereHas(
                'assessmentService',
                function ($query) use ($assessment): void {
                    $query->where(
                        'assessment_id',
                        $assessment->id
                    );
                }
            )
            ->with('files')
            ->get()
            ->flatMap(
                static function (
                    AssessmentServiceValue $value
                ) {
                    return $value->files->pluck('id');
                }
            )
            ->map(
                static fn ($id): string => (string) $id
            )
            ->unique()
            ->values()
            ->all();
    }

    /*
    |--------------------------------------------------------------------------
    | COLLECT EXISTING FILE VALUES
    |--------------------------------------------------------------------------
    */

    /**
     * Collect existing file values by assessment service order
     * and revenue service field ID.
     *
     * This must be called BEFORE removeAssessmentServices().
     */
    public function collectAssessmentFileValues(
        Assessment $assessment
    ): array {
        $services = AssessmentServiceModel::query()
            ->where('assessment_id', $assessment->id)
            ->with('values.files')
            ->orderBy('service_order')
            ->get();

        $result = [];

        foreach ($services as $assessmentService) {
            $fieldValues = [];

            foreach ($assessmentService->values as $value) {
                $fileIds = $value->files
                    ->pluck('id')
                    ->map(
                        static fn ($id): string => (string) $id
                    )
                    ->values()
                    ->all();

                if ($fileIds === []) {
                    continue;
                }

                $fieldId = strtolower(
                    trim((string) $value->revenue_service_field_id)
                );

                $fieldValues[$fieldId] = strtoupper(
                    (string) $value->input_type
                ) === 'MULTI_FILE'
                    ? $fileIds
                    : $fileIds[0];
            }

            if ($fieldValues !== []) {
                $result[(int) $assessmentService->service_order] = [
                    'service_id' => (string) $assessmentService->service_id,
                    'fields' => $fieldValues,
                ];
            }
        }

        return $result;
    }

    /*
    |--------------------------------------------------------------------------
    | FIND OBSOLETE FILES
    |--------------------------------------------------------------------------
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

    /*
    |--------------------------------------------------------------------------
    | REMOVE ASSESSMENT SERVICES
    |--------------------------------------------------------------------------
    */

    /**
     * Remove all services and values belonging to an assessment.
     *
     * File registry records and physical files are preserved here.
     * Only their polymorphic ownership is detached.
     * Physical cleanup is handled separately after commit.
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
                | DETACH FILES
                |--------------------------------------------------------------------------
                */

                foreach ($value->files as $file) {
                    $file->fileable()->dissociate();
                    $file->save();
                }

                /*
                |--------------------------------------------------------------------------
                | REMOVE VALUE
                |--------------------------------------------------------------------------
                */

                $value->forceDelete();
            }

            /*
            |--------------------------------------------------------------------------
            | REMOVE ASSESSMENT SERVICE
            |--------------------------------------------------------------------------
            */

            $assessmentService->forceDelete();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | CLEANUP OBSOLETE FILES
    |--------------------------------------------------------------------------
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

    /*
    |--------------------------------------------------------------------------
    | FILE REFERENCE
    |--------------------------------------------------------------------------
    */

    /**
     * Determine whether a file is still attached to a record.
     */
    protected function fileIsStillReferenced(
        File $file
    ): bool {
        return $file->fileable_type !== null
            && $file->fileable_id !== null;
    }

    /*
    |--------------------------------------------------------------------------
    | DELETE
    |--------------------------------------------------------------------------
    */

    /**
     * Delete the physical file and its database record.
     */
    protected function deleteFile(
        File $file
    ): void {
        try {
            $this->storageService->delete($file);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | ATTACHMENT VALIDATION
    |--------------------------------------------------------------------------
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
        | BASIC FILE STATE
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
        | FILE STATUS
        |--------------------------------------------------------------------------
        */

        if (strtoupper((string) $file->status) !== 'READY') {
            throw ValidationException::withMessages([
                'services' => [
                    'The selected file is not ready for attachment.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | OWNERSHIP
        |--------------------------------------------------------------------------
        |
        | Existing files can only be attached by the user who
        | originally uploaded them.
        |
        */

        if (
            $file->uploaded_by !== null
            && (string) $file->uploaded_by !== (string) auth()->id()
        ) {
            throw ValidationException::withMessages([
                'services' => [
                    'You are not allowed to attach this file.',
                ],
            ]);
        }
    }
}
