<?php

namespace App\Modules\Assessment\Services;

use App\Models\Assessment;
use App\Models\AssessmentService as AssessmentServiceModel;
use App\Models\AssessmentServiceValue;
use App\Models\RevenueService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class AssessmentServiceValueService
{
    public function __construct(
        protected AssessmentFileService $fileService,
    ) {
    }

    /**
     * Store assessment services and their dynamic field values.
     *
     * Existing file values must be collected before old assessment
     * services are removed.
     */
    public function storeServices(
        Assessment $assessment,
        array $services,
        array $existingFileValues = []
    ): void {
        Log::info('Assessment services persistence started.', [
            'assessment_id' => $assessment->id,
            'service_count' => count($services),
            'existing_file_service_count' => count($existingFileValues),
        ]);

        foreach ($services as $index => $serviceData) {
            if (! is_array($serviceData)) {
                throw ValidationException::withMessages([
                    'services' => [
                        'Each assessment service must be a valid object.',
                    ],
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Resolve Revenue Service
            |--------------------------------------------------------------------------
            */

            $service = $this->resolveRevenueService($serviceData);

            /*
            |--------------------------------------------------------------------------
            | Submitted Fields
            |--------------------------------------------------------------------------
            */

            $submittedFields = $serviceData['fields'] ?? [];

            if (! is_array($submittedFields)) {
                throw ValidationException::withMessages([
                    "services.{$index}.fields" => [
                        'The fields value must be an object.',
                    ],
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Resolve Existing Files For This Service
            |--------------------------------------------------------------------------
            */

            $serviceOrder = $index + 1;

            $existingServiceFiles =
                $existingFileValues[$serviceOrder] ?? [];

            if (
                ! is_array($existingServiceFiles)
                || strtolower(trim((string) (
                    $existingServiceFiles['service_id'] ?? ''
                ))) !== strtolower(trim((string) $service->id))
            ) {
                $existingServiceFiles = [];
            }

            $existingFields = $existingServiceFiles['fields'] ?? [];

            if (! is_array($existingFields)) {
                $existingFields = [];
            }

            /*
            |--------------------------------------------------------------------------
            | Create Assessment Service
            |--------------------------------------------------------------------------
            */

            $assessmentService = AssessmentServiceModel::create([
                'id' => (string) Str::uuid(),
                'assessment_id' => $assessment->id,
                'service_id' => $service->id,
                'service_code' => $service->revenueCode?->code,
                'service_order' => $serviceOrder,
                'status' => 'CAPTURED',
                'computed_amount' => null,
                'currency_code' => null,
                'calculation_metadata' => null,
                'calculation_error' => null,
                'calculated_at' => null,
            ]);

            Log::info('Assessment service created.', [
                'assessment_id' => $assessment->id,
                'assessment_service_id' => $assessmentService->id,
                'revenue_service_id' => $service->id,
                'service_code' => $service->revenueCode?->code,
                'service_order' => $serviceOrder,
                'field_count' => count($submittedFields),
                'existing_file_field_count' => count($existingFields),
            ]);

            /*
            |--------------------------------------------------------------------------
            | Store Dynamic Fields
            |--------------------------------------------------------------------------
            */

            $this->storeServiceValuesByFieldId(
                $assessmentService,
                $submittedFields,
                $existingFields
            );
        }

        Log::info('Assessment services persistence completed.', [
            'assessment_id' => $assessment->id,
            'service_count' => count($services),
            'stored_service_count' => $assessment->services()->count(),
        ]);
    }

    /**
     * Store dynamic service field values using RevenueServiceField IDs.
     */
    public function storeServiceValuesByFieldId(
        AssessmentServiceModel $assessmentService,
        array $submittedFields,
        array $existingFileFields = []
    ): void {
        Log::info('Assessment service field persistence started by field ID.', [
            'assessment_service_id' => $assessmentService->id,
            'revenue_service_id' => $assessmentService->service_id,
            'submitted_field_count' => count($submittedFields),
            'existing_file_field_count' => count($existingFileFields),
        ]);

        /*
        |--------------------------------------------------------------------------
        | Resolve Revenue Service
        |--------------------------------------------------------------------------
        */

        $service = $this->resolveAssessmentService($assessmentService);

        /*
        |--------------------------------------------------------------------------
        | Active Revenue Service Fields
        |--------------------------------------------------------------------------
        */

        $activeFields = $this->getActiveServiceFields($service);

        /*
        |--------------------------------------------------------------------------
        | Build Field ID Lookup
        |--------------------------------------------------------------------------
        */

        $fields = $activeFields->keyBy(
            fn ($serviceField): string => strtolower(
                trim((string) $serviceField->id)
            )
        );

        /*
        |--------------------------------------------------------------------------
        | Normalize Submitted Field IDs
        |--------------------------------------------------------------------------
        |
        | UUID comparisons must be case-insensitive.
        | This also prevents duplicate field IDs with different casing.
        |
        */

        $submittedFields = $this->normalizeSubmittedFieldKeys(
            $submittedFields
        );

        /*
        |--------------------------------------------------------------------------
        | Normalize Existing File Field IDs
        |--------------------------------------------------------------------------
        */

        $existingFileFields = $this->normalizeExistingFileFieldKeys(
            $existingFileFields
        );

        /*
        |--------------------------------------------------------------------------
        | Preserve Existing File Values
        |--------------------------------------------------------------------------
        */

        $submittedFields = $this->preserveExistingFileValues(
            $submittedFields,
            $existingFileFields,
            $fields,
            $assessmentService
        );

        Log::debug('Revenue service field configuration loaded.', [
            'assessment_service_id' => $assessmentService->id,
            'revenue_service_id' => $service->id,
            'configured_field_count' => $fields->count(),
            'submitted_field_ids' => array_keys($submittedFields),
        ]);

        /*
        |--------------------------------------------------------------------------
        | Persist Values
        |--------------------------------------------------------------------------
        */

        $this->persistServiceValues(
            $assessmentService,
            $fields,
            $submittedFields
        );

        /*
        |--------------------------------------------------------------------------
        | Synchronize Agreement Date
        |--------------------------------------------------------------------------
        */

        $this->syncAgreementDate($assessmentService);

        $freshAssessmentService = $assessmentService->fresh();

        Log::info('Assessment service field persistence completed.', [
            'assessment_service_id' => $assessmentService->id,
            'submitted_field_count' => count($submittedFields),
            'stored_value_count' => $assessmentService->values()->count(),
            'agreement_date' => $freshAssessmentService
                ?->agreement_date
                ?->toDateString(),
        ]);
    }

    /**
     * Normalize submitted field IDs to lowercase.
     */
    protected function normalizeSubmittedFieldKeys(
        array $submittedFields
    ): array {
        $normalized = [];

        foreach ($submittedFields as $fieldId => $value) {
            $normalizedFieldId = strtolower(
                trim((string) $fieldId)
            );

            if ($normalizedFieldId === '') {
                throw ValidationException::withMessages([
                    'services' => [
                        'Every submitted field must have a valid field ID.',
                    ],
                ]);
            }

            if (array_key_exists($normalizedFieldId, $normalized)) {
                throw ValidationException::withMessages([
                    'services' => [
                        "Duplicate field ID [{$normalizedFieldId}] was submitted.",
                    ],
                ]);
            }

            $normalized[$normalizedFieldId] = $value;
        }

        return $normalized;
    }

    /**
     * Normalize existing file mapping keys to lowercase.
     */
    protected function normalizeExistingFileFieldKeys(
        array $existingFileFields
    ): array {
        $normalized = [];

        foreach ($existingFileFields as $fieldId => $value) {
            $normalizedFieldId = strtolower(
                trim((string) $fieldId)
            );

            if ($normalizedFieldId === '') {
                continue;
            }

            $normalized[$normalizedFieldId] = $value;
        }

        return $normalized;
    }

    /**
     * Restore existing FILE/MULTI_FILE values when no replacement
     * was submitted for the corresponding field.
     */
    protected function preserveExistingFileValues(
        array $submittedFields,
        array $existingFileFields,
        $fields,
        AssessmentServiceModel $assessmentService
    ): array {
        foreach ($fields as $normalizedFieldId => $serviceField) {
            $types = $this->resolveFieldTypes($serviceField);

            if (! in_array(
                $types['input_type'],
                ['FILE', 'MULTI_FILE'],
                true
            )) {
                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Find Existing File Value
            |--------------------------------------------------------------------------
            */

            if (! array_key_exists(
                $normalizedFieldId,
                $existingFileFields
            )) {
                continue;
            }

            $existingFileValue =
                $existingFileFields[$normalizedFieldId];

            if ($existingFileValue === null) {
                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Determine Whether A New File Was Submitted
            |--------------------------------------------------------------------------
            */

            $hasSubmittedValue = array_key_exists(
                $normalizedFieldId,
                $submittedFields
            );

            $submittedValue = $hasSubmittedValue
                ? $submittedFields[$normalizedFieldId]
                : null;

            $hasNewValue = $hasSubmittedValue
                && $submittedValue !== null
                && $submittedValue !== ''
                && $submittedValue !== [];

            if ($hasNewValue) {
                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Normalize Existing File References
            |--------------------------------------------------------------------------
            */

            if ($types['input_type'] === 'MULTI_FILE') {
                $existingFileIds = is_array($existingFileValue)
                    ? array_values($existingFileValue)
                    : [$existingFileValue];

                $existingFileIds = array_values(
                    array_filter(
                        $existingFileIds,
                        static fn ($fileId): bool =>
                            is_string($fileId)
                            && trim($fileId) !== ''
                    )
                );

                if ($existingFileIds === []) {
                    continue;
                }

                $submittedFields[$normalizedFieldId] = $existingFileIds;
            } else {
                /*
                |--------------------------------------------------------------------------
                | FILE Must Contain Exactly One Existing File
                |--------------------------------------------------------------------------
                |
                | An array containing one ID is normalized to a scalar.
                | Multiple IDs are rejected instead of silently choosing
                | one and potentially losing an attachment.
                |
                */

                if (is_array($existingFileValue)) {
                    $existingFileIds = array_values(
                        array_filter(
                            $existingFileValue,
                            static fn ($fileId): bool =>
                                is_string($fileId)
                                && trim($fileId) !== ''
                        )
                    );

                    if (count($existingFileIds) > 1) {
                        Log::error(
                            'Multiple existing files found for a single FILE field.',
                            [
                                'assessment_service_id' => $assessmentService->id,
                                'revenue_service_field_id' => $serviceField->id,
                                'existing_file_count' => count($existingFileIds),
                            ]
                        );

                        throw ValidationException::withMessages([
                            'services' => [
                                "The FILE field [{$serviceField->id}] has multiple existing attachments. It must contain exactly one file.",
                            ],
                        ]);
                    }

                    $existingFileValue = $existingFileIds[0] ?? null;
                }

                if (
                    ! is_string($existingFileValue)
                    || trim($existingFileValue) === ''
                ) {
                    continue;
                }

                $submittedFields[$normalizedFieldId] =
                    trim($existingFileValue);
            }

            Log::debug('Existing assessment file value preserved.', [
                'assessment_service_id' => $assessmentService->id,
                'revenue_service_field_id' => $serviceField->id,
                'input_type' => $types['input_type'],
                'preserved_file_count' =>
                    $types['input_type'] === 'MULTI_FILE'
                        ? count($submittedFields[$normalizedFieldId])
                        : 1,
            ]);
        }

        return $submittedFields;
    }

    /**
     * Synchronize the captured AGREEMENT_DATE field with the
     * dedicated agreement_date column.
     */
    protected function syncAgreementDate(
        AssessmentServiceModel $assessmentService
    ): void {
        $agreementDateValue = $assessmentService
            ->values()
            ->whereHas(
                'revenueServiceField.baseField',
                fn ($query) => $query->where('code', 'AGREEMENT_DATE')
            )
            ->value('value');

        if (
            $agreementDateValue === null
            || (
                is_string($agreementDateValue)
                && trim($agreementDateValue) === ''
            )
        ) {
            if ($assessmentService->agreement_date !== null) {
                $assessmentService->agreement_date = null;
                $assessmentService->save();
            }

            return;
        }

        try {
            $agreementDate = Carbon::parse(
                $agreementDateValue
            )->toDateString();
        } catch (Throwable $exception) {
            Log::warning(
                'Assessment service agreement date synchronization failed.',
                [
                    'assessment_service_id' => $assessmentService->id,
                    'exception' => $exception::class,
                ]
            );

            throw ValidationException::withMessages([
                'services' => [
                    'The agreement date must be a valid date.',
                ],
            ]);
        }

        if (
            $assessmentService->agreement_date?->toDateString()
            !== $agreementDate
        ) {
            $assessmentService->agreement_date = $agreementDate;
            $assessmentService->save();
        }

        Log::debug('Assessment service agreement date synchronized.', [
            'assessment_service_id' => $assessmentService->id,
            'agreement_date' => $agreementDate,
        ]);
    }

    /**
     * Resolve the RevenueService associated with an assessment service.
     */
    protected function resolveAssessmentService(
        AssessmentServiceModel $assessmentService
    ): RevenueService {
        $service = RevenueService::query()
            ->with([
                'revenueCode',
                'fields.baseField.options',
            ])
            ->find($assessmentService->service_id);

        if (! $service) {
            Log::error('Revenue service could not be resolved.', [
                'assessment_service_id' => $assessmentService->id,
                'revenue_service_id' => $assessmentService->service_id,
            ]);

            throw ValidationException::withMessages([
                'services' => [
                    "Revenue service [{$assessmentService->service_id}] was not found.",
                ],
            ]);
        }

        return $service;
    }

    /**
     * Resolve active fields configured for a revenue service.
     */
    protected function getActiveServiceFields(
        RevenueService $service
    ) {
        $activeFields = $service->fields
            ->filter(
                function ($serviceField): bool {
                    return $serviceField->is_active
                        && $serviceField->baseField
                        && $serviceField->baseField->is_active;
                }
            )
            ->values();

        Log::debug('Active revenue service fields resolved.', [
            'revenue_service_id' => $service->id,
            'total_configured_fields' => $service->fields->count(),
            'active_field_count' => $activeFields->count(),
            'inactive_or_invalid_field_count' =>
                $service->fields->count() - $activeFields->count(),
        ]);

        return $activeFields;
    }

    /**
     * Persist all submitted field values.
     */
    protected function persistServiceValues(
        AssessmentServiceModel $assessmentService,
        $fields,
        array $submittedFields
    ): void {
        $resolvedCount = 0;
        $createdCount = 0;

        Log::debug('Assessment service value persistence engine started.', [
            'assessment_service_id' => $assessmentService->id,
            'submitted_count' => count($submittedFields),
            'configured_count' => $fields->count(),
        ]);

        /*
        |--------------------------------------------------------------------------
        | Delete Existing Values
        |--------------------------------------------------------------------------
        */

        if (! empty($submittedFields)) {
            $deletedCount = $assessmentService
                ->values()
                ->delete();

            Log::debug('Existing assessment service values cleared.', [
                'assessment_service_id' => $assessmentService->id,
                'deleted_count' => $deletedCount,
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Process Submitted Fields
        |--------------------------------------------------------------------------
        */

        foreach ($submittedFields as $fieldId => $rawValue) {
            $normalizedFieldId = strtolower(
                trim((string) $fieldId)
            );

            if (! preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
                $normalizedFieldId
            )) {
                throw ValidationException::withMessages([
                    'services' => [
                        "Invalid RevenueServiceField ID [{$normalizedFieldId}].",
                    ],
                ]);
            }

            if (! $fields->has($normalizedFieldId)) {
                Log::warning(
                    'Assessment service field rejected: field does not belong to the active service configuration.',
                    [
                        'assessment_service_id' => $assessmentService->id,
                        'revenue_service_id' => $assessmentService->service_id,
                        'field_id' => $normalizedFieldId,
                    ]
                );

                throw ValidationException::withMessages([
                    'services' => [
                        "Field [{$normalizedFieldId}] does not belong to the selected revenue service.",
                    ],
                ]);
            }

            $serviceField = $fields->get($normalizedFieldId);

            $resolvedCount++;

            $baseField = $serviceField->baseField;

            if (! $baseField) {
                throw ValidationException::withMessages([
                    'services' => [
                        "The configured field [{$serviceField->id}] does not have a base field.",
                    ],
                ]);
            }

            $types = $this->resolveFieldTypes($serviceField);

            /*
            |--------------------------------------------------------------------------
            | FILE / MULTI_FILE
            |--------------------------------------------------------------------------
            */

            if (in_array(
                $types['input_type'],
                ['FILE', 'MULTI_FILE'],
                true
            )) {
                Log::debug('Assessment file input diagnostic.', [
                    'assessment_service_id' => $assessmentService->id,
                    'revenue_service_field_id' => $serviceField->id,
                    'input_type' => $types['input_type'],
                    'raw_value_type' => get_debug_type($rawValue),
                    'raw_value' => $rawValue,
                ]);

                try {
                    $value = $this->fileService->storeUploadedFiles(
                        $assessmentService,
                        $serviceField,
                        $rawValue,
                        $types['input_type']
                    );

                    $displayValue = $this->makeDisplayValue(
                        $value,
                        $types,
                        $serviceField
                    );

                    $assessmentServiceValue = AssessmentServiceValue::create([
                        'id' => (string) Str::uuid(),
                        'assessment_service_id' => $assessmentService->id,
                        'revenue_service_field_id' => $serviceField->id,
                        'field_code' => strtoupper(
                            trim((string) $baseField->code)
                        ),
                        'field_label' => $serviceField->label
                            ?: $baseField->label,
                        'data_type' => $types['data_type'],
                        'input_type' => $types['input_type'],
                        'value' => $value,
                        'display_value' => $displayValue,
                        'measurement_unit_id' =>
                            $serviceField->measurement_unit_id
                            ?? $baseField->measurement_unit_id
                            ?? null,
                        'sort_order' =>
                            $serviceField->sort_order
                            ?? $baseField->sort_order
                            ?? 0,
                    ]);

                    $this->fileService->attachValueFiles(
                        $assessmentServiceValue,
                        $value
                    );

                    $createdCount++;
                } catch (Throwable $exception) {
                    Log::error(
                        'Assessment service file value creation failed.',
                        [
                            'assessment_service_id' => $assessmentService->id,
                            'revenue_service_field_id' => $serviceField->id,
                            'input_type' => $types['input_type'],
                            'raw_value_type' => get_debug_type($rawValue),
                            'exception' => $exception::class,
                            'message' => $exception->getMessage(),
                        ]
                    );

                    throw $exception;
                }

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | NORMAL VALUE
            |--------------------------------------------------------------------------
            */

            $value = $this->normalizeFieldValue(
                $rawValue,
                $types['data_type'],
                $types['input_type'],
                $serviceField
            );

            $displayValue = $this->makeDisplayValue(
                $value,
                $types,
                $serviceField
            );

            AssessmentServiceValue::create([
                'id' => (string) Str::uuid(),
                'assessment_service_id' => $assessmentService->id,
                'revenue_service_field_id' => $serviceField->id,
                'field_code' => strtoupper(
                    trim((string) $baseField->code)
                ),
                'field_label' => $serviceField->label
                    ?: $baseField->label,
                'data_type' => $types['data_type'],
                'input_type' => $types['input_type'],
                'value' => $value,
                'display_value' => $displayValue,
                'measurement_unit_id' =>
                    $serviceField->measurement_unit_id
                    ?? $baseField->measurement_unit_id
                    ?? null,
                'sort_order' =>
                    $serviceField->sort_order
                    ?? $baseField->sort_order
                    ?? 0,
            ]);

            $createdCount++;
        }

        $storedCount = $assessmentService
            ->values()
            ->count();

        Log::info('Assessment service values persistence completed.', [
            'assessment_service_id' => $assessmentService->id,
            'submitted_count' => count($submittedFields),
            'configured_count' => $fields->count(),
            'resolved_count' => $resolvedCount,
            'created_count' => $createdCount,
            'stored_count' => $storedCount,
        ]);
    }

    /**
     * Resolve and validate the submitted revenue service.
     */
    protected function resolveRevenueService(
        array $serviceData
    ): RevenueService {
        $serviceId = $serviceData['serviceId'] ?? null;

        if (! $serviceId) {
            throw ValidationException::withMessages([
                'services' => [
                    'Each assessment service must contain a serviceId.',
                ],
            ]);
        }

        $service = RevenueService::query()
            ->with([
                'revenueCode',
                'fields.baseField.options',
            ])
            ->find($serviceId);

        if (! $service) {
            throw ValidationException::withMessages([
                'services' => [
                    "Revenue service [{$serviceId}] was not found.",
                ],
            ]);
        }

        $expectedCode = $service->revenueCode?->code;

        if ($expectedCode === null) {
            throw ValidationException::withMessages([
                'services' => [
                    "Revenue service [{$serviceId}] does not have a valid revenue code.",
                ],
            ]);
        }

        $submittedCode = $serviceData['serviceCode'] ?? null;

        if ($submittedCode !== null) {
            $submittedCode = trim((string) $submittedCode);
            $expectedCode = trim((string) $expectedCode);

            if (strtoupper($submittedCode) !== strtoupper($expectedCode)) {
                Log::warning('Revenue service code validation failed.', [
                    'revenue_service_id' => $serviceId,
                    'submitted_code' => $submittedCode,
                    'expected_code' => $expectedCode,
                ]);

                throw ValidationException::withMessages([
                    'services' => [
                        "The serviceCode does not match serviceId [{$serviceId}].",
                    ],
                ]);
            }
        }

        return $service;
    }

    /**
     * Resolve effective data and input types for a service field.
     */
    protected function resolveFieldTypes(
        $serviceField
    ): array {
        $baseField = $serviceField->baseField;

        if (! $baseField) {
            throw ValidationException::withMessages([
                'services' => [
                    "The field [{$serviceField->id}] does not have a base field.",
                ],
            ]);
        }

        $dataType = strtoupper(
            trim(
                (string) (
                    $serviceField->data_type
                    ?? $baseField->data_type
                    ?? 'TEXT'
                )
            )
        );

        $inputType = strtoupper(
            trim(
                (string) (
                    $serviceField->input_type
                    ?? $baseField->input_type
                    ?? ''
                )
            )
        );

        if ($inputType === '') {
            $inputType = match ($dataType) {
                'SELECT' => 'SELECT',
                'BOOLEAN' => 'TEXT',
                'NUMBER' => 'NUMBER',
                'DECIMAL' => 'DECIMAL',
                'DATE' => 'DATE',
                default => 'TEXT',
            };
        }

        if (in_array($dataType, ['FILE', 'MULTI_FILE'], true)) {
            $inputType = $dataType;
            $dataType = 'TEXT';
        }

        $allowedDataTypes = [
            'NUMBER',
            'DECIMAL',
            'TEXT',
            'BOOLEAN',
            'DATE',
            'SELECT',
        ];

        $allowedInputTypes = [
            'TEXT',
            'NUMBER',
            'DECIMAL',
            'SELECT',
            'RADIO',
            'CHECKBOX',
            'DATE',
            'TEXTAREA',
            'FILE',
            'MULTI_FILE',
        ];

        if (! in_array($dataType, $allowedDataTypes, true)) {
            throw ValidationException::withMessages([
                'services' => [
                    "Unsupported data type [{$dataType}].",
                ],
            ]);
        }

        if (! in_array($inputType, $allowedInputTypes, true)) {
            throw ValidationException::withMessages([
                'services' => [
                    "Unsupported input type [{$inputType}].",
                ],
            ]);
        }

        return [
            'data_type' => $dataType,
            'input_type' => $inputType,
        ];
    }

    /**
     * Normalize a submitted dynamic field value.
     */
    protected function normalizeFieldValue(
        mixed $value,
        string $dataType,
        string $inputType,
        $serviceField
    ): mixed {
        if ($value === null || $value === '') {
            return null;
        }

        if (
            $dataType === 'SELECT'
            || in_array($inputType, ['SELECT', 'RADIO'], true)
        ) {
            return $this->normalizeOptionValue($value, $serviceField);
        }

        if ($inputType === 'CHECKBOX') {
            return $this->normalizeCheckboxValue($value, $serviceField);
        }

        return match ($dataType) {
            'NUMBER' => $this->normalizeNumber($value),
            'DECIMAL' => $this->normalizeDecimal($value),
            'BOOLEAN' => $this->normalizeBoolean($value),
            'DATE' => $this->normalizeDate($value),
            'TEXT' => $this->normalizeText($value),
            'SELECT' => $this->normalizeOptionValue($value, $serviceField),
            default => $value,
        };
    }

    /**
     * Validate and normalize a single SELECT/RADIO option.
     */
    protected function normalizeOptionValue(
        mixed $value,
        $serviceField
    ): string {
        if (is_array($value) || is_object($value)) {
            throw ValidationException::withMessages([
                'services' => [
                    'The selected value must be a single option.',
                ],
            ]);
        }

        $submittedValue = trim((string) $value);

        if ($submittedValue === '') {
            return '';
        }

        $baseField = $serviceField->baseField;

        if (! $baseField) {
            throw ValidationException::withMessages([
                'services' => [
                    "The field [{$serviceField->id}] does not have a base field.",
                ],
            ]);
        }

        $matchingOption = $baseField->options->first(
            fn ($option): bool =>
                strtoupper(trim((string) $option->value))
                === strtoupper($submittedValue)
        );

        if (! $matchingOption) {
            throw ValidationException::withMessages([
                'services' => [
                    "Invalid option [{$submittedValue}] for field [{$baseField->code}].",
                ],
            ]);
        }

        return (string) $matchingOption->value;
    }

    /**
     * Validate and normalize CHECKBOX values.
     */
    protected function normalizeCheckboxValue(
        mixed $value,
        $serviceField
    ): array {
        if (! is_array($value)) {
            throw ValidationException::withMessages([
                'services' => [
                    'The checkbox value must be an array.',
                ],
            ]);
        }

        $baseField = $serviceField->baseField;

        if (! $baseField) {
            throw ValidationException::withMessages([
                'services' => [
                    "The field [{$serviceField->id}] does not have a base field.",
                ],
            ]);
        }

        $validValues = $baseField->options
            ->map(
                static fn ($option): string => strtoupper(
                    trim((string) $option->value)
                )
            )
            ->all();

        $normalizedValues = [];

        foreach ($value as $item) {
            if (is_array($item) || is_object($item)) {
                throw ValidationException::withMessages([
                    'services' => [
                        'Each checkbox value must be a scalar option.',
                    ],
                ]);
            }

            $normalizedItem = trim((string) $item);

            if ($normalizedItem === '') {
                continue;
            }

            if (! in_array(
                strtoupper($normalizedItem),
                $validValues,
                true
            )) {
                throw ValidationException::withMessages([
                    'services' => [
                        "Invalid checkbox option [{$normalizedItem}] for field [{$baseField->code}].",
                    ],
                ]);
            }

            $normalizedValues[] = $normalizedItem;
        }

        return array_values(array_unique($normalizedValues));
    }

    /**
     * Normalize an integer field.
     */
    protected function normalizeNumber(mixed $value): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw ValidationException::withMessages([
                'services' => [
                    'The submitted value must be a valid number.',
                ],
            ]);
        }

        return (int) $value;
    }

    /**
     * Normalize a decimal field.
     */
    protected function normalizeDecimal(mixed $value): float
    {
        if (! is_numeric($value)) {
            throw ValidationException::withMessages([
                'services' => [
                    'The submitted value must be a valid decimal number.',
                ],
            ]);
        }

        return (float) $value;
    }

    /**
     * Normalize a boolean field.
     */
    protected function normalizeBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (
            $value === 1
            || $value === '1'
            || $value === 'true'
            || $value === 'TRUE'
        ) {
            return true;
        }

        if (
            $value === 0
            || $value === '0'
            || $value === 'false'
            || $value === 'FALSE'
        ) {
            return false;
        }

        throw ValidationException::withMessages([
            'services' => [
                'The submitted value must be a valid boolean.',
            ],
        ]);
    }

    /**
     * Normalize a date field.
     */
    protected function normalizeDate(mixed $value): string
    {
        try {
            return Carbon::parse($value)->toDateString();
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'services' => [
                    'The submitted value must be a valid date.',
                ],
            ]);
        }
    }

    /**
     * Normalize a text field.
     */
    protected function normalizeText(mixed $value): string
    {
        if (is_array($value) || is_object($value)) {
            throw ValidationException::withMessages([
                'services' => [
                    'The submitted value must be text.',
                ],
            ]);
        }

        return trim((string) $value);
    }

    /**
     * Build a human-readable display value.
     */
    protected function makeDisplayValue(
        mixed $value,
        array $types,
        $serviceField
    ): ?string {
        if ($value === null) {
            return null;
        }

        if ($types['input_type'] === 'CHECKBOX') {
            if (! is_array($value)) {
                return (string) $value;
            }

            $displayValues = [];

            foreach ($value as $item) {
                $displayValues[] = $this->resolveOptionLabel(
                    $item,
                    $serviceField
                );
            }

            return implode(', ', $displayValues);
        }

        if (in_array(
            $types['input_type'],
            ['FILE', 'MULTI_FILE'],
            true
        )) {
            if (is_array($value)) {
                return implode(
                    ', ',
                    array_map(
                        static fn ($item): string => (string) $item,
                        $value
                    )
                );
            }

            return (string) $value;
        }

        if ($types['data_type'] === 'BOOLEAN') {
            return $value ? 'Yes' : 'No';
        }

        if (
            $types['data_type'] === 'SELECT'
            || in_array($types['input_type'], ['SELECT', 'RADIO'], true)
        ) {
            return $this->resolveOptionLabel($value, $serviceField);
        }

        return is_scalar($value)
            ? (string) $value
            : json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Resolve an option's display label.
     */
    protected function resolveOptionLabel(
        mixed $value,
        $serviceField
    ): string {
        $baseField = $serviceField->baseField;

        if (! $baseField) {
            return (string) $value;
        }

        $matchingOption = $baseField->options->first(
            fn ($option): bool =>
                strtoupper(trim((string) $option->value))
                === strtoupper(trim((string) $value))
        );

        if (! $matchingOption) {
            return (string) $value;
        }

        return (string) (
            $matchingOption->label
            ?: $matchingOption->value
        );
    }
}
