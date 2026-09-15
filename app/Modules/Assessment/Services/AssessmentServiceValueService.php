<?php

namespace App\Modules\Assessment\Services;

use App\Models\Assessment;
use App\Models\AssessmentService as AssessmentServiceModel;
use App\Models\AssessmentServiceValue;
use App\Models\RevenueService;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
        foreach ($services as $index => $serviceData) {
            if (! is_array($serviceData)) {
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
                'service_code' =>
                    $service->revenueCode?->code,

                'service_order' => $index + 1,

                'status' => 'CAPTURED',

                /*
                 * Calculation is intentionally not performed here.
                 */
                'computed_amount' => null,

                'currency_code' => null,

                'calculation_metadata' => null,

                'calculation_error' => null,

                'calculated_at' => null,
            ]);

            $this->storeServiceValues(
                $assessmentService,
                $serviceData['fields'] ?? []
            );
        }
    }

    /**
     * ============================================================
     * STORE VALUES
     * ============================================================
     *
     * Store dynamic values for one assessment service.
     */
    public function storeServiceValues(
        AssessmentServiceModel $assessmentService,
        array $submittedFields
    ): void {
        $service = RevenueService::query()
            ->with([
                'revenueCode',
                'fields.baseField.options',
            ])
            ->find($assessmentService->service_id);

        if (! $service) {
            throw ValidationException::withMessages([
                'services' => [
                    "Revenue service [{$assessmentService->service_id}] was not found.",
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Active Service Fields
        |--------------------------------------------------------------------------
        */

        $fields = $service->fields
            ->filter(function ($serviceField) {
                return $serviceField->is_active
                    && $serviceField->baseField
                    && $serviceField->baseField->is_active;
            })
            ->keyBy(function ($serviceField) {
                return strtoupper(
                    trim(
                        (string) $serviceField->baseField->code
                    )
                );
            });

        /*
        |--------------------------------------------------------------------------
        | Store Submitted Fields
        |--------------------------------------------------------------------------
        */

        foreach ($submittedFields as $fieldCode => $rawValue) {
            $normalizedCode = strtoupper(
                trim((string) $fieldCode)
            );

            /*
            |--------------------------------------------------------------------------
            | Ignore Unknown Fields
            |--------------------------------------------------------------------------
            |
            | Revenue service configuration is authoritative.
            |
            */

            if (! $fields->has($normalizedCode)) {
                continue;
            }

            $serviceField = $fields->get(
                $normalizedCode
            );

            $baseField = $serviceField->baseField;

            /*
            |--------------------------------------------------------------------------
            | Resolve Effective Types
            |--------------------------------------------------------------------------
            */

            $types = $this->resolveFieldTypes(
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
                    ['FILE', 'MULTI_FILE'],
                    true
                )
            ) {
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

                $assessmentServiceValue =
                    AssessmentServiceValue::create([
                        'id' => (string) Str::uuid(),

                        'assessment_service_id' =>
                            $assessmentService->id,

                        'revenue_service_field_id' =>
                            $serviceField->id,

                        'field_code' =>
                            $normalizedCode,

                        'field_label' =>
                            $serviceField->label
                            ?: $baseField->label,

                        /*
                         * FILE is an input type.
                         *
                         * The underlying data type remains
                         * TEXT unless the service field explicitly
                         * defines another supported data type.
                         */
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
                $this->fileService->attachValueFiles(
                    $assessmentServiceValue,
                    $value
                );

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | NORMAL VALUES
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

            /*
            |--------------------------------------------------------------------------
            | CREATE VALUE SNAPSHOT
            |--------------------------------------------------------------------------
            */

            AssessmentServiceValue::create([
                'id' => (string) Str::uuid(),

                'assessment_service_id' =>
                    $assessmentService->id,

                'revenue_service_field_id' =>
                    $serviceField->id,

                'field_code' =>
                    $normalizedCode,

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
        }
    }

    /**
     * ============================================================
     * SERVICE RESOLUTION
     * ============================================================
     */

    /**
     * Resolve the revenue service using serviceId.
     *
     * serviceId is authoritative.
     *
     * serviceCode is validated against:
     *
     * RevenueService
     *      ↓
     * RevenueCode
     *      ↓
     * code
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

        $expectedCode = $service->revenueCode?->code;

        if ($expectedCode === null) {
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

        $submittedCode = $serviceData['serviceCode'] ?? null;

        if ($submittedCode !== null) {
            $submittedCode = trim(
                (string) $submittedCode
            );

            $expectedCode = trim(
                (string) $expectedCode
            );

            if (
                strtoupper($submittedCode)
                !== strtoupper($expectedCode)
            ) {
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
     *
     * Resolve effective data_type and input_type.
     *
     * Architecture:
     *
     * data_type
     * ----------
     * TEXT
     * NUMBER
     * DECIMAL
     * BOOLEAN
     * DATE
     * SELECT
     *
     * input_type
     * ----------
     * TEXT
     * NUMBER
     * DECIMAL
     * SELECT
     * RADIO
     * CHECKBOX
     * DATE
     * TEXTAREA
     * FILE
     * MULTI_FILE
     *
     * FILE is therefore an input type, not a data type.
     */
    protected function resolveFieldTypes(
        $serviceField
    ): array {
        $baseField = $serviceField->baseField;

        if (! $baseField) {
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

        $dataType = strtoupper(
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

        $inputType = strtoupper(
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
        |
        | If input_type is not configured, derive it from data_type.
        |
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
        | IMPORTANT FILE COMPATIBILITY
        |--------------------------------------------------------------------------
        |
        | Older configuration may have FILE stored in data_type.
        |
        | Treat that as a legacy configuration and normalize it to:
        |
        |     data_type  = TEXT
        |     input_type = FILE
        |
        | This prevents:
        |
        |     Unsupported data type [FILE]
        |
        | while preserving file upload behavior.
        |
        */

        if (
            in_array(
                $dataType,
                ['FILE', 'MULTI_FILE'],
                true
            )
        ) {
            $inputType = $dataType;

            $dataType = 'TEXT';
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

        if (! in_array(
            $dataType,
            $allowedDataTypes,
            true
        )) {
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

        if (! in_array(
            $inputType,
            $allowedInputTypes,
            true
        )) {
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
        if ($value === null || $value === '') {
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
                ['SELECT', 'RADIO'],
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
        | STANDARD DATA TYPES
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

        $submittedValue = trim(
            (string) $value
        );

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

        $options = $baseField->options;

        $matchingOption = $options->first(
            function ($option) use ($submittedValue) {
                return strtoupper(
                    trim((string) $option->value)
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

        return (string) $matchingOption->value;
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

        $baseField = $serviceField->baseField;

        if (! $baseField) {
            throw ValidationException::withMessages([
                'services' => [
                    "The field [{$serviceField->id}] does not have a base field.",
                ],
            ]);
        }

        $options = $baseField->options;

        $validValues = $options
            ->map(
                static fn ($option) => strtoupper(
                    trim((string) $option->value)
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

            $normalizedItem = trim(
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

            $normalizedValues[] = $normalizedItem;
        }

        return array_values(
            array_unique($normalizedValues)
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
            return Carbon::parse($value)
                ->toDateString();
        } catch (\Throwable) {
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

        return trim((string) $value);
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
                ['FILE', 'MULTI_FILE'],
                true
            )
        ) {
            if (is_array($value)) {
                return implode(
                    ', ',
                    array_map(
                        static fn ($item) => (string) $item,
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

        if ($types['data_type'] === 'BOOLEAN') {
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
                ['SELECT', 'RADIO'],
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
        $baseField = $serviceField->baseField;

        if (! $baseField) {
            return (string) $value;
        }

        $options = $baseField->options;

        $matchingOption = $options->first(
            function ($option) use ($value) {
                return strtoupper(
                    trim((string) $option->value)
                ) === strtoupper(
                    trim((string) $value)
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