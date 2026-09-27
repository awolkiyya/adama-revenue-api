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
     * ============================================================
     * STORE SERVICES
     * ============================================================
     *
     * Store assessment services and their dynamic field values.
     *
     * Expected payload:
     *
     * [
     *     [
     *         'serviceId' => '...',
     *         'serviceCode' => '...',
     *         'fields' => [
     *             'REVENUE_SERVICE_FIELD_UUID' => 'value',
     *             'REVENUE_SERVICE_FIELD_UUID' => 'value',
     *         ],
     *     ],
     * ]
     *
     * IMPORTANT:
     *
     * The keys inside `fields` are RevenueServiceField.id.
     *
     * They are NOT:
     *
     * - BaseField.code
     * - RevenueServiceField.key
     * - Revenue code
     *
     * Responsibilities:
     *
     * - Resolve configured RevenueService
     * - Validate serviceCode against serviceId
     * - Create assessment_service
     * - Resolve RevenueServiceField by ID
     * - Validate field belongs to selected service
     * - Normalize submitted values
     * - Snapshot field configuration
     * - Generate display values
     * - Delegate file handling
     *
     * This service does NOT calculate tariffs or assessment amounts.
     */
    public function storeServices(
        Assessment $assessment,
        array $services
    ): void {
        Log::info(
            'Assessment services persistence started.',
            [
                'assessment_id' => $assessment->id,
                'service_count' => count($services),
            ]
        );

        foreach ($services as $index => $serviceData) {

            if (! is_array($serviceData)) {
                Log::warning(
                    'Assessment service rejected: invalid service payload.',
                    [
                        'assessment_id' => $assessment->id,
                        'service_index' => $index,
                    ]
                );

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

            $service = $this->resolveRevenueService(
                $serviceData
            );

            /*
            |--------------------------------------------------------------------------
            | Create Assessment Service
            |--------------------------------------------------------------------------
            */

            $assessmentService = AssessmentServiceModel::create([
                'id' =>
                    (string) Str::uuid(),

                'assessment_id' =>
                    $assessment->id,

                'service_id' =>
                    $service->id,

                /*
                 * Always store the authoritative code from
                 * RevenueService -> RevenueCode.
                 */
                'service_code' =>
                    $service->revenueCode?->code,

                'service_order' =>
                    $index + 1,

                'status' =>
                    'CAPTURED',

                /*
                 * Calculation is intentionally handled later.
                 */
                'computed_amount' =>
                    null,

                'currency_code' =>
                    null,

                'calculation_metadata' =>
                    null,

                'calculation_error' =>
                    null,

                'calculated_at' =>
                    null,
            ]);

            $submittedFields =
                $serviceData['fields'] ?? [];

            if (! is_array($submittedFields)) {
                throw ValidationException::withMessages([
                    "services.{$index}.fields" => [
                        'The fields value must be an object.',
                    ],
                ]);
            }

            Log::info(
                'Assessment service created.',
                [
                    'assessment_id' =>
                        $assessment->id,

                    'assessment_service_id' =>
                        $assessmentService->id,

                    'revenue_service_id' =>
                        $service->id,

                    'service_code' =>
                        $service->revenueCode?->code,

                    'service_order' =>
                        $index + 1,

                    'field_count' =>
                        count($submittedFields),
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Store Dynamic Fields By RevenueServiceField.id
            |--------------------------------------------------------------------------
            */

            $this->storeServiceValuesByFieldId(
                $assessmentService,
                $submittedFields
            );
        }

        Log::info(
            'Assessment services persistence completed.',
            [
                'assessment_id' =>
                    $assessment->id,

                'service_count' =>
                    count($services),

                'stored_service_count' =>
                    $assessment->services()->count(),
            ]
        );
    }

    /**
     * ============================================================
     * STORE VALUES BY FIELD ID
     * ============================================================
     *
     * Canonical Assessment field persistence method.
     *
     * Expected input:
     *
     * [
     *     'RevenueServiceField.id' => value,
     *     'RevenueServiceField.id' => value,
     * ]
     *
     * The field ID must belong to the RevenueService selected
     * by the assessment service.
     */
    public function storeServiceValuesByFieldId(
        AssessmentServiceModel $assessmentService,
        array $submittedFields
    ): void {
        Log::info(
            'Assessment service field persistence started by field ID.',
            [
                'assessment_service_id' =>
                    $assessmentService->id,

                'revenue_service_id' =>
                    $assessmentService->service_id,

                'submitted_field_count' =>
                    count($submittedFields),
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Resolve Revenue Service
        |--------------------------------------------------------------------------
        */

        $service = $this->resolveAssessmentService(
            $assessmentService
        );

        /*
        |--------------------------------------------------------------------------
        | Active RevenueServiceFields
        |--------------------------------------------------------------------------
        */

        $activeFields =
            $this->getActiveServiceFields(
                $service
            );

        /*
        |--------------------------------------------------------------------------
        | Build Field ID Lookup
        |--------------------------------------------------------------------------
        |
        | IMPORTANT:
        |
        | The lookup key is RevenueServiceField.id.
        |
        */

        $fields = $activeFields->keyBy(
            function ($serviceField): string {
                return strtolower(
                    trim(
                        (string) $serviceField->id
                    )
                );
            }
        );

        Log::debug(
            'Revenue service field ID configuration loaded.',
            [
                'assessment_service_id' =>
                    $assessmentService->id,

                'revenue_service_id' =>
                    $service->id,

                'configured_field_count' =>
                    $fields->count(),

                'configured_field_ids' =>
                    $fields->keys()->values()->all(),
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Do Not Log Raw Values
        |--------------------------------------------------------------------------
        |
        | Values may contain taxpayer information.
        |
        */

        Log::debug(
            'Submitted service field IDs received.',
            [
                'assessment_service_id' =>
                    $assessmentService->id,

                'submitted_field_ids' =>
                    array_keys($submittedFields),
            ]
        );

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

        Log::info(
            'Assessment service field persistence completed.',
            [
                'assessment_service_id' =>
                    $assessmentService->id,

                'submitted_field_count' =>
                    count($submittedFields),

                'stored_value_count' =>
                    $assessmentService
                        ->values()
                        ->count(),
            ]
        );
    }

    /**
     * ============================================================
     * RESOLVE ASSESSMENT SERVICE
     * ============================================================
     */
    protected function resolveAssessmentService(
        AssessmentServiceModel $assessmentService
    ): RevenueService {
        $service = RevenueService::query()
            ->with([
                'revenueCode',
                'fields.baseField.options',
            ])
            ->find(
                $assessmentService->service_id
            );

        if (! $service) {
            Log::error(
                'Revenue service could not be resolved.',
                [
                    'assessment_service_id' =>
                        $assessmentService->id,

                    'revenue_service_id' =>
                        $assessmentService->service_id,
                ]
            );

            throw ValidationException::withMessages([
                'services' => [
                    "Revenue service [{$assessmentService->service_id}] was not found.",
                ],
            ]);
        }

        return $service;
    }

    /**
     * ============================================================
     * ACTIVE SERVICE FIELDS
     * ============================================================
     *
     * Only active RevenueServiceFields whose BaseField is also
     * active are accepted.
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

        Log::debug(
            'Active revenue service fields resolved.',
            [
                'revenue_service_id' =>
                    $service->id,

                'total_configured_fields' =>
                    $service->fields->count(),

                'active_field_count' =>
                    $activeFields->count(),

                'inactive_or_invalid_field_count' =>
                    $service->fields->count()
                    - $activeFields->count(),
            ]
        );

        return $activeFields;
    }

    /**
     * ============================================================
     * PERSIST SERVICE VALUES
     * ============================================================
     *
     * Shared persistence engine.
     *
     * `$fields` is keyed by RevenueServiceField.id.
     *
     * `$submittedFields` is also keyed by RevenueServiceField.id.
     */
    protected function persistServiceValues(
        AssessmentServiceModel $assessmentService,
        $fields,
        array $submittedFields
    ): void {
        $resolvedCount = 0;
        $skippedCount = 0;
        $createdCount = 0;

        Log::debug(
            'Assessment service value persistence engine started.',
            [
                'assessment_service_id' =>
                    $assessmentService->id,

                'submitted_count' =>
                    count($submittedFields),

                'configured_count' =>
                    $fields->count(),
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Delete Existing Values
        |--------------------------------------------------------------------------
        |
        | This protects replacement/update operations from
        | leaving duplicate value records.
        |
        */

        if (! empty($submittedFields)) {

            $deletedCount =
                $assessmentService
                    ->values()
                    ->delete();

            Log::debug(
                'Existing assessment service values cleared.',
                [
                    'assessment_service_id' =>
                        $assessmentService->id,

                    'deleted_count' =>
                        $deletedCount,
                ]
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Process Submitted Fields
        |--------------------------------------------------------------------------
        */

        foreach ($submittedFields as $fieldId => $rawValue) {

            $normalizedFieldId =
                strtolower(
                    trim(
                        (string) $fieldId
                    )
                );

            /*
            |--------------------------------------------------------------------------
            | Empty Field ID
            |--------------------------------------------------------------------------
            */

            if ($normalizedFieldId === '') {

                Log::warning(
                    'Assessment service field rejected: empty field ID.',
                    [
                        'assessment_service_id' =>
                            $assessmentService->id,
                    ]
                );

                throw ValidationException::withMessages([
                    'services' => [
                        'Every submitted field must have a valid field ID.',
                    ],
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Field ID Format
            |--------------------------------------------------------------------------
            */

            if (
                ! preg_match(
                    '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/',
                    $normalizedFieldId
                )
            ) {
                Log::warning(
                    'Assessment service field rejected: invalid field ID.',
                    [
                        'assessment_service_id' =>
                            $assessmentService->id,

                        'field_id' =>
                            $normalizedFieldId,
                    ]
                );

                throw ValidationException::withMessages([
                    'services' => [
                        "Invalid RevenueServiceField ID [{$normalizedFieldId}].",
                    ],
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Resolve Field
            |--------------------------------------------------------------------------
            */

            if (! $fields->has($normalizedFieldId)) {

                Log::warning(
                    'Assessment service field rejected: field does not belong to the active service configuration.',
                    [
                        'assessment_service_id' =>
                            $assessmentService->id,

                        'revenue_service_id' =>
                            $assessmentService->service_id,

                        'field_id' =>
                            $normalizedFieldId,
                    ]
                );

                throw ValidationException::withMessages([
                    'services' => [
                        "Field [{$normalizedFieldId}] does not belong to the selected revenue service.",
                    ],
                ]);
            }

            $serviceField =
                $fields->get(
                    $normalizedFieldId
                );

            $resolvedCount++;

            /*
            |--------------------------------------------------------------------------
            | Base Field
            |--------------------------------------------------------------------------
            */

            $baseField =
                $serviceField->baseField;

            if (! $baseField) {

                throw ValidationException::withMessages([
                    'services' => [
                        "The configured field [{$serviceField->id}] does not have a base field.",
                    ],
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Effective Field Types
            |--------------------------------------------------------------------------
            */

            $types =
                $this->resolveFieldTypes(
                    $serviceField
                );

            /*
            |--------------------------------------------------------------------------
            | FILE / MULTI_FILE
            |--------------------------------------------------------------------------
            */

            if (
                in_array(
                    $types['input_type'],
                    [
                        'FILE',
                        'MULTI_FILE',
                    ],
                    true
                )
            ) {

                try {

                    $value =
                        $this->fileService
                            ->storeUploadedFiles(
                                $assessmentService,
                                $serviceField,
                                $rawValue,
                                $types['input_type']
                            );

                    $displayValue =
                        $this->makeDisplayValue(
                            $value,
                            $types,
                            $serviceField
                        );

                    $assessmentServiceValue =
                        AssessmentServiceValue::create([
                            'id' =>
                                (string) Str::uuid(),

                            'assessment_service_id' =>
                                $assessmentService->id,

                            /*
                             * AUTHORITATIVE FIELD ID
                             */
                            'revenue_service_field_id' =>
                                $serviceField->id,

                            /*
                             * Historical field code snapshot.
                             */
                            'field_code' =>
                                strtoupper(
                                    trim(
                                        (string) $baseField->code
                                    )
                                ),

                            'field_label' =>
                                $serviceField->label
                                ?: $baseField->label,

                            'data_type' =>
                                $types['data_type'],

                            'input_type' =>
                                $types['input_type'],

                            'value' =>
                                $value,

                            'display_value' =>
                                $displayValue,

                            'measurement_unit_id' =>
                                $serviceField->measurement_unit_id
                                ?? $baseField->measurement_unit_id
                                ?? null,

                            'sort_order' =>
                                $serviceField->sort_order
                                ?? $baseField->sort_order
                                ?? 0,
                        ]);

                    /*
                    |--------------------------------------------------------------------------
                    | Attach Files
                    |--------------------------------------------------------------------------
                    */

                    $this->fileService
                        ->attachValueFiles(
                            $assessmentServiceValue,
                            $value
                        );

                    $createdCount++;

                } catch (Throwable $exception) {

                    Log::error(
                        'Assessment service file value creation failed.',
                        [
                            'assessment_service_id' =>
                                $assessmentService->id,

                            'revenue_service_field_id' =>
                                $serviceField->id,

                            'exception' =>
                                $exception::class,

                            'message' =>
                                $exception->getMessage(),
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

            $value =
                $this->normalizeFieldValue(
                    $rawValue,
                    $types['data_type'],
                    $types['input_type'],
                    $serviceField
                );

            /*
            |--------------------------------------------------------------------------
            | DISPLAY VALUE
            |--------------------------------------------------------------------------
            */

            $displayValue =
                $this->makeDisplayValue(
                    $value,
                    $types,
                    $serviceField
                );

            /*
            |--------------------------------------------------------------------------
            | CREATE VALUE SNAPSHOT
            |--------------------------------------------------------------------------
            */

            $assessmentServiceValue =
                AssessmentServiceValue::create([
                    'id' =>
                        (string) Str::uuid(),

                    'assessment_service_id' =>
                        $assessmentService->id,

                    /*
                     * AUTHORITATIVE RevenueServiceField ID.
                     */
                    'revenue_service_field_id' =>
                        $serviceField->id,

                    /*
                     * Historical BaseField code snapshot.
                     */
                    'field_code' =>
                        strtoupper(
                            trim(
                                (string) $baseField->code
                            )
                        ),

                    'field_label' =>
                        $serviceField->label
                        ?: $baseField->label,

                    'data_type' =>
                        $types['data_type'],

                    'input_type' =>
                        $types['input_type'],

                    'value' =>
                        $value,

                    'display_value' =>
                        $displayValue,

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

        /*
        |--------------------------------------------------------------------------
        | Persistence Summary
        |--------------------------------------------------------------------------
        */

        $storedCount =
            $assessmentService
                ->values()
                ->count();

        Log::info(
            'Assessment service values persistence completed.',
            [
                'assessment_service_id' =>
                    $assessmentService->id,

                'submitted_count' =>
                    count($submittedFields),

                'configured_count' =>
                    $fields->count(),

                'resolved_count' =>
                    $resolvedCount,

                'skipped_count' =>
                    $skippedCount,

                'created_count' =>
                    $createdCount,

                'stored_count' =>
                    $storedCount,
            ]
        );
    }

    /**
     * ============================================================
     * RESOLVE REVENUE SERVICE
     * ============================================================
     */
    protected function resolveRevenueService(
        array $serviceData
    ): RevenueService {
        $serviceId =
            $serviceData['serviceId'] ?? null;

        if (! $serviceId) {

            throw ValidationException::withMessages([
                'services' => [
                    'Each assessment service must contain a serviceId.',
                ],
            ]);
        }

        $service =
            RevenueService::query()
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

        /*
        |--------------------------------------------------------------------------
        | Authoritative Service Code
        |--------------------------------------------------------------------------
        */

        $expectedCode =
            $service->revenueCode?->code;

        if ($expectedCode === null) {

            throw ValidationException::withMessages([
                'services' => [
                    "Revenue service [{$serviceId}] does not have a valid revenue code.",
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Verify Submitted Service Code
        |--------------------------------------------------------------------------
        */

        $submittedCode =
            $serviceData['serviceCode'] ?? null;

        if ($submittedCode !== null) {

            $submittedCode =
                trim(
                    (string) $submittedCode
                );

            $expectedCode =
                trim(
                    (string) $expectedCode
                );

            if (
                strtoupper($submittedCode)
                !== strtoupper($expectedCode)
            ) {

                Log::warning(
                    'Revenue service code validation failed.',
                    [
                        'revenue_service_id' =>
                            $serviceId,

                        'submitted_code' =>
                            $submittedCode,

                        'expected_code' =>
                            $expectedCode,
                    ]
                );

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
     * ============================================================
     * FIELD TYPES
     * ============================================================
     */
    protected function resolveFieldTypes(
        $serviceField
    ): array {
        $baseField =
            $serviceField->baseField;

        if (! $baseField) {

            throw ValidationException::withMessages([
                'services' => [
                    "The field [{$serviceField->id}] does not have a base field.",
                ],
            ]);
        }

        $dataType =
            strtoupper(
                trim(
                    (string) (
                        $serviceField->data_type
                        ?? $baseField->data_type
                        ?? 'TEXT'
                    )
                )
            );

        $inputType =
            strtoupper(
                trim(
                    (string) (
                        $serviceField->input_type
                        ?? $baseField->input_type
                        ?? ''
                    )
                )
            );

        /*
        |--------------------------------------------------------------------------
        | Backward Compatibility
        |--------------------------------------------------------------------------
        */

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

        /*
        |--------------------------------------------------------------------------
        | FILE / MULTI_FILE
        |--------------------------------------------------------------------------
        */

        if (
            in_array(
                $dataType,
                [
                    'FILE',
                    'MULTI_FILE',
                ],
                true
            )
        ) {
            $inputType =
                $dataType;

            $dataType =
                'TEXT';
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

        if (
            ! in_array(
                $dataType,
                $allowedDataTypes,
                true
            )
        ) {

            throw ValidationException::withMessages([
                'services' => [
                    "Unsupported data type [{$dataType}].",
                ],
            ]);
        }

        if (
            ! in_array(
                $inputType,
                $allowedInputTypes,
                true
            )
        ) {

            throw ValidationException::withMessages([
                'services' => [
                    "Unsupported input type [{$inputType}].",
                ],
            ]);
        }

        return [
            'data_type' =>
                $dataType,

            'input_type' =>
                $inputType,
        ];
    }

    /**
     * ============================================================
     * NORMALIZE FIELD VALUE
     * ============================================================
     */
    protected function normalizeFieldValue(
        mixed $value,
        string $dataType,
        string $inputType,
        $serviceField
    ): mixed {
        if (
            $value === null
            || $value === ''
        ) {
            return null;
        }

        /*
        |--------------------------------------------------------------------------
        | SELECT / RADIO
        |--------------------------------------------------------------------------
        */

        if (
            $dataType === 'SELECT'
            || in_array(
                $inputType,
                [
                    'SELECT',
                    'RADIO',
                ],
                true
            )
        ) {
            return $this->normalizeOptionValue(
                $value,
                $serviceField
            );
        }

        /*
        |--------------------------------------------------------------------------
        | CHECKBOX
        |--------------------------------------------------------------------------
        */

        if ($inputType === 'CHECKBOX') {
            return $this->normalizeCheckboxValue(
                $value,
                $serviceField
            );
        }

        /*
        |--------------------------------------------------------------------------
        | STANDARD TYPES
        |--------------------------------------------------------------------------
        */

        return match ($dataType) {
            'NUMBER' =>
                $this->normalizeNumber($value),

            'DECIMAL' =>
                $this->normalizeDecimal($value),

            'BOOLEAN' =>
                $this->normalizeBoolean($value),

            'DATE' =>
                $this->normalizeDate($value),

            'TEXT' =>
                $this->normalizeText($value),

            'SELECT' =>
                $this->normalizeOptionValue(
                    $value,
                    $serviceField
                ),

            default =>
                $value,
        };
    }

    /**
     * ============================================================
     * OPTION VALUE
     * ============================================================
     */
    protected function normalizeOptionValue(
        mixed $value,
        $serviceField
    ): string {
        if (
            is_array($value)
            || is_object($value)
        ) {
            throw ValidationException::withMessages([
                'services' => [
                    'The selected value must be a single option.',
                ],
            ]);
        }

        $submittedValue =
            trim(
                (string) $value
            );

        if ($submittedValue === '') {
            return '';
        }

        $baseField =
            $serviceField->baseField;

        if (! $baseField) {
            throw ValidationException::withMessages([
                'services' => [
                    "The field [{$serviceField->id}] does not have a base field.",
                ],
            ]);
        }

        $matchingOption =
            $baseField->options->first(
                function ($option) use ($submittedValue): bool {

                    return strtoupper(
                        trim(
                            (string) $option->value
                        )
                    ) === strtoupper(
                        $submittedValue
                    );
                }
            );

        if (! $matchingOption) {
            throw ValidationException::withMessages([
                'services' => [
                    "Invalid option [{$submittedValue}] for field [{$baseField->code}].",
                ],
            ]);
        }

        return (string)
            $matchingOption->value;
    }

    /**
     * ============================================================
     * CHECKBOX
     * ============================================================
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

        $baseField =
            $serviceField->baseField;

        if (! $baseField) {
            throw ValidationException::withMessages([
                'services' => [
                    "The field [{$serviceField->id}] does not have a base field.",
                ],
            ]);
        }

        $validValues =
            $baseField->options
                ->map(
                    static fn ($option): string =>
                        strtoupper(
                            trim(
                                (string) $option->value
                            )
                        )
                )
                ->all();

        $normalizedValues = [];

        foreach ($value as $item) {

            if (
                is_array($item)
                || is_object($item)
            ) {
                throw ValidationException::withMessages([
                    'services' => [
                        'Each checkbox value must be a scalar option.',
                    ],
                ]);
            }

            $normalizedItem =
                trim(
                    (string) $item
                );

            if ($normalizedItem === '') {
                continue;
            }

            if (
                ! in_array(
                    strtoupper($normalizedItem),
                    $validValues,
                    true
                )
            ) {
                throw ValidationException::withMessages([
                    'services' => [
                        "Invalid checkbox option [{$normalizedItem}] for field [{$baseField->code}].",
                    ],
                ]);
            }

            $normalizedValues[] =
                $normalizedItem;
        }

        return array_values(
            array_unique(
                $normalizedValues
            )
        );
    }

    /**
     * ============================================================
     * NUMBER
     * ============================================================
     */
    protected function normalizeNumber(
        mixed $value
    ): int {
        if (
            filter_var(
                $value,
                FILTER_VALIDATE_INT
            ) === false
        ) {
            throw ValidationException::withMessages([
                'services' => [
                    'The submitted value must be a valid number.',
                ],
            ]);
        }

        return (int) $value;
    }

    /**
     * ============================================================
     * DECIMAL
     * ============================================================
     */
    protected function normalizeDecimal(
        mixed $value
    ): float {
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
     * ============================================================
     * BOOLEAN
     * ============================================================
     */
    protected function normalizeBoolean(
        mixed $value
    ): bool {
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
     * ============================================================
     * DATE
     * ============================================================
     */
    protected function normalizeDate(
        mixed $value
    ): string {
        try {
            return Carbon::parse(
                $value
            )->toDateString();
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'services' => [
                    'The submitted value must be a valid date.',
                ],
            ]);
        }
    }

    /**
     * ============================================================
     * TEXT
     * ============================================================
     */
    protected function normalizeText(
        mixed $value
    ): string {
        if (
            is_array($value)
            || is_object($value)
        ) {
            throw ValidationException::withMessages([
                'services' => [
                    'The submitted value must be text.',
                ],
            ]);
        }

        return trim(
            (string) $value
        );
    }

    /**
     * ============================================================
     * DISPLAY VALUE
     * ============================================================
     */
    protected function makeDisplayValue(
        mixed $value,
        array $types,
        $serviceField
    ): ?string {
        if ($value === null) {
            return null;
        }

        /*
        |--------------------------------------------------------------------------
        | CHECKBOX
        |--------------------------------------------------------------------------
        */

        if (
            $types['input_type'] === 'CHECKBOX'
        ) {
            if (! is_array($value)) {
                return (string) $value;
            }

            $displayValues = [];

            foreach ($value as $item) {
                $displayValues[] =
                    $this->resolveOptionLabel(
                        $item,
                        $serviceField
                    );
            }

            return implode(
                ', ',
                $displayValues
            );
        }

        /*
        |--------------------------------------------------------------------------
        | FILE / MULTI_FILE
        |--------------------------------------------------------------------------
        */

        if (
            in_array(
                $types['input_type'],
                [
                    'FILE',
                    'MULTI_FILE',
                ],
                true
            )
        ) {
            if (is_array($value)) {
                return implode(
                    ', ',
                    array_map(
                        static fn ($item): string =>
                            (string) $item,
                        $value
                    )
                );
            }

            return (string) $value;
        }

        /*
        |--------------------------------------------------------------------------
        | BOOLEAN
        |--------------------------------------------------------------------------
        */

        if (
            $types['data_type'] === 'BOOLEAN'
        ) {
            return $value
                ? 'Yes'
                : 'No';
        }

        /*
        |--------------------------------------------------------------------------
        | SELECT / RADIO
        |--------------------------------------------------------------------------
        */

        if (
            $types['data_type'] === 'SELECT'
            || in_array(
                $types['input_type'],
                [
                    'SELECT',
                    'RADIO',
                ],
                true
            )
        ) {
            return $this->resolveOptionLabel(
                $value,
                $serviceField
            );
        }

        /*
        |--------------------------------------------------------------------------
        | SCALAR / JSON
        |--------------------------------------------------------------------------
        */

        return is_scalar($value)
            ? (string) $value
            : json_encode(
                $value,
                JSON_UNESCAPED_UNICODE
            );
    }

    /**
     * ============================================================
     * OPTION LABEL
     * ============================================================
     */
    protected function resolveOptionLabel(
        mixed $value,
        $serviceField
    ): string {
        $baseField =
            $serviceField->baseField;

        if (! $baseField) {
            return (string) $value;
        }

        $matchingOption =
            $baseField->options->first(
                function ($option) use ($value): bool {

                    return strtoupper(
                        trim(
                            (string) $option->value
                        )
                    ) === strtoupper(
                        trim(
                            (string) $value
                        )
                    );
                }
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