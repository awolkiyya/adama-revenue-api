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
     * Supported values:
     *
     * - UploadedFile
     * - UploadedFile[]
     * - {"__file": "multipart-field-name"}
     * - [{"__file": "multipart-field-name"}]
     * - Existing file UUID
     * - Existing file UUID[]
     *
     * FILE:
     *     Returns one file database ID.
     *
     * MULTI_FILE:
     *     Returns an array of file database IDs.
     */
    public function storeUploadedFiles(
        AssessmentServiceModel $assessmentService,
        $serviceField,
        mixed $value,
        string $inputType
    ): mixed {
        $inputType = strtoupper(
            trim($inputType)
        );

        $isMultiple = $inputType === 'MULTI_FILE';

        /*
        |--------------------------------------------------------------------------
        | Normalize Submitted Values
        |--------------------------------------------------------------------------
        |
        | FILE:
        |     One value
        |
        | MULTI_FILE:
        |     Multiple values
        |
        */

        $values = $isMultiple
            ? (is_array($value) ? $value : [$value])
            : [$value];

        $storedFileIds = [];

        foreach ($values as $fileValue) {

            /*
            |--------------------------------------------------------------------------
            | Resolve Multipart Reference
            |--------------------------------------------------------------------------
            */

            $fileValue = $this->resolveUploadedFileReference(
                $fileValue
            );

            /*
            |--------------------------------------------------------------------------
            | New Uploaded File
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
            | Existing File Reference
            |--------------------------------------------------------------------------
            */

            if (
                is_string($fileValue)
                && trim($fileValue) !== ''
            ) {
                $file = $this->resolveExistingFile(
                    $fileValue
                );

                $this->assertFileCanBeAttached(
                    $file,
                    $assessmentService
                );

                $storedFileIds[] = $file->id;

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Invalid Value
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
        | Return Correct Value Shape
        |--------------------------------------------------------------------------
        */

        if ($isMultiple) {
            return array_values(
                array_unique($storedFileIds)
            );
        }

        return $storedFileIds[0] ?? null;
    }

    /**
     * ============================================================
     * RESOLVE UPLOADED FILE REFERENCE
     * ============================================================
     */

    /**
     * Resolve frontend multipart file reference.
     *
     * Frontend:
     *
     * {
     *     "__file": "file__FIELD_ID__FIELD_CODE"
     * }
     *
     * Multipart request:
     *
     * file__FIELD_ID__FIELD_CODE = UploadedFile
     */
    protected function resolveUploadedFileReference(
        mixed $value
    ): mixed {
        /*
        |--------------------------------------------------------------------------
        | Already an UploadedFile
        |--------------------------------------------------------------------------
        */

        if ($value instanceof UploadedFile) {
            return $value;
        }

        /*
        |--------------------------------------------------------------------------
        | File Reference Object
        |--------------------------------------------------------------------------
        */

        if (is_array($value)) {

            /*
            |--------------------------------------------------------------------------
            | Single File Reference
            |--------------------------------------------------------------------------
            */

            if (
                isset($value['__file'])
                && is_string($value['__file'])
            ) {
                $fieldName = trim(
                    $value['__file']
                );

                if ($fieldName === '') {
                    throw ValidationException::withMessages([
                        'services' => [
                            'The uploaded file reference cannot be empty.',
                        ],
                    ]);
                }

                $uploadedFile = request()->file(
                    $fieldName
                );

                if (
                    ! $uploadedFile instanceof UploadedFile
                ) {
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
            | Direct UploadedFile Inside Array
            |--------------------------------------------------------------------------
            */

            if (count($value) === 1) {
                $firstValue = array_values(
                    $value
                )[0];

                if ($firstValue instanceof UploadedFile) {
                    return $firstValue;
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Nothing To Resolve
        |--------------------------------------------------------------------------
        */

        return $value;
    }

    /**
     * ============================================================
     * UPLOAD
     * ============================================================
     */

    /**
     * Upload a new assessment file.
     *
     * The actual storage operation is delegated to StorageService.
     */
    public function uploadAssessmentFile(
        AssessmentServiceModel $assessmentService,
        UploadedFile $uploadedFile
    ): File {
        try {

            /*
            |--------------------------------------------------------------------------
            | Validate Uploaded File
            |--------------------------------------------------------------------------
            */

            if (! $uploadedFile->isValid()) {
                throw new RuntimeException(
                    'The uploaded file is invalid.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | StorageService
            |--------------------------------------------------------------------------
            |
            | StorageService::upload() is responsible for:
            |
            | - generating the stored filename
            | - creating the date directory
            | - calculating SHA-256 hash
            | - storing the physical file
            | - creating the File registry record
            |
            */

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

    /**
     * ============================================================
     * RESOLVE EXISTING FILE
     * ============================================================
     */

    /**
     * Resolve an existing file by UUID or database ID.
     *
     * StorageService::find() supports both:
     *
     * - public UUID
     * - database primary key
     */
    protected function resolveExistingFile(
        string $reference
    ): File {
        $reference = trim(
            $reference
        );

        if ($reference === '') {
            throw ValidationException::withMessages([
                'services' => [
                    'File identifier cannot be empty.',
                ],
            ]);
        }

        $file = $this->storageService->find(
            $reference
        );

        if (! $file) {
            throw ValidationException::withMessages([
                'services' => [
                    "The selected file [{$reference}] was not found.",
                ],
            ]);
        }

        return $file;
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
    
            $file = File::query()->find($fileId);
    
            if (! $file) {
                throw ValidationException::withMessages([
                    'services' => [
                        "File [{$fileId}] was not found.",
                    ],
                ]);
            }
    
            if (
                strtoupper((string) $file->status) !== 'READY'
            ) {
                throw ValidationException::withMessages([
                    'services' => [
                        "File [{$fileId}] is not ready for attachment.",
                    ],
                ]);
            }
    
            /*
             * If the file is already attached to this value,
             * nothing needs to be changed.
             */
            if (
                $file->fileable_type === $value->getMorphClass()
                && (string) $file->fileable_id === (string) $value->getKey()
            ) {
                continue;
            }
    
            /*
             * Do not allow a file already owned by another model
             * to be silently reassigned.
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
             * Attach the file through the polymorphic
             * fileable relationship.
             */
            $file->fileable()->associate($value);
            $file->save();
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
        return AssessmentServiceValue::query()
            ->whereHas(
                'assessmentService',
                function ($query) use ($assessment) {
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
                    return $value->files
                        ->pluck('id');
                }
            )
            ->map(
                static fn ($id) => (string) $id
            )
            ->unique()
            ->values()
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
     * Physical files are cleaned separately afterward.
     */
    public function removeAssessmentServices(
        Assessment $assessment
    ): void {
        $services = AssessmentServiceModel::query()
            ->where(
                'assessment_id',
                $assessment->id
            )
            ->with('values.files')
            ->get();

        foreach ($services as $assessmentService) {

            foreach ($assessmentService->values as $value) {

                /*
                |--------------------------------------------------------------------------
                | Preserve File Records For Cleanup
                |--------------------------------------------------------------------------
                |
                | The File model uses a polymorphic relationship.
                | We remove the ownership here.
                |
                */

                foreach ($value->files as $file) {

                    $file->fileable()->dissociate();

                    $file->save();
                }

                /*
                |--------------------------------------------------------------------------
                | Remove Value
                |--------------------------------------------------------------------------
                */

                $value->forceDelete();
            }

            /*
            |--------------------------------------------------------------------------
            | Remove Assessment Service
            |--------------------------------------------------------------------------
            */

            $assessmentService->forceDelete();
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
            ->whereIn(
                'id',
                $fileIds
            )
            ->get();

        foreach ($files as $file) {

            /*
            |--------------------------------------------------------------------------
            | Never delete referenced files.
            |--------------------------------------------------------------------------
            */

            if (
                $this->fileIsStillReferenced(
                    $file
                )
            ) {
                continue;
            }

            $this->deleteFile(
                $file
            );
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
            ->whereIn(
                'id',
                $fileIds
            )
            ->get();

        foreach ($files as $file) {

            /*
            |--------------------------------------------------------------------------
            | Never delete referenced files.
            |--------------------------------------------------------------------------
            */

            if (
                $this->fileIsStillReferenced(
                    $file
                )
            ) {
                continue;
            }

            $this->deleteFile(
                $file
            );
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
     *
     * Delegates to StorageService::delete().
     */
    protected function deleteFile(
        File $file
    ): void {
        try {

            $this->storageService->delete(
                $file
            );

        } catch (Throwable $e) {

            /*
            |--------------------------------------------------------------------------
            | Do Not Break Main Workflow
            |--------------------------------------------------------------------------
            |
            | File cleanup is secondary to the assessment operation.
            |
            */

            report($e);
        }
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
        | File Status
        |--------------------------------------------------------------------------
        */

        if (
            strtoupper(
                (string) $file->status
            ) !== 'READY'
        ) {
            throw ValidationException::withMessages([
                'services' => [
                    'The selected file is not ready for attachment.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Ownership
        |--------------------------------------------------------------------------
        |
        | Existing files can only be attached by the user who
        | originally uploaded them.
        |
        */

        if (
            $file->uploaded_by !== null
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
