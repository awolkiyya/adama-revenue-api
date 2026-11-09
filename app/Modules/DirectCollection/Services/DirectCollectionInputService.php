<?php

declare(strict_types=1);

namespace App\Modules\DirectCollection\Services;

use App\Models\Citizen;
use App\Models\RevenueService;
use App\Models\RevenueServiceField;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class DirectCollectionInputService
{
    /**
     * Resolve taxpayer, service, configured fields and collection entries.
     *
     * Supported formats:
     *
     * New:
     * [
     *     'taxpayer_id' => 'uuid',
     *     'revenue_service_id' => 'uuid',
     *     'entries' => [
     *         ['fields' => ['field-uuid' => 5]],
     *         ['fields' => ['field-uuid' => 10]],
     *     ],
     * ]
     *
     * Legacy:
     * [
     *     'taxpayer_id' => 'uuid',
     *     'revenue_service_id' => 'uuid',
     *     'fields' => ['field-uuid' => 5],
     * ]
     *
     * @param array<string,mixed> $data
     *
     * @return array{
     *     taxpayer: Citizen,
     *     service: RevenueService,
     *     fields: Collection<int,RevenueServiceField>,
     *     entries: array<int,array<string,mixed>>,
     *     inputs: array<string,mixed>
     * }
     */
    public function resolve(array $data): array
    {
        $taxpayerId =
            $data['taxpayer_id']
            ?? $data['citizen_id']
            ?? null;

        $serviceId =
            $data['revenue_service_id']
            ?? $data['service_id']
            ?? null;

        /*
        |--------------------------------------------------------------------------
        | Validate Identifiers
        |--------------------------------------------------------------------------
        */

        if (
            ! is_string($taxpayerId)
            && ! is_int($taxpayerId)
        ) {
            throw ValidationException::withMessages([
                'taxpayer_id' => [
                    'A valid taxpayer identifier is required.',
                ],
            ]);
        }

        if (trim((string) $taxpayerId) === '') {
            throw ValidationException::withMessages([
                'taxpayer_id' => [
                    'Taxpayer is required.',
                ],
            ]);
        }

        if (
            ! is_string($serviceId)
            && ! is_int($serviceId)
        ) {
            throw ValidationException::withMessages([
                'revenue_service_id' => [
                    'A valid revenue service identifier is required.',
                ],
            ]);
        }

        if (trim((string) $serviceId) === '') {
            throw ValidationException::withMessages([
                'revenue_service_id' => [
                    'Revenue service is required.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Resolve Taxpayer
        |--------------------------------------------------------------------------
        */

        $taxpayer = Citizen::query()->find($taxpayerId);

        if (! $taxpayer) {
            throw ValidationException::withMessages([
                'taxpayer_id' => [
                    'The selected taxpayer could not be found.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Resolve Revenue Service
        |--------------------------------------------------------------------------
        */

        $service = RevenueService::query()->find($serviceId);

        if (! $service) {
            throw ValidationException::withMessages([
                'revenue_service_id' => [
                    'The selected revenue service could not be found.',
                ],
            ]);
        }

        $this->ensureServiceIsAvailable($service);

        /*
        |--------------------------------------------------------------------------
        | Check Multiple Entry Configuration
        |--------------------------------------------------------------------------
        */

        $allowMultiple = (bool) (
            $service->allow_multiple ?? false
        );

        /*
        |--------------------------------------------------------------------------
        | Load Configured Fields
        |--------------------------------------------------------------------------
        */

        $fields = $this->getServiceFields($service);

        /*
        |--------------------------------------------------------------------------
        | Normalize Entries
        |--------------------------------------------------------------------------
        */

        $entries = $this->normalizeEntries($data);

        if ($entries === []) {
            throw ValidationException::withMessages([
                'entries' => [
                    'At least one collection entry is required.',
                ],
            ]);
        }

        if (! $allowMultiple && count($entries) > 1) {
            throw ValidationException::withMessages([
                'entries' => [
                    'This revenue service allows only one collection entry.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Validate Every Entry
        |--------------------------------------------------------------------------
        */

        $resolvedEntries = [];

        foreach ($entries as $index => $entry) {
            if (! is_array($entry)) {
                throw ValidationException::withMessages([
                    "entries.{$index}" => [
                        'Each collection entry must be an object.',
                    ],
                ]);
            }

            $entryFields = $entry['fields'] ?? null;

            if (! is_array($entryFields)) {
                throw ValidationException::withMessages([
                    "entries.{$index}.fields" => [
                        'Revenue service fields must be provided as an object.',
                    ],
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Normalize Values Using Configured Field Types
            |--------------------------------------------------------------------------
            */

            $inputs = $this->normalizeInputs(
                fields: $fields,
                inputs: $entryFields,
            );

            /*
            |--------------------------------------------------------------------------
            | Validate Against Service Configuration
            |--------------------------------------------------------------------------
            */

            $this->validateInputsAgainstServiceFields(
                fields: $fields,
                inputs: $inputs,
                errorPrefix: "entries.{$index}.fields",
            );

            $resolvedEntries[] = [
                'index' => (int) $index,
                'fields' => $inputs,
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Return Resolved Context
        |--------------------------------------------------------------------------
        */

        return [
            'taxpayer' => $taxpayer,
            'service' => $service,
            'fields' => $fields,
            'entries' => $resolvedEntries,

            // Backward compatibility for existing single-entry consumers.
            'inputs' => $resolvedEntries[0]['fields'],
        ];
    }

    /**
     * Normalize both new and legacy request formats.
     *
     * @param array<string,mixed> $data
     * @return array<int,mixed>
     */
    protected function normalizeEntries(array $data): array
    {
        /*
        |--------------------------------------------------------------------------
        | New Multi-Entry Format
        |--------------------------------------------------------------------------
        */

        if (array_key_exists('entries', $data)) {
            if (! is_array($data['entries'])) {
                throw ValidationException::withMessages([
                    'entries' => [
                        'Collection entries must be provided as an array.',
                    ],
                ]);
            }

            return array_values($data['entries']);
        }

        /*
        |--------------------------------------------------------------------------
        | Legacy Single-Entry Format
        |--------------------------------------------------------------------------
        */

        $inputs =
            $data['fields']
            ?? $data['inputs']
            ?? [];

        if (! is_array($inputs)) {
            throw ValidationException::withMessages([
                'fields' => [
                    'Revenue service fields must be provided as an object.',
                ],
            ]);
        }

        return [
            [
                'fields' => $inputs,
            ],
        ];
    }

    /**
     * Ensure the selected revenue service is available.
     */
    protected function ensureServiceIsAvailable(
        RevenueService $service
    ): void {
        if (
            isset($service->is_active)
            && ! $service->is_active
        ) {
            throw ValidationException::withMessages([
                'revenue_service_id' => [
                    'The selected revenue service is not active.',
                ],
            ]);
        }

        if (
            isset($service->status)
            && in_array(
                strtoupper((string) $service->status),
                [
                    'INACTIVE',
                    'DISABLED',
                    'ARCHIVED',
                ],
                true
            )
        ) {
            throw ValidationException::withMessages([
                'revenue_service_id' => [
                    'The selected revenue service is not available.',
                ],
            ]);
        }
    }

    /**
     * Load configured fields for the selected service.
     *
     * @return Collection<int,RevenueServiceField>
     */
    protected function getServiceFields(
        RevenueService $service
    ): Collection {
        if (method_exists($service, 'fields')) {
            return $service
                ->fields()
                ->orderBy('sort_order')
                ->get();
        }

        return RevenueServiceField::query()
            ->where('service_id', $service->id)
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * Normalize submitted values according to configured field types.
     *
     * Important:
     * - Numeric-looking TEXT values remain strings.
     * - Identifiers and phone numbers preserve leading zeros.
     * - Numeric fields are converted only when their configured type
     *   requires numeric input.
     * - Boolean fields are normalized to actual booleans.
     *
     * @param Collection<int,RevenueServiceField> $fields
     * @param array<string,mixed> $inputs
     * @return array<string,mixed>
     */
    protected function normalizeInputs(
        Collection $fields,
        array $inputs
    ): array {
        $normalized = [];

        $fieldMap = [];

        foreach ($fields as $field) {
            $fieldMap[$this->fieldInputKey($field)] = $field;
        }

        foreach ($inputs as $key => $value) {
            $key = (string) $key;

            if (is_string($value)) {
                $value = trim($value);
            }

            $field = $fieldMap[$key] ?? null;

            /*
            |--------------------------------------------------------------------------
            | Unknown Fields
            |--------------------------------------------------------------------------
            |
            | Preserve them here so the validation method can return
            | a proper unknown-field validation error.
            |
            */

            if (! $field) {
                $normalized[$key] = $value;
                continue;
            }

            $dataType = strtoupper(
                trim((string) ($field->data_type ?? ''))
            );

            /*
            |--------------------------------------------------------------------------
            | Integer Fields
            |--------------------------------------------------------------------------
            */

            if (in_array($dataType, ['NUMBER', 'INTEGER'], true)) {
                if (
                    is_string($value)
                    && preg_match('/^-?\d+$/', $value)
                ) {
                    $normalized[$key] = $this->normalizeInteger($value);
                } else {
                    $normalized[$key] = $value;
                }

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Decimal Fields
            |--------------------------------------------------------------------------
            */

            if (
                in_array(
                    $dataType,
                    [
                        'DECIMAL',
                        'FLOAT',
                        'NUMBER_DECIMAL',
                    ],
                    true
                )
            ) {
                if (
                    is_string($value)
                    && is_numeric($value)
                ) {
                    $normalized[$key] = (float) $value;
                } else {
                    $normalized[$key] = $value;
                }

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Boolean Fields
            |--------------------------------------------------------------------------
            */

            if ($dataType === 'BOOLEAN') {
                if (
                    is_string($value)
                    && in_array(
                        strtolower($value),
                        ['true', 'false'],
                        true
                    )
                ) {
                    $normalized[$key] =
                        strtolower($value) === 'true';
                } elseif ($value === 1 || $value === '1') {
                    $normalized[$key] = true;
                } elseif ($value === 0 || $value === '0') {
                    $normalized[$key] = false;
                } else {
                    $normalized[$key] = $value;
                }

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Text, Date and Other Fields
            |--------------------------------------------------------------------------
            */

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /**
     * Safely normalize integer values without integer overflow.
     */
    protected function normalizeInteger(string $value): int|string
    {
        $integer = filter_var(
            $value,
            FILTER_VALIDATE_INT
        );

        return $integer === false
            ? $value
            : $integer;
    }

    /**
     * Validate submitted values against configured service fields.
     *
     * RevenueServiceField.id is the canonical API input key.
     *
     * @param Collection<int,RevenueServiceField> $fields
     * @param array<string,mixed> $inputs
     */
    protected function validateInputsAgainstServiceFields(
        Collection $fields,
        array $inputs,
        string $errorPrefix = 'fields',
    ): void {
        $errors = [];

        $allowedKeys = [];

        foreach ($fields as $field) {
            $allowedKeys[] = $this->fieldInputKey($field);
        }

        /*
        |--------------------------------------------------------------------------
        | Reject Unknown Fields
        |--------------------------------------------------------------------------
        */

        foreach ($inputs as $key => $value) {
            $key = (string) $key;

            if (! in_array($key, $allowedKeys, true)) {
                $errors["{$errorPrefix}.{$key}"][] =
                    'This field is not configured for the selected revenue service.';
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Validate Configured Fields
        |--------------------------------------------------------------------------
        */

        foreach ($fields as $field) {
            $key = $this->fieldInputKey($field);

            $exists = array_key_exists($key, $inputs);

            if (
                $this->fieldIsRequired($field)
                && (
                    ! $exists
                    || $this->isEmptyValue($inputs[$key] ?? null)
                )
            ) {
                $errors["{$errorPrefix}.{$key}"][] =
                    'This field is required.';

                continue;
            }

            if (
                ! $exists
                || $this->isEmptyValue($inputs[$key] ?? null)
            ) {
                continue;
            }

            $value = $inputs[$key];

            $dataType = strtoupper(
                trim((string) ($field->data_type ?? ''))
            );

            switch ($dataType) {
                case 'NUMBER':
                case 'INTEGER':

                    if (
                        ! is_int($value)
                        && ! (
                            is_string($value)
                            && preg_match('/^-?\d+$/', $value)
                        )
                    ) {
                        $errors["{$errorPrefix}.{$key}"][] =
                            'The value must be an integer.';
                    }

                    break;

                case 'DECIMAL':
                case 'FLOAT':
                case 'NUMBER_DECIMAL':

                    if (
                        ! is_numeric($value)
                        || is_array($value)
                        || is_object($value)
                    ) {
                        $errors["{$errorPrefix}.{$key}"][] =
                            'The value must be numeric.';
                    }

                    break;

                case 'BOOLEAN':

                    if (
                        ! is_bool($value)
                        && ! in_array(
                            $value,
                            [
                                0,
                                1,
                                '0',
                                '1',
                            ],
                            true
                        )
                    ) {
                        $errors["{$errorPrefix}.{$key}"][] =
                            'The value must be boolean.';
                    }

                    break;

                case 'DATE':

                    if (! $this->isValidDate($value)) {
                        $errors["{$errorPrefix}.{$key}"][] =
                            'The value must be a valid date in YYYY-MM-DD format.';
                    }

                    break;

                case 'TEXT':
                case 'STRING':
                default:

                    if (
                        is_array($value)
                        || is_object($value)
                    ) {
                        $errors["{$errorPrefix}.{$key}"][] =
                            'The value must be text.';
                    }

                    break;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Canonical input key for a configured field.
     */
    protected function fieldInputKey(
        RevenueServiceField $field
    ): string {
        return (string) $field->id;
    }

    /**
     * Determine whether a field is required.
     */
    protected function fieldIsRequired(
        RevenueServiceField $field
    ): bool {
        if (isset($field->is_required)) {
            return (bool) $field->is_required;
        }

        if (isset($field->required)) {
            return (bool) $field->required;
        }

        return false;
    }

    /**
     * Determine whether a submitted value is empty.
     */
    protected function isEmptyValue(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        if (is_array($value)) {
            return $value === [];
        }

        return false;
    }

    /**
     * Validate YYYY-MM-DD date.
     */
    protected function isValidDate(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        $date = \DateTime::createFromFormat('!Y-m-d', $value);

        return $date !== false
            && $date->format('Y-m-d') === $value;
    }
}
