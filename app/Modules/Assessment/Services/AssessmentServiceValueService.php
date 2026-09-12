<?php

namespace App\Modules\Assessment\Services;

use App\Models\Assessment;
use App\Models\AssessmentService as AssessmentServiceModel;
use App\Models\AssessmentServiceValue;
use App\Models\RevenueService;
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
     */

    /**
     * Store assessment services and their dynamic values.
     *
     * The service itself is responsible for:
     *
     * - Creating assessment_service records
     * - Resolving service fields
     * - Storing field snapshots
     * - Normalizing submitted values
     * - Generating display values
     * - Delegating file handling
     */
    public function storeServices(
        Assessment $assessment,
        array $services
    ): void {
        foreach ($services as $index => $serviceData) {
            $service = $this->resolveRevenueService(
                $serviceData
            );

            $assessmentService = AssessmentServiceModel::create([
                'id' => (string) \Illuminate\Support\Str::uuid(),

                'assessment_id' => $assessment->id,

                'service_id' => $service->id,

                'service_code' => $service->code,

                'service_order' => $index + 1,

                'status' => 'CAPTURED',

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
     */

    /**
     * Store dynamic values for one assessment service.
     */
    public function storeServiceValues(
        AssessmentServiceModel $assessmentService,
        array $submittedFields
    ): void {
        $service = RevenueService::query()
            ->with('fields.baseField')
            ->findOrFail($assessmentService->service_id);

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
                    (string) $serviceField->baseField->code
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
            | The service configuration is authoritative.
            |
            */

            if (! $fields->has($normalizedCode)) {
                continue;
            }

            $serviceField = $fields->get($normalizedCode);

            $baseField = $serviceField->baseField;

            /*
            |--------------------------------------------------------------------------
            | Resolve Types
            |--------------------------------------------------------------------------
            */

            $types = $this->resolveFieldTypes(
                $serviceField
            );

            /*
            |--------------------------------------------------------------------------
            | File Fields
            |--------------------------------------------------------------------------
            */

            if (in_array(
                $types['input_type'],
                ['FILE', 'MULTI_FILE'],
                true
            )) {
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
                    'id' => (string) \Illuminate\Support\Str::uuid(),

                    'assessment_service_id' => $assessmentService->id,

                    'revenue_service_field_id' => $serviceField->id,

                    'field_code' => $normalizedCode,

                    'field_label' => $serviceField->label
                        ?: $baseField->label,

                    'data_type' => $types['data_type'],

                    'input_type' => $types['input_type'],

                    'value' => $value,

                    'display_value' => $displayValue,

                    'measurement_unit' => $serviceField->measurement_unit
                        ?? $baseField->measurement_unit
                        ?? null,

                    'sort_order' => $serviceField->sort_order
                        ?? $baseField->sort_order
                        ?? 0,
                ]);

                $this->fileService->attachValueFiles(
                    $assessmentServiceValue,
                    $value
                );

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Normalize Value
            |--------------------------------------------------------------------------
            */

            $value = $this->normalizeFieldValue(
                $rawValue,
                $types['data_type'],
                $types['input_type'],
                $serviceField
            );

            /*
            |--------------------------------------------------------------------------
            | Display Value
            |--------------------------------------------------------------------------
            */

            $displayValue = $this->makeDisplayValue(
                $value,
                $types,
                $serviceField
            );

            /*
            |--------------------------------------------------------------------------
            | Create Snapshot
            |--------------------------------------------------------------------------
            */

            AssessmentServiceValue::create([
                'id' => (string) \Illuminate\Support\Str::uuid(),

                'assessment_service_id' => $assessmentService->id,

                'revenue_service_field_id' => $serviceField->id,

                'field_code' => $normalizedCode,

                'field_label' => $serviceField->label
                    ?: $baseField->label,

                'data_type' => $types['data_type'],

                'input_type' => $types['input_type'],

                'value' => $value,

                'display_value' => $displayValue,

                'measurement_unit' => $serviceField->measurement_unit
                    ?? $baseField->measurement_unit
                    ?? null,

                'sort_order' => $serviceField->sort_order
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
     * serviceCode is only validated against the resolved service.
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
            ->with('fields.baseField')
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
        | Validate Service Code
        |--------------------------------------------------------------------------
        */

        $submittedCode = $serviceData['serviceCode'] ?? null;

        if (
            $submittedCode !== null
            && strtoupper(trim((string) $submittedCode))
                !== strtoupper((string) $service->code)
        ) {
            throw ValidationException::withMessages([
                'services' => [
                    "The serviceCode does not match serviceId [{$serviceId}].",
                ],
            ]);
        }

        return $service;
    }

    /**
     * ============================================================
     * FIELD TYPES
     * ============================================================
     */

    /**
     * Resolve the effective data and input types.
     */
    protected function resolveFieldTypes(
        $serviceField
    ): array {
        $baseField = $serviceField->baseField;

        $dataType = strtoupper(
            (string) (
                $serviceField->data_type
                ?? $baseField->data_type
                ?? 'TEXT'
            )
        );

        $inputType = strtoupper(
            (string) (
                $serviceField->input_type
                ?? $baseField->input_type
                ?? 'TEXT'
            )
        );

        $allowedDataTypes = [
            'NUMBER',
            'DECIMAL',
            'TEXT',
            'BOOLEAN',
            'DATE',
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
     * ============================================================
     * NORMALIZE VALUE
     * ============================================================
     */

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

        return match ($dataType) {
            'NUMBER' => $this->normalizeNumber($value),

            'DECIMAL' => $this->normalizeDecimal($value),

            'BOOLEAN' => $this->normalizeBoolean($value),

            'DATE' => $this->normalizeDate($value),

            'TEXT' => $this->normalizeText($value),

            default => $value,
        };
    }

    /**
     * Normalize integer-like values.
     */
    protected function normalizeNumber(mixed $value): int
    {
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
     * Normalize decimal values.
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
     * Normalize boolean values.
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
     * Normalize date values.
     */
    protected function normalizeDate(mixed $value): string
    {
        try {
            return \Carbon\Carbon::parse($value)
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
     * Normalize text values.
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
     * ============================================================
     * DISPLAY VALUE
     * ============================================================
     */

    /**
     * Generate human-readable display value.
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

            return implode(', ', array_map(
                static fn ($item) => (string) $item,
                $value
            ));
        }

        if (
            $types['input_type'] === 'FILE'
            || $types['input_type'] === 'MULTI_FILE'
        ) {
            if (is_array($value)) {
                return implode(', ', array_map(
                    static fn ($item) => (string) $item,
                    $value
                ));
            }

            return (string) $value;
        }

        if ($types['data_type'] === 'BOOLEAN') {
            return $value ? 'Yes' : 'No';
        }

        /*
        |--------------------------------------------------------------------------
        | Select / Radio
        |--------------------------------------------------------------------------
        |
        | Keep the submitted option value as the display value unless
        | the field configuration provides a matching option label.
        |
        */

        if (in_array(
            $types['input_type'],
            ['SELECT', 'RADIO'],
            true
        )) {
            $options = $serviceField->options ?? [];

            if (is_array($options)) {
                foreach ($options as $option) {
                    if (! is_array($option)) {
                        continue;
                    }

                    if (
                        isset($option['value'])
                        && (string) $option['value'] === (string) $value
                    ) {
                        return (string) (
                            $option['label']
                            ?? $option['value']
                        );
                    }
                }
            }
        }

        return is_scalar($value)
            ? (string) $value
            : json_encode(
                $value,
                JSON_UNESCAPED_UNICODE
            );
    }
}