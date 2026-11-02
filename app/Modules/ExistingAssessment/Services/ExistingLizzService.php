<?php

namespace App\Modules\ExistingAssessment\Services;

use App\Models\Assessment;
use App\Models\AssessmentService;
use App\Modules\Assessment\Services\AssessmentFileService;
use App\Modules\Assessment\Services\AssessmentServiceValueService;
use App\Services\DocumentSequenceService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class ExistingLizzService
{
    public function __construct(
        protected DocumentSequenceService $documentSequenceService,
        protected AssessmentServiceValueService $assessmentServiceValueService,
        protected AssessmentFileService $assessmentFileService,
        protected ExistingLizzFinancialService $financialService,
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | CREATE
    |--------------------------------------------------------------------------
    */

    public function create(
        array $data,
        array $files = []
    ): Assessment {
        $createdFileIds = [];

        Log::info('Existing LIZZ creation started.', [
            'revenue_service_id' => $data['revenue_service_id'] ?? null,
            'service_field_count' => count($data['service_fields'] ?? []),
            'uploaded_file_count' => $this->countUploadedFiles($files),
        ]);

        try {
            return DB::transaction(function () use (
                $data,
                $files,
                &$createdFileIds
            ) {
                $user = auth()->user();

                if (! $user) {
                    throw ValidationException::withMessages([
                        'authentication' => [
                            'An authenticated user is required to register an assessment.',
                        ],
                    ]);
                }

                if (! $user->administrative_unit_id) {
                    throw ValidationException::withMessages([
                        'administrative_unit_id' => [
                            'The authenticated officer is not assigned to an administrative unit.',
                        ],
                    ]);
                }

                $financial = $this->financialService->calculate(
                    $data['original_obligation'],
                    $data['amount_already_paid']
                );

                $assessmentNumber = $this->documentSequenceService->generate(
                    sequenceType: 'assessment'
                );

                $assessment = Assessment::create([
                    'assessment_number' => $assessmentNumber,
                    'citizen_id' => $data['taxpayer_id'],
                    'administrative_unit_id' => $user->administrative_unit_id,
                    'source_type' => 'EXISTING_LIZZ',
                    'status' => 'PENDING_APPROVAL',
                    'submitted_at' => now(),
                    'notes' => $data['notes'] ?? null,
                    'created_by' => $user->id,
                    'updated_by' => $user->id,
                ]);

                $assessmentService = AssessmentService::create([
                    'assessment_id' => $assessment->id,
                    'service_id' => $data['revenue_service_id'],
                    'computed_amount' => $financial['original_obligation'],
                    'paid_amount' => $financial['amount_already_paid'],
                    'remaining_amount' => $financial['outstanding_balance'],
                    'balance_as_of_date' => $data['balance_as_of_date'],
                ]);

                /*
                 * Ensure every uploaded file belongs to the selected
                 * revenue service before processing any attachments.
                 */
                $this->validateUploadedService(
                    $files,
                    (string) $assessmentService->service_id
                );

                /*
                 * Convert multipart uploads into field values that
                 * AssessmentServiceValueService can process.
                 *
                 * File references use the existing convention:
                 * file__SERVICE_UUID__FIELD_UUID
                 */
                $serviceFields = $data['service_fields'] ?? [];

                $serviceFields = $this->mergeUploadedFilesIntoFields(
                    $serviceFields,
                    $files,
                    $createdFileIds,
                    $assessmentService
                );

                $this->storeServiceFields(
                    $assessmentService,
                    $serviceFields
                );

                Log::info('Existing LIZZ assessment created.', [
                    'assessment_id' => $assessment->id,
                    'assessment_number' => $assessment->assessment_number,
                    'service_id' => $assessmentService->service_id,
                    'uploaded_file_count' => count($createdFileIds),
                    'created_by' => $user->id,
                ]);

                return $assessment->fresh([
                    'citizen',
                    'administrativeUnit',
                    'services.service',
                    'services.values',
                    'services.paymentSchedules',
                ]);
            });
        } catch (Throwable $exception) {
            /*
             * DB rollback does not automatically remove physical files
             * uploaded through StorageService.
             */
            $this->cleanupCreatedFiles($createdFileIds);

            if ($exception instanceof ValidationException) {
                Log::warning('Existing LIZZ creation validation failed.', [
                    'errors' => $exception->errors(),
                    'user_id' => auth()->id(),
                ]);

                throw $exception;
            }

            Log::error('Existing LIZZ creation failed.', [
                'user_id' => auth()->id(),
                'revenue_service_id' => $data['revenue_service_id'] ?? null,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | UPDATE
    |--------------------------------------------------------------------------
    */

    public function update(
        Assessment $assessment,
        array $data,
        array $files = []
    ): Assessment {
        $this->ensureExistingLizz($assessment);

        if ($assessment->status !== 'RETURNED') {
            throw ValidationException::withMessages([
                'assessment' => [
                    'Only returned assessments can be updated.',
                ],
            ]);
        }

        $user = auth()->user();

        if (! $user) {
            throw ValidationException::withMessages([
                'authentication' => [
                    'An authenticated user is required to update an assessment.',
                ],
            ]);
        }

        if (! $user->administrative_unit_id) {
            throw ValidationException::withMessages([
                'administrative_unit_id' => [
                    'The authenticated officer is not assigned to an administrative unit.',
                ],
            ]);
        }

        $createdFileIds = [];

        try {
            return DB::transaction(function () use (
                $assessment,
                $data,
                $files,
                $user,
                &$createdFileIds
            ) {
                /*
                 * Lock the assessment while processing the returned
                 * assessment update.
                 */
                $assessment = Assessment::query()
                    ->whereKey($assessment->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->ensureExistingLizz($assessment);

                if ($assessment->status !== 'RETURNED') {
                    throw ValidationException::withMessages([
                        'assessment' => [
                            'Only returned assessments can be updated.',
                        ],
                    ]);
                }

                $assessmentData = [
                    'administrative_unit_id' => $user->administrative_unit_id,
                    'updated_by' => $user->id,
                ];

                if (array_key_exists('taxpayer_id', $data)) {
                    $assessmentData['citizen_id'] = $data['taxpayer_id'];
                }

                if (array_key_exists('notes', $data)) {
                    $assessmentData['notes'] = $data['notes'];
                }

                $assessment->update($assessmentData);

                $assessmentService = $this->getPrimaryAssessmentService(
                    $assessment
                );

                if (array_key_exists('revenue_service_id', $data)) {
                    $assessmentService->update([
                        'service_id' => $data['revenue_service_id'],
                    ]);

                    $assessmentService->refresh();
                }

                $hasFinancialUpdate =
                    array_key_exists('original_obligation', $data)
                    || array_key_exists('amount_already_paid', $data)
                    || array_key_exists('balance_as_of_date', $data);

                if ($hasFinancialUpdate) {
                    $financial = $this->financialService->calculate(
                        $data['original_obligation']
                            ?? $assessmentService->computed_amount,
                        $data['amount_already_paid']
                            ?? $assessmentService->paid_amount
                    );

                    $assessmentService->update([
                        'computed_amount' => $financial['original_obligation'],
                        'paid_amount' => $financial['amount_already_paid'],
                        'remaining_amount' => $financial['outstanding_balance'],
                        'balance_as_of_date' =>
                            $data['balance_as_of_date']
                            ?? $assessmentService->balance_as_of_date,
                    ]);
                }

                $this->validateUploadedService(
                    $files,
                    (string) $assessmentService->service_id
                );

                $serviceFields = $data['service_fields'] ?? [];

                $serviceFields = $this->mergeUploadedFilesIntoFields(
                    $serviceFields,
                    $files,
                    $createdFileIds,
                    $assessmentService
                );

                if ($serviceFields !== []) {
                    $this->storeServiceFields(
                        $assessmentService,
                        $serviceFields
                    );
                }

                /*
                 * Resubmit only after the requested changes succeed.
                 */
                $assessment->update([
                    'status' => 'PENDING_APPROVAL',
                    'submitted_at' => now(),
                    'updated_by' => $user->id,
                ]);

                Log::info(
                    'Existing LIZZ assessment resubmitted for approval.',
                    [
                        'assessment_id' => $assessment->id,
                        'assessment_number' => $assessment->assessment_number,
                        'uploaded_file_count' => count($createdFileIds),
                        'updated_by' => $user->id,
                    ]
                );

                return $assessment->fresh([
                    'citizen',
                    'administrativeUnit',
                    'services.service',
                    'services.values',
                    'services.paymentSchedules',
                ]);
            });
        } catch (Throwable $exception) {
            $this->cleanupCreatedFiles($createdFileIds);

            if ($exception instanceof ValidationException) {
                Log::warning('Existing LIZZ update validation failed.', [
                    'assessment_id' => $assessment->id,
                    'errors' => $exception->errors(),
                ]);

                throw $exception;
            }

            Log::error('Existing LIZZ update failed.', [
                'assessment_id' => $assessment->id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | FIND
    |--------------------------------------------------------------------------
    */

    public function find(Assessment $assessment): Assessment
    {
        $this->ensureExistingLizz($assessment);

        return $assessment->load([
            'citizen',
            'administrativeUnit',
            'services.service',
            'services.values',
            'services.paymentSchedules',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | LIST
    |--------------------------------------------------------------------------
    */

    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = Assessment::query()
            ->where('source_type', 'EXISTING_LIZZ')
            ->with([
                'citizen',
                'administrativeUnit',
                'services.service',
                'services.values',
                'services.paymentSchedules',
            ]);

        if (! empty($filters['taxpayer_id'])) {
            $query->where('citizen_id', $filters['taxpayer_id']);
        }

        if (! empty($filters['administrative_unit_id'])) {
            $query->where(
                'administrative_unit_id',
                $filters['administrative_unit_id']
            );
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['search'])) {
            $search = trim($filters['search']);

            $query->where(function ($q) use ($search) {
                $q->where(
                    'assessment_number',
                    'like',
                    "%{$search}%"
                )->orWhereHas('citizen', function ($citizenQuery) use ($search) {
                    $citizenQuery
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('tin', 'like', "%{$search}%");
                });
            });
        }

        $perPage = min(
            max((int) ($filters['per_page'] ?? 15), 1),
            100
        );

        return $query
            ->latest('assessment_date')
            ->paginate($perPage);
    }

    /*
    |--------------------------------------------------------------------------
    | SERVICE FIELD VALUES
    |--------------------------------------------------------------------------
    */

    protected function storeServiceFields(
        AssessmentService $assessmentService,
        array $serviceFields
    ): void {
        if ($serviceFields === []) {
            return;
        }

        $this->assessmentServiceValueService->storeServiceValuesByFieldId(
            $assessmentService,
            $serviceFields
        );
    }

    /*
    |--------------------------------------------------------------------------
    | MERGE UPLOADED FILES INTO FIELD VALUES
    |--------------------------------------------------------------------------
    */

    /**
     * Upload files through AssessmentFileService and return field values
     * that reference the resulting File records.
     *
     * Expected upload structure:
     *
     * [
     *     'SERVICE_UUID' => [
     *         'FIELD_UUID' => UploadedFile,
     *     ],
     * ]
     *
     * This method assumes storeServiceValuesByFieldId() accepts a
     * FILE field's uploaded file reference and invokes AssessmentFileService
     * to attach it to the generated AssessmentServiceValue.
     *
     * @param array<string, mixed> $serviceFields
     * @param array<string, mixed> $files
     * @param array<int, string|int> $createdFileIds
     * @return array<string, mixed>
     */
    protected function mergeUploadedFilesIntoFields(
        array $serviceFields,
        array $files,
        array &$createdFileIds,
        AssessmentService $assessmentService
    ): array {
        foreach ($files as $serviceId => $serviceFiles) {
            foreach ($serviceFiles as $fieldId => $uploadedFile) {
                if (! $uploadedFile instanceof UploadedFile) {
                    throw ValidationException::withMessages([
                        "file__{$serviceId}__{$fieldId}" => [
                            'The uploaded file is invalid.',
                        ],
                    ]);
                }

                /*
                 * Upload and register the file through the shared
                 * assessment file service.
                 */
                $file = $this->assessmentFileService->uploadAssessmentFile(
                    $assessmentService,
                    $uploadedFile
                );

                $createdFileIds[] = $file->getKey();

                /*
                 * Pass the File ID to the value service.
                 * The value service must recognize FILE fields and
                 * attach this File to the corresponding field value.
                 */
                $serviceFields[$fieldId] = (string) $file->getKey();
            }
        }

        return $serviceFields;
    }

    /*
    |--------------------------------------------------------------------------
    | VALIDATE UPLOADED SERVICE
    |--------------------------------------------------------------------------
    */

    /**
     * Ensure uploaded files belong to the assessment service being saved.
     *
     * @param array<string, mixed> $files
     */
    protected function validateUploadedService(
        array $files,
        string $expectedServiceId
    ): void {
        foreach ($files as $serviceId => $serviceFiles) {
            if ((string) $serviceId !== $expectedServiceId) {
                throw ValidationException::withMessages([
                    "file__{$serviceId}" => [
                        'The uploaded attachment does not belong to the selected revenue service.',
                    ],
                ]);
            }

            if (! is_array($serviceFiles)) {
                throw ValidationException::withMessages([
                    "file__{$serviceId}" => [
                        'The uploaded file collection is invalid.',
                    ],
                ]);
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | FILE CLEANUP
    |--------------------------------------------------------------------------
    */

    /**
     * Remove newly created file records and physical files after failure.
     *
     * The AssessmentFileService skips files still referenced by an
     * assessment-service value.
     *
     * @param array<int, string|int> $fileIds
     */
    protected function cleanupCreatedFiles(array $fileIds): void
    {
        if ($fileIds === []) {
            return;
        }

        try {
            $this->assessmentFileService->cleanupCreatedFiles(
                array_values(array_unique($fileIds))
            );
        } catch (Throwable $exception) {
            Log::error(
                'Failed to clean up files created during Existing LIZZ processing.',
                [
                    'file_ids' => $fileIds,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | COUNT UPLOADS
    |--------------------------------------------------------------------------
    */

    protected function countUploadedFiles(array $files): int
    {
        $count = 0;

        foreach ($files as $serviceFiles) {
            if (is_array($serviceFiles)) {
                $count += count($serviceFiles);
            }
        }

        return $count;
    }

    /*
    |--------------------------------------------------------------------------
    | PRIMARY SERVICE
    |--------------------------------------------------------------------------
    */

    protected function getPrimaryAssessmentService(
        Assessment $assessment
    ): AssessmentService {
        $assessmentService = $assessment->services()->first();

        if (! $assessmentService) {
            throw ValidationException::withMessages([
                'revenue_service_id' => [
                    'The existing LIZZ assessment does not have an assessment service.',
                ],
            ]);
        }

        return $assessmentService;
    }

    /*
    |--------------------------------------------------------------------------
    | EXISTING LIZZ GUARD
    |--------------------------------------------------------------------------
    */

    protected function ensureExistingLizz(
        Assessment $assessment
    ): void {
        if ($assessment->source_type !== 'EXISTING_LIZZ') {
            throw ValidationException::withMessages([
                'assessment' => [
                    'The selected assessment is not an existing LIZZ assessment.',
                ],
            ]);
        }
    }
}