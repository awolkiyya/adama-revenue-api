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
     * Resolve and validate all direct collection inputs.
     *
     * @param array{
     *     taxpayer_id?:string,
     *     citizen_id?:string,
     *     revenue_service_id?:string,
     *     service_id?:string,
     *     fields?:array<string,mixed>,
     *     inputs?:array<string,mixed>,
     *     administrative_unit_id?:string|null,
     *     due_date?:string|null,
     *     notes?:string|null,
     *     metadata?:array<string,mixed>|null
     * } $data
     *
     * @return array{
     *     taxpayer: Citizen,
     *     service: RevenueService,
     *     fields: Collection<int, RevenueServiceField>,
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
        | Taxpayer
        |--------------------------------------------------------------------------
        */

        if (! $taxpayerId) {
            throw ValidationException::withMessages([
                'taxpayer_id' => [
                    'Taxpayer is required.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Revenue Service
        |--------------------------------------------------------------------------
        */

        if (! $serviceId) {
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

        /** @var Citizen|null $taxpayer */
        $taxpayer = Citizen::query()
            ->find($taxpayerId);

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

        /** @var RevenueService|null $service */
        $service = RevenueService::query()
            ->find($serviceId);

        if (! $service) {
            throw ValidationException::withMessages([
                'revenue_service_id' => [
                    'The selected revenue service could not be found.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Service Availability
        |--------------------------------------------------------------------------
        */

        $this->ensureServiceIsAvailable(
            $service
        );

        /*
        |--------------------------------------------------------------------------
        | Load Configured Fields
        |--------------------------------------------------------------------------
        */

        $fields =
            $this->getServiceFields(
                $service
            );

        /*
        |--------------------------------------------------------------------------
        | Raw Inputs
        |--------------------------------------------------------------------------
        */

        $rawInputs =
            $data['fields']
            ?? $data['inputs']
            ?? [];

        if (! is_array($rawInputs)) {
            throw ValidationException::withMessages([
                'fields' => [
                    'Revenue service fields must be provided as an object.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Normalize Inputs
        |--------------------------------------------------------------------------
        */

        $inputs =
            $this->normalizeInputs(
                $rawInputs
            );

        /*
        |--------------------------------------------------------------------------
        | Validate Inputs
        |--------------------------------------------------------------------------
        */

        $this->validateInputsAgainstServiceFields(
            $fields,
            $inputs
        );

        return [
            'taxpayer' => $taxpayer,
            'service' => $service,
            'fields' => $fields,
            'inputs' => $inputs,
        ];
    }

    /**
     * Ensure the selected revenue service can be collected.
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
                strtoupper(
                    (string) $service->status
                ),
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
     * @return Collection<int, RevenueServiceField>
     */
    protected function getServiceFields(
        RevenueService $service
    ): Collection {
        /*
        |--------------------------------------------------------------------------
        | Preferred relationship
        |--------------------------------------------------------------------------
        */

        if (method_exists($service, 'fields')) {
            return $service
                ->fields()
                ->orderBy('sort_order')
                ->get();
        }

        /*
        |--------------------------------------------------------------------------
        | Fallback
        |--------------------------------------------------------------------------
        */

        return RevenueServiceField::query()
            ->where(
                'service_id',
                $service->id
            )
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * Normalize submitted field values.
     *
     * @param array<string,mixed> $inputs
     * @return array<string,mixed>
     */
    protected function normalizeInputs(
        array $inputs
    ): array {
        $normalized = [];

        foreach ($inputs as $key => $value) {
            $key = (string) $key;

            /*
            |--------------------------------------------------------------------------
            | Boolean
            |--------------------------------------------------------------------------
            */

            if (
                $value === 'true'
                || $value === 'false'
            ) {
                $normalized[$key] =
                    $value === 'true';

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Numeric
            |--------------------------------------------------------------------------
            */

            if (
                is_string($value)
                && is_numeric($value)
            ) {
                $normalized[$key] =
                    $this->normalizeNumericValue(
                        trim($value)
                    );

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Text
            |--------------------------------------------------------------------------
            */

            if (is_string($value)) {
                $normalized[$key] =
                    trim($value);

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Other
            |--------------------------------------------------------------------------
            */

            $normalized[$key] =
                $value;
        }

        return $normalized;
    }

    /**
     * @return int|float|string
     */
    protected function normalizeNumericValue(
        string $value
    ): int|float|string {
        if (
            preg_match(
                '/^-?\d+$/',
                $value
            )
        ) {
            return (int) $value;
        }

        return (float) $value;
    }

    /**
     * Validate submitted inputs against configured service fields.
     *
     * IMPORTANT:
     * RevenueServiceField.id is the canonical API input key.
     *
     * @param Collection<int, RevenueServiceField> $fields
     * @param array<string,mixed> $inputs
     */
    protected function validateInputsAgainstServiceFields(
        Collection $fields,
        array $inputs
    ): void {
        $errors = [];

        /*
        |--------------------------------------------------------------------------
        | Build allowed field IDs
        |--------------------------------------------------------------------------
        */

        $allowedKeys = [];

        foreach ($fields as $field) {
            $allowedKeys[] =
                $this->fieldInputKey(
                    $field
                );
        }

        /*
        |--------------------------------------------------------------------------
        | Reject Unknown Fields
        |--------------------------------------------------------------------------
        */

        foreach ($inputs as $key => $value) {
            $key = (string) $key;

            if (
                ! in_array(
                    $key,
                    $allowedKeys,
                    true
                )
            ) {
                $errors[$key][] =
                    'This field is not configured for the selected revenue service.';
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Validate Configured Fields
        |--------------------------------------------------------------------------
        */

        foreach ($fields as $field) {
            $key =
                $this->fieldInputKey(
                    $field
                );

            $exists =
                array_key_exists(
                    $key,
                    $inputs
                );

            /*
            |--------------------------------------------------------------------------
            | Required
            |--------------------------------------------------------------------------
            */

            if (
                $this->fieldIsRequired($field)
                && (
                    ! $exists
                    || $this->isEmptyValue(
                        $inputs[$key] ?? null
                    )
                )
            ) {
                $errors[$key][] =
                    'This field is required.';

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Optional Empty
            |--------------------------------------------------------------------------
            */

            if (
                ! $exists
                || $this->isEmptyValue(
                    $inputs[$key] ?? null
                )
            ) {
                continue;
            }

            $value =
                $inputs[$key];

            $dataType =
                strtoupper(
                    trim(
                        (string) (
                            $field->data_type
                            ?? ''
                        )
                    )
                );

            /*
            |--------------------------------------------------------------------------
            | Data Type
            |--------------------------------------------------------------------------
            */

            switch ($dataType) {
                case 'NUMBER':
                case 'INTEGER':

                    if (
                        filter_var(
                            $value,
                            FILTER_VALIDATE_INT
                        ) === false
                    ) {
                        $errors[$key][] =
                            'The value must be an integer.';
                    }

                    break;

                case 'DECIMAL':
                case 'FLOAT':
                case 'NUMBER_DECIMAL':

                    if (! is_numeric($value)) {
                        $errors[$key][] =
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
                                'true',
                                'false',
                            ],
                            true
                        )
                    ) {
                        $errors[$key][] =
                            'The value must be boolean.';
                    }

                    break;

                case 'DATE':

                    if (
                        ! $this->isValidDate($value)
                    ) {
                        $errors[$key][] =
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
                        $errors[$key][] =
                            'The value must be text.';
                    }

                    break;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Throw Errors
        |--------------------------------------------------------------------------
        */

        if ($errors !== []) {
            throw ValidationException::withMessages(
                $errors
            );
        }
    }

    /**
     * RevenueServiceField.id is the canonical input key.
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
    protected function isEmptyValue(
        mixed $value
    ): bool {
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
    protected function isValidDate(
        mixed $value
    ): bool {
        if (! is_string($value)) {
            return false;
        }

        $date =
            \DateTime::createFromFormat(
                'Y-m-d',
                $value
            );

        return $date !== false
            && $date->format('Y-m-d') === $value;
    }
}