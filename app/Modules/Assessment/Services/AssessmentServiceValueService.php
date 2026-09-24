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
     * Normal Assessment payload:
     *
     * [
     *     [
     *         'serviceId' => '...',
     *         'serviceCode' => '...',
     *         'fields' => [
     *             'FIELD_CODE' => 'value',
     *         ],
     *     ],
     * ]
     *
     * Responsibilities:
     *
     * - Resolve the configured revenue service
     * - Validate serviceCode against serviceId
     * - Create assessment_service
     * - Store dynamic field values
     * - Snapshot field configuration
     * - Normalize submitted values
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
                    'Assessment service skipped: invalid service payload.',
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

            $service = $this->resolveRevenueService(
                $serviceData
            );

            $assessmentService = AssessmentServiceModel::create([
                'id' => (string) Str::uuid(),

                'assessment_id' => $assessment->id,

                'service_id' => $service->id,

                /*
                 * RevenueService
                 *      ↓
                 * revenueCode
                 *      ↓
                 * code
                 */
                'service_code' => $service->revenueCode?->code,

                'service_order' => $index + 1,

                'status' => 'CAPTURED',

                /*
                 * Calculation is intentionally not
                 * performed here.
                 */
                'computed_amount' => null,

                'currency_code' => null,

                'calculation_metadata' => null,

                'calculation_error' => null,

                'calculated_at' => null,
            ]);

            Log::info(
                'Assessment service created.',
                [
                    'assessment_id' => $assessment->id,

                    'assessment_service_id' =>
                        $assessmentService->id,

                    'revenue_service_id' =>
                        $service->id,

                    'service_code' =>
                        $service->revenueCode?->code,

                    'service_order' =>
                        $index + 1,

                    'field_count' =>
                        is_array($serviceData['fields'] ?? null)
                            ? count($serviceData['fields'])
                            : 0,
                ]
            );

            /*
             * Normal assessments submit fields using
             * base-field codes.
             */
            $this->storeServiceValues(
                $assessmentService,
                $serviceData['fields'] ?? []
            );
        }

        Log::info(
            'Assessment services persistence completed.',
            [
                'assessment_id' => $assessment->id,

                'service_count' => count($services),

                'stored_service_count' =>
                    $assessment->services()->count(),
            ]
        );
    }

    /**
     * ============================================================
     * STORE VALUES BY FIELD CODE
     * ============================================================
     *
     * Store dynamic values for one assessment service.
     *
     * Expected input:
     *
     * [
     *     'FIELD_CODE' => 'value',
     *     'ANOTHER_FIELD' => 'value',
     * ]
     *
     * This is the existing normal Assessment behavior.
     */
    public function storeServiceValues(
        AssessmentServiceModel $assessmentService,
        array $submittedFields
    ): void {
        Log::info(
            'Assessment service field persistence started by field code.',
            [
                'assessment_service_id' =>
                    $assessmentService->id,

                'revenue_service_id' =>
                    $assessmentService->service_id,

                'submitted_field_count' =>
                    count($submittedFields),
            ]
        );

        $service = $this->resolveAssessmentService(
            $assessmentService
        );

        /*
         * Build lookup using BASE FIELD CODE.
         */
        $activeFields = $this->getActiveServiceFields(
            $service
        );

        $fields = $activeFields->keyBy(
            function ($serviceField) {
                return strtoupper(
                    trim(
                        (string) $serviceField->baseField->code
                    )
                );
            }
        );

        Log::debug(
            'Assessment service field-code configuration loaded.',
            [
                'assessment_service_id' =>
                    $assessmentService->id,

                'revenue_service_id' =>
                    $service->id,

                'configured_field_count' =>
                    $fields->count(),

                'configured_field_codes' =>
                    $fields->keys()->values()->all(),
            ]
        );

        /*
         * Persist submitted fields.
         */
        $this->persistServiceValues(
            $assessmentService,
            $fields,
            $submittedFields
        );

        Log::info(
            'Assessment service field-code persistence completed.',
            [
                'assessment_service_id' =>
                    $assessmentService->id,

                'stored_value_count' =>
                    $assessmentService->values()->count(),
            ]
        );
    }

    /**
     * ============================================================
     * STORE VALUES BY FIELD UUID
     * ============================================================
     *
     * Existing LIZZ uses RevenueServiceField UUIDs as keys.
     *
     * Example:
     *
     * [
     *     '01a0a71b-d3e5-726e-ab39-04e0d79f01f7' => true,
     *     '01a0a71b-d3e1-7012-818e-df7a5d1e10df' => '100',
     *     '01a0a71b-d3e3-704d-b8ba-13dbd6ece574' => '1FFAA',
     * ]
     *
     * This method resolves the UUID to the configured
     * RevenueServiceField and then uses the SAME persistence
     * and normalization logic as normal assessments.
     */
    public function storeServiceValuesByFieldId(
        AssessmentServiceModel $assessmentService,
        array $submittedFields
    ): void {
        Log::info(
            'Assessment service field persistence started by field UUID.',
            [
                'assessment_service_id' =>
                    $assessmentService->id,

                'revenue_service_id' =>
                    $assessmentService->service_id,

                'submitted_field_count' =>
                    count($submittedFields),
            ]
        );

        $service = $this->resolveAssessmentService(
            $assessmentService
        );

        /*
         * Get only active configured fields.
         */
        $activeFields = $this->getActiveServiceFields(
            $service
        );

        /*
         * Build lookup using RevenueServiceField UUID.
         *
         * IMPORTANT:
         *
         * Your application uses UUIDv7-style identifiers.
         *
         * Example:
         *
         * 01a0a71b-d3e5-726e-ab39-04e0d79f01f7
         *
         * The UUID must therefore be normalized to lowercase.
         */
        $fields = $activeFields->keyBy(
            function ($serviceField) {
                return strtolower(
                    trim(
                        (string) $serviceField->id
                    )
                );
            }
        );

        Log::info(
            'Assessment service UUID field configuration loaded.',
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
         * Log submitted IDs only.
         *
         * Do NOT log raw values because some fields
         * may contain sensitive taxpayer information.
         */
        Log::debug(
            'Submitted service field UUIDs received.',
            [
                'assessment_service_id' =>
                    $assessmentService->id,

                'submitted_field_ids' =>
                    array_keys($submittedFields),
            ]
        );

        /*
         * Persist submitted fields.
         */
        $this->persistServiceValues(
            $assessmentService,
            $fields,
            $submittedFields
        );

        /*
         * Verify the final number of persisted values.
         */
        $storedCount = $assessmentService
            ->values()
            ->count();

        Log::info(
            'Assessment service field UUID persistence completed.',
            [
                'assessment_service_id' =>
                    $assessmentService->id,

                'submitted_field_count' =>
                    count($submittedFields),

                'configured_field_count' =>
                    $fields->count(),

                'stored_value_count' =>
                    $storedCount,
            ]
        );
    }

    /**
     * ============================================================
     * RESOLVE ASSESSMENT SERVICE
     * ============================================================
     *
     * Load the RevenueService configuration belonging to
     * the AssessmentService record.
     */
    protected function resolveAssessmentService(
        AssessmentServiceModel $assessmentService
    ): RevenueService {
        Log::debug(
            'Resolving revenue service for assessment service.',
            [
                'assessment_service_id' =>
                    $assessmentService->id,

                'revenue_service_id' =>
                    $assessmentService->service_id,
            ]
        );

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

        Log::debug(
            'Revenue service resolved successfully.',
            [
                'assessment_service_id' =>
                    $assessmentService->id,

                'revenue_service_id' =>
                    $service->id,

                'revenue_service_field_count' =>
                    $service->fields->count(),
            ]
        );

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
            ->filter(function ($serviceField) {
                return $serviceField->is_active
                    && $serviceField->baseField
                    && $serviceField->baseField->is_active;
            })
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
     * Shared persistence engine used by:
     *
     * - storeServiceValues()
     * - storeServiceValuesByFieldId()
     *
     * This prevents duplication of:
     *
     * - field validation
     * - type resolution
     * - normalization
     * - option validation
     * - display-value generation
     * - file handling
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
        | This is important for Existing LIZZ updates.
        |
        | Without this:
        |
        | Edit #1 → values
        | Edit #2 → another set of values
        | Edit #3 → another set of values
        |
        | The assessment would contain duplicate historical
        | field snapshots.
        |
        */

        if (! empty($submittedFields)) {
            $deletedCount = $assessmentService
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
        | Store Submitted Fields
        |--------------------------------------------------------------------------
        */

        foreach ($submittedFields as $fieldKey => $rawValue) {
            $normalizedKey = trim(
                (string) $fieldKey
            );

            /*
            |--------------------------------------------------------------------------
            | Empty Key
            |--------------------------------------------------------------------------
            */

            if ($normalizedKey === '') {
                Log::warning(
                    'Assessment service field skipped: empty field key.',
                    [
                        'assessment_service_id' =>
                            $assessmentService->id,
                    ]
                );

                $skippedCount++;

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Resolve Lookup Key
            |--------------------------------------------------------------------------
            */

            $lookupKey = $this->normalizeLookupKey(
                $normalizedKey
            );

            /*
            |--------------------------------------------------------------------------
            | Resolve Configured Field
            |--------------------------------------------------------------------------
            */

            if (! $fields->has($lookupKey)) {
                Log::warning(
                    'Assessment service field skipped: field was not found in configured active service fields.',
                    [
                        'assessment_service_id' =>
                            $assessmentService->id,

                        'submitted_field_key' =>
                            $normalizedKey,

                        'normalized_lookup_key' =>
                            $lookupKey,

                        'configured_field_count' =>
                            $fields->count(),
                    ]
                );

                $skippedCount++;

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Field Successfully Resolved
            |--------------------------------------------------------------------------
            */

            $serviceField = $fields->get(
                $lookupKey
            );

            $resolvedCount++;

            /*
            |--------------------------------------------------------------------------
            | Base Field
            |--------------------------------------------------------------------------
            */

            $baseField = $serviceField->baseField;

            if (! $baseField) {
                Log::warning(
                    'Assessment service field skipped: base field relationship is missing.',
                    [
                        'assessment_service_id' =>
                            $assessmentService->id,

                        'revenue_service_field_id' =>
                            $serviceField->id,
                    ]
                );

                $skippedCount++;

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Effective Types
            |--------------------------------------------------------------------------
            */

            $types = $this->resolveFieldTypes(
                $serviceField
            );

            Log::debug(
                'Assessment service field resolved successfully.',
                [
                    'assessment_service_id' =>
                        $assessmentService->id,

                    'revenue_service_field_id' =>
                        $serviceField->id,

                    'submitted_field_key' =>
                        $normalizedKey,

                    'normalized_lookup_key' =>
                        $lookupKey,

                    'field_code' =>
                        $baseField->code,

                    'data_type' =>
                        $types['data_type'],

                    'input_type' =>
                        $types['input_type'],
                ]
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
                Log::debug(
                    'Assessment service file field detected.',
                    [
                        'assessment_service_id' =>
                            $assessmentService->id,

                        'revenue_service_field_id' =>
                            $serviceField->id,

                        'input_type' =>
                            $types['input_type'],
                    ]
                );

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

                            'revenue_service_field_id' =>
                                $serviceField->id,

                            /*
                             * Always snapshot the authoritative
                             * base-field code.
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
                     * Attach uploaded files to the value record.
                     */
                    $this->fileService
                        ->attachValueFiles(
                            $assessmentServiceValue,
                            $value
                        );

                    $createdCount++;

                    Log::debug(
                        'Assessment service file value created.',
                        [
                            'assessment_service_id' =>
                                $assessmentService->id,

                            'assessment_service_value_id' =>
                                $assessmentServiceValue->id,

                            'revenue_service_field_id' =>
                                $serviceField->id,

                            'field_code' =>
                                $baseField->code,
                        ]
                    );
                } catch (Throwable $exception) {
                    Log::error(
                        'Assessment service file value creation failed.',
                        [
                            'assessment_service_id' =>
                                $assessmentService->id,

                            'revenue_service_field_id' =>
                                $serviceField->id,

                            'field_code' =>
                                $baseField->code,

                            'exception' =>
                                $exception::class,

                            'message' =>
                                $exception->getMessage(),

                            'file' =>
                                $exception->getFile(),

                            'line' =>
                                $exception->getLine(),
                        ]
                    );

                    throw $exception;
                }

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | NORMAL VALUES
            |--------------------------------------------------------------------------
            */

            try {
                $value =
                    $this->normalizeFieldValue(
                        $rawValue,
                        $types['data_type'],
                        $types['input_type'],
                        $serviceField
                    );
            } catch (ValidationException $exception) {
                Log::warning(
                    'Assessment service field value normalization failed.',
                    [
                        'assessment_service_id' =>
                            $assessmentService->id,

                        'revenue_service_field_id' =>
                            $serviceField->id,

                        'field_code' =>
                            $baseField->code,

                        'data_type' =>
                            $types['data_type'],

                        'input_type' =>
                            $types['input_type'],

                        'errors' =>
                            $exception->errors(),
                    ]
                );

                throw $exception;
            }

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

            try {
                $assessmentServiceValue =
                    AssessmentServiceValue::create([
                        'id' =>
                            (string) Str::uuid(),

                        'assessment_service_id' =>
                            $assessmentService->id,

                        'revenue_service_field_id' =>
                            $serviceField->id,

                        /*
                         * Persist the authoritative base-field code,
                         * regardless of whether the caller supplied:
                         *
                         * - field code
                         * - field UUID
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
            } catch (Throwable $exception) {
                Log::error(
                    'Assessment service value creation failed.',
                    [
                        'assessment_service_id' =>
                            $assessmentService->id,

                        'revenue_service_field_id' =>
                            $serviceField->id,

                        'field_code' =>
                            $baseField->code,

                        'data_type' =>
                            $types['data_type'],

                        'input_type' =>
                            $types['input_type'],

                        'exception' =>
                            $exception::class,

                        'message' =>
                            $exception->getMessage(),

                        'file' =>
                            $exception->getFile(),

                        'line' =>
                            $exception->getLine(),
                    ]
                );

                throw $exception;
            }

            $createdCount++;

            Log::debug(
                'Assessment service value created.',
                [
                    'assessment_service_id' =>
                        $assessmentService->id,

                    'assessment_service_value_id' =>
                        $assessmentServiceValue->id,

                    'revenue_service_field_id' =>
                        $serviceField->id,

                    'field_code' =>
                        $baseField->code,

                    'data_type' =>
                        $types['data_type'],

                    'input_type' =>
                        $types['input_type'],
                ]
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Final Persistence Summary
        |--------------------------------------------------------------------------
        */

        $storedCount = $assessmentService
            ->values()
            ->count();

        Log::info(
            'Assessment service values persistence summary.',
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
     * LOOKUP KEY
     * ============================================================
     *
     * Field codes are case-insensitive.
     *
     * UUIDs are normalized to lowercase.
     *
     * IMPORTANT:
     *
     * This intentionally does NOT restrict the UUID version.
     *
     * The application uses UUIDv7 identifiers such as:
     *
     * 01a0a71b-d3e5-726e-ab39-04e0d79f01f7
     *
     * The old regex used:
     *
     * [1-5]
     *
     * which excluded UUIDv7.
     */
    protected function normalizeLookupKey(
        string $key
    ): string {
        $key = trim($key);

        /*
        |--------------------------------------------------------------------------
        | UUID / UUIDv7
        |--------------------------------------------------------------------------
        |
        | Match the UUID structure only.
        |
        | 8-4-4-4-12
        |
        | Do not restrict the version nibble.
        |
        */

        if (
            preg_match(
                '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/',
                $key
            )
        ) {
            return strtolower($key);
        }

        /*
        |--------------------------------------------------------------------------
        | Normal Field Codes
        |--------------------------------------------------------------------------
        */

        return strtoupper($key);
    }

    /**
     * ============================================================
     * SERVICE RESOLUTION
     * ============================================================
     */

    protected function resolveRevenueService(
        array $serviceData
    ): RevenueService {
        $serviceId =
            $serviceData['serviceId'] ?? null;

        if (! $serviceId) {
            Log::warning(
                'Revenue service resolution failed: serviceId missing.'
            );

            throw ValidationException::withMessages([
                'services' => [
                    'Each assessment service must contain a serviceId.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Load Revenue Service
        |--------------------------------------------------------------------------
        */

        $service = RevenueService::query()
            ->with([
                'revenueCode',
                'fields.baseField.options',
            ])
            ->find($serviceId);

        if (! $service) {
            Log::error(
                'Revenue service could not be found.',
                [
                    'revenue_service_id' =>
                        $serviceId,
                ]
            );

            throw ValidationException::withMessages([
                'services' => [
                    "Revenue service [{$serviceId}] was not found.",
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Authoritative Revenue Code
        |--------------------------------------------------------------------------
        */

        $expectedCode =
            $service->revenueCode?->code;

        if ($expectedCode === null) {
            Log::error(
                'Revenue service does not have a valid revenue code.',
                [
                    'revenue_service_id' =>
                        $service->id,
                ]
            );

            throw ValidationException::withMessages([
                'services' => [
                    "Revenue service [{$serviceId}] does not have a valid revenue code.",
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Validate Submitted Service Code
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

        Log::debug(
            'Revenue service resolved and validated.',
            [
                'revenue_service_id' =>
                    $service->id,

                'revenue_service_code' =>
                    $expectedCode,
            ]
        );

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
            Log::error(
                'Service field has no base field.',
                [
                    'revenue_service_field_id' =>
                        $serviceField->id,
                ]
            );

            throw ValidationException::withMessages([
                'services' => [
                    'The configured service field does not have a base field.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Effective Data Type
        |--------------------------------------------------------------------------
        */

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

        /*
        |--------------------------------------------------------------------------
        | Effective Input Type
        |--------------------------------------------------------------------------
        */

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
        | Legacy FILE Configuration
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

        /*
        |--------------------------------------------------------------------------
        | Supported Data Types
        |--------------------------------------------------------------------------
        */

        $allowedDataTypes = [
            'NUMBER',
            'DECIMAL',
            'TEXT',
            'BOOLEAN',
            'DATE',
            'SELECT',
        ];

        /*
        |--------------------------------------------------------------------------
        | Supported Input Types
        |--------------------------------------------------------------------------
        */

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

        /*
        |--------------------------------------------------------------------------
        | Validate Data Type
        |--------------------------------------------------------------------------
        */

        if (
            ! in_array(
                $dataType,
                $allowedDataTypes,
                true
            )
        ) {
            Log::error(
                'Unsupported service field data type.',
                [
                    'revenue_service_field_id' =>
                        $serviceField->id,

                    'data_type' =>
                        $dataType,
                ]
            );

            throw ValidationException::withMessages([
                'services' => [
                    "Unsupported data type [{$dataType}].",
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Validate Input Type
        |--------------------------------------------------------------------------
        */

        if (
            ! in_array(
                $inputType,
                $allowedInputTypes,
                true
            )
        ) {
            Log::error(
                'Unsupported service field input type.',
                [
                    'revenue_service_field_id' =>
                        $serviceField->id,

                    'input_type' =>
                        $inputType,
                ]
            );

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
     * NORMALIZE VALUE
     * ============================================================
     */

    protected function normalizeFieldValue(
        mixed $value,
        string $dataType,
        string $inputType,
        $serviceField
    ): mixed {
        /*
        |--------------------------------------------------------------------------
        | NULL / EMPTY
        |--------------------------------------------------------------------------
        */

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

        if (
            $inputType === 'CHECKBOX'
        ) {
            return $this->normalizeCheckboxValue(
                $value,
                $serviceField
            );
        }

        /*
        |--------------------------------------------------------------------------
        | STANDARD DATA TYPES
        |--------------------------------------------------------------------------
        */

        return match ($dataType) {
            'NUMBER' =>
                $this->normalizeNumber(
                    $value
                ),

            'DECIMAL' =>
                $this->normalizeDecimal(
                    $value
                ),

            'BOOLEAN' =>
                $this->normalizeBoolean(
                    $value
                ),

            'DATE' =>
                $this->normalizeDate(
                    $value
                ),

            'TEXT' =>
                $this->normalizeText(
                    $value
                ),

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
     * OPTION VALUES
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

        $options =
            $baseField->options;

        $matchingOption =
            $options->first(
                function ($option) use (
                    $submittedValue
                ) {
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

        $options =
            $baseField->options;

        $validValues =
            $options
                ->map(
                    static fn ($option) =>
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
                    strtoupper(
                        $normalizedItem
                    ),
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
            $types['input_type']
            === 'CHECKBOX'
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
                        static fn ($item) =>
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
            $types['data_type']
            === 'BOOLEAN'
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
            $types['data_type']
            === 'SELECT'
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

        $options =
            $baseField->options;

        $matchingOption =
            $options->first(
                function ($option) use (
                    $value
                ) {
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
