<?php

namespace App\Modules\DirectCollection\Services;

use App\Models\Citizen;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\RevenueService;
use App\Models\RevenueServiceField;
use App\Services\Revenue\DecisionProviderInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class DirectCollectionService
{
    public function __construct(
        protected DecisionProviderInterface $decisionProvider,
    ) {
    }

    /**
     * Create and issue a direct-collection invoice.
     *
     * Direct Collection intentionally does NOT:
     *
     * - create an Assessment
     * - create AssessmentService
     * - create AssessmentServiceValue
     * - create FieldCollection
     * - create ServiceCapture
     * - create ServiceCaptureValue
     *
     * Flow:
     *
     * Citizen
     *   ↓
     * Revenue Service
     *   ↓
     * Dynamic Inputs
     *   ↓
     * Decision Provider
     *   ↓
     * Invoice
     *   ↓
     * Invoice Item
     *
     * @param array{
     *     citizen_id:string,
     *     service_id:string,
     *     inputs?:array<string,mixed>,
     *     administrative_unit_id?:string|null,
     *     due_date?:string|null,
     *     notes?:string|null,
     *     metadata?:array<string,mixed>|null
     * } $data
     *
     * @return Invoice
     *
     * @throws ValidationException
     * @throws Throwable
     */
    public function create(array $data): Invoice
    {
        return DB::transaction(
            function () use ($data): Invoice {

                /*
                |--------------------------------------------------------------------------
                | 1. Resolve authenticated user
                |--------------------------------------------------------------------------
                */

                $user = Auth::user();

                if (!$user) {
                    throw ValidationException::withMessages([
                        'user' => 'An authenticated user is required to create a direct collection.',
                    ]);
                }


                /*
                |--------------------------------------------------------------------------
                | 2. Validate required identifiers
                |--------------------------------------------------------------------------
                */

                $citizenId = $data['citizen_id'] ?? null;
                $serviceId = $data['service_id'] ?? null;

                if (!$citizenId) {
                    throw ValidationException::withMessages([
                        'citizen_id' => 'Citizen is required.',
                    ]);
                }

                if (!$serviceId) {
                    throw ValidationException::withMessages([
                        'service_id' => 'Revenue service is required.',
                    ]);
                }


                /*
                |--------------------------------------------------------------------------
                | 3. Lock and resolve citizen
                |--------------------------------------------------------------------------
                |
                | Locking prevents the citizen record from being modified
                | while the transaction is constructing the invoice.
                |
                */

                /** @var Citizen|null $citizen */
                $citizen = Citizen::query()
                    ->lockForUpdate()
                    ->find($citizenId);

                if (!$citizen) {
                    throw ValidationException::withMessages([
                        'citizen_id' => 'The selected citizen could not be found.',
                    ]);
                }


                /*
                |--------------------------------------------------------------------------
                | 4. Resolve revenue service
                |--------------------------------------------------------------------------
                |
                | The service must be active.
                |
                */

                /** @var RevenueService|null $service */
                $service = RevenueService::query()
                    ->lockForUpdate()
                    ->find($serviceId);

                if (!$service) {
                    throw ValidationException::withMessages([
                        'service_id' => 'The selected revenue service could not be found.',
                    ]);
                }

                $this->ensureServiceIsAvailable($service);


                /*
                |--------------------------------------------------------------------------
                | 5. Normalize input values
                |--------------------------------------------------------------------------
                |
                | Never use the frontend's calculated amount.
                |
                | Only the submitted service inputs are accepted.
                |
                */

                $inputs = $this->normalizeInputs(
                    $data['inputs'] ?? []
                );


                /*
                |--------------------------------------------------------------------------
                | 6. Validate dynamic service fields
                |--------------------------------------------------------------------------
                |
                | The fields are defined by revenue_service_fields.
                |
                */

                /** @var Collection<int, RevenueServiceField> $fields */
                $fields = $this->getServiceFields($service);

                $this->validateInputsAgainstServiceFields(
                    $fields,
                    $inputs
                );


                /*
                |--------------------------------------------------------------------------
                | 7. Build calculation request
                |--------------------------------------------------------------------------
                |
                | The Decision Provider is the authoritative source for
                | the amount.
                |
                */

                $calculationRequest = [
                    'service_id' => $service->id,

                    'citizen_id' => $citizen->id,

                    'inputs' => $inputs,

                    'context' => [
                        'source_type' => 'DIRECT_COLLECTION',
                        'user_id' => $user->id,
                        'administrative_unit_id' =>
                            $data['administrative_unit_id'] ?? null,
                    ],
                ];


                /*
                |--------------------------------------------------------------------------
                | 8. Calculate amount
                |--------------------------------------------------------------------------
                |
                | IMPORTANT:
                |
                | We never accept:
                |
                | $data['amount']
                |
                | from the frontend.
                |
                | The backend Decision Provider calculates the amount.
                |
                */

                $decision = $this->calculate(
                    $calculationRequest
                );


                /*
                |--------------------------------------------------------------------------
                | 9. Validate calculation result
                |--------------------------------------------------------------------------
                */

                $this->validateCalculationResult(
                    $decision
                );


                /*
                |--------------------------------------------------------------------------
                | 10. Extract authoritative financial values
                |--------------------------------------------------------------------------
                */

                $amount = $this->decimal(
                    $decision['amount']
                );

                $currency = strtoupper(
                    $decision['currency']
                        ?? $service->currency_code
                        ?? 'ETB'
                );

                $tariffVersionId =
                    $decision['tariff_version_id']
                    ?? null;

                $tariffRuleId =
                    $decision['tariff_rule_id']
                    ?? null;


                /*
                |--------------------------------------------------------------------------
                | 11. Build calculation snapshot
                |--------------------------------------------------------------------------
                |
                | This becomes an immutable historical representation of
                | the decision used to generate the invoice.
                |
                */

                $calculationSnapshot = $this->buildCalculationSnapshot(
                    decision: $decision,
                    inputs: $inputs,
                );


                /*
                |--------------------------------------------------------------------------
                | 12. Build input snapshot
                |--------------------------------------------------------------------------
                |
                | Store the values used for this specific invoice.
                |
                */

                $inputSnapshot = $this->buildInputSnapshot(
                    fields: $fields,
                    inputs: $inputs,
                );


                /*
                |--------------------------------------------------------------------------
                | 13. Generate invoice number
                |--------------------------------------------------------------------------
                */

                $invoiceNumber = $this->generateInvoiceNumber();


                /*
                |--------------------------------------------------------------------------
                | 14. Create invoice
                |--------------------------------------------------------------------------
                |
                | Direct Collection has:
                |
                | source_type = DIRECT_COLLECTION
                | assessment_id = NULL
                |
                */

                $invoice = Invoice::query()->create([
                    'invoice_number' => $invoiceNumber,

                    'source_type' => 'DIRECT_COLLECTION',

                    'assessment_id' => null,

                    'citizen_id' => $citizen->id,

                    'administrative_unit_id' =>
                        $data['administrative_unit_id'] ?? null,

                    'status' => 'DRAFT',

                    'currency' => $currency,

                    'subtotal' => $amount,

                    'discount_amount' => 0,

                    'penalty_amount' => 0,

                    'interest_amount' => 0,

                    'total_amount' => $amount,

                    'paid_amount' => 0,

                    'balance_due' => $amount,

                    'issued_at' => null,

                    'due_date' =>
                        $data['due_date'] ?? null,

                    'paid_at' => null,

                    'cancelled_at' => null,

                    'cancelled_by' => null,

                    'cancellation_reason' => null,

                    'voided_at' => null,

                    'voided_by' => null,

                    'void_reason' => null,

                    'notes' => $data['notes'] ?? null,

                    'created_by' => $user->id,

                    'issued_by' => null,

                    'source_metadata' => [
                        'source' => 'DIRECT_COLLECTION',

                        'service_id' => $service->id,

                        'service_code' =>
                            $service->code ?? null,

                        'citizen_id' => $citizen->id,

                        'created_by' => $user->id,

                        'created_at' => now()->toISOString(),

                        'metadata' =>
                            $data['metadata'] ?? null,
                    ],
                ]);


                /*
                |--------------------------------------------------------------------------
                | 15. Create invoice item
                |--------------------------------------------------------------------------
                |
                | This is where the direct collection becomes financially
                | represented on the invoice.
                |
                */

                $invoiceItem = InvoiceItem::query()->create([
                    'invoice_id' => $invoice->id,

                    /*
                    |--------------------------------------------------------------------------
                    | No Assessment
                    |--------------------------------------------------------------------------
                    */

                    'assessment_service_id' => null,

                    'service_id' => $service->id,

                    'line_number' => 1,

                    'description' =>
                        $this->buildDescription($service),

                    'quantity' =>
                        $this->extractQuantity($decision),

                    'unit' =>
                        $this->extractUnit(
                            $decision,
                            $service
                        ),

                    'unit_price' =>
                        $this->extractUnitPrice($decision),

                    /*
                    |--------------------------------------------------------------------------
                    | Authoritative amount
                    |--------------------------------------------------------------------------
                    */

                    'amount' => $amount,

                    'discount_amount' => 0,

                    'penalty_amount' => 0,

                    'interest_amount' => 0,

                    'total_amount' => $amount,

                    'currency' => $currency,

                    /*
                    |--------------------------------------------------------------------------
                    | Tariff references
                    |--------------------------------------------------------------------------
                    */

                    'tariff_version_id' =>
                        $tariffVersionId,

                    'tariff_rule_id' =>
                        $tariffRuleId,

                    /*
                    |--------------------------------------------------------------------------
                    | Immutable snapshots
                    |--------------------------------------------------------------------------
                    */

                    'input_snapshot' =>
                        $inputSnapshot,

                    'calculation_snapshot' =>
                        $calculationSnapshot,
                ]);


                /*
                |--------------------------------------------------------------------------
                | 16. Issue invoice
                |--------------------------------------------------------------------------
                |
                | Direct Collection does not require an approval workflow.
                |
                | Therefore:
                |
                | DRAFT → ISSUED
                |
                */

                $invoice->forceFill([
                    'status' => 'ISSUED',

                    'issued_at' => now(),

                    'issued_by' => $user->id,

                    'balance_due' => $amount,
                ])->save();


                /*
                |--------------------------------------------------------------------------
                | 17. Log successful creation
                |--------------------------------------------------------------------------
                */

                Log::info(
                    'Direct collection invoice created.',
                    [
                        'invoice_id' => $invoice->id,

                        'invoice_number' =>
                            $invoice->invoice_number,

                        'citizen_id' =>
                            $citizen->id,

                        'service_id' =>
                            $service->id,

                        'invoice_item_id' =>
                            $invoiceItem->id,

                        'amount' => $amount,

                        'currency' => $currency,

                        'created_by' =>
                            $user->id,
                    ]
                );


                /*
                |--------------------------------------------------------------------------
                | 18. Return fresh invoice
                |--------------------------------------------------------------------------
                */

                return $invoice->fresh([
                    'citizen',
                    'items',
                    'items.service',
                ]);
            },

            attempts: 3
        );
    }


    /**
     * Ensure the revenue service is available for collection.
     */
    protected function ensureServiceIsAvailable(
        RevenueService $service
    ): void {
        /*
        |--------------------------------------------------------------------------
        | Adapt this check to your actual RevenueService status column.
        |--------------------------------------------------------------------------
        */

        if (
            isset($service->is_active)
            && !$service->is_active
        ) {
            throw ValidationException::withMessages([
                'service_id' =>
                    'The selected revenue service is not active.',
            ]);
        }

        if (
            isset($service->status)
            && in_array(
                strtoupper((string) $service->status),
                ['INACTIVE', 'DISABLED', 'ARCHIVED'],
                true
            )
        ) {
            throw ValidationException::withMessages([
                'service_id' =>
                    'The selected revenue service is not available.',
            ]);
        }
    }


    /**
     * Get configured dynamic fields for the revenue service.
     */
    protected function getServiceFields(
        RevenueService $service
    ): Collection {
        /*
        |--------------------------------------------------------------------------
        | Prefer an existing relationship if your model has one.
        |--------------------------------------------------------------------------
        */

        if (
            method_exists(
                $service,
                'fields'
            )
        ) {
            return $service->fields()
                ->orderBy('sort_order')
                ->get();
        }

        /*
        |--------------------------------------------------------------------------
        | Fallback query.
        |--------------------------------------------------------------------------
        */

        return RevenueServiceField::query()
            ->where('service_id', $service->id)
            ->orderBy('sort_order')
            ->get();
    }


    /**
     * Validate submitted inputs against configured service fields.
     */
    protected function validateInputsAgainstServiceFields(
        Collection $fields,
        array $inputs
    ): void {
        $errors = [];

        /*
        |--------------------------------------------------------------------------
        | Detect unknown fields
        |--------------------------------------------------------------------------
        |
        | A client must not submit arbitrary field IDs/codes.
        |
        */

        $allowedCodes = [];

        $allowedIds = [];

        foreach ($fields as $field) {

            if (
                isset($field->field_code)
                && $field->field_code !== null
            ) {
                $allowedCodes[] =
                    (string) $field->field_code;
            }

            $allowedIds[] =
                (string) $field->id;
        }


        foreach ($inputs as $key => $value) {

            $keyString = (string) $key;

            if (
                !in_array(
                    $keyString,
                    $allowedCodes,
                    true
                )
                &&
                !in_array(
                    $keyString,
                    $allowedIds,
                    true
                )
            ) {
                $errors[$keyString][] =
                    'This field is not configured for the selected revenue service.';
            }
        }


        /*
        |--------------------------------------------------------------------------
        | Validate configured fields
        |--------------------------------------------------------------------------
        */

        foreach ($fields as $field) {

            $fieldKey = $this->fieldInputKey($field);

            $exists = array_key_exists(
                $fieldKey,
                $inputs
            );

            /*
            |--------------------------------------------------------------------------
            | Required field
            |--------------------------------------------------------------------------
            */

            $required =
                $this->fieldIsRequired($field);

            if (
                $required
                && (!$exists || $this->isEmptyValue($inputs[$fieldKey] ?? null))
            ) {
                $errors[$fieldKey][] =
                    'This field is required.';

                continue;
            }


            /*
            |--------------------------------------------------------------------------
            | Optional empty field
            |--------------------------------------------------------------------------
            */

            if (
                !$exists
                || $this->isEmptyValue($inputs[$fieldKey] ?? null)
            ) {
                continue;
            }


            /*
            |--------------------------------------------------------------------------
            | Data type validation
            |--------------------------------------------------------------------------
            */

            $value = $inputs[$fieldKey];

            $dataType =
                strtoupper(
                    (string) (
                        $field->data_type
                        ?? ''
                    )
                );

            switch ($dataType) {

                case 'NUMBER':

                    if (
                        !is_int($value)
                        && !is_numeric($value)
                    ) {
                        $errors[$fieldKey][] =
                            'The value must be numeric.';
                    }

                    break;


                case 'DECIMAL':

                    if (
                        !is_numeric($value)
                    ) {
                        $errors[$fieldKey][] =
                            'The value must be a decimal number.';
                    }

                    break;


                case 'BOOLEAN':

                    if (
                        !is_bool($value)
                        && !in_array(
                            $value,
                            [0, 1, '0', '1', 'true', 'false'],
                            true
                        )
                    ) {
                        $errors[$fieldKey][] =
                            'The value must be boolean.';
                    }

                    break;


                case 'DATE':

                    if (
                        !$this->isValidDate($value)
                    ) {
                        $errors[$fieldKey][] =
                            'The value must be a valid date.';
                    }

                    break;


                case 'TEXT':
                default:

                    if (
                        is_array($value)
                        || is_object($value)
                    ) {
                        $errors[$fieldKey][] =
                            'The value must be text.';
                    }

                    break;
            }
        }


        /*
        |--------------------------------------------------------------------------
        | Throw validation errors
        |--------------------------------------------------------------------------
        */

        if ($errors !== []) {
            throw ValidationException::withMessages(
                $errors
            );
        }
    }


    /**
     * Normalize input values.
     */
    protected function normalizeInputs(
        array $inputs
    ): array {
        $normalized = [];

        foreach ($inputs as $key => $value) {

            /*
            |--------------------------------------------------------------------------
            | Normalize numeric strings
            |--------------------------------------------------------------------------
            */

            if (
                is_string($value)
                && is_numeric($value)
            ) {
                $normalized[$key] =
                    $this->normalizeNumericValue($value);

                continue;
            }


            /*
            |--------------------------------------------------------------------------
            | Normalize boolean strings
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
            | Keep other values unchanged
            |--------------------------------------------------------------------------
            */

            $normalized[$key] = $value;
        }

        return $normalized;
    }


    /**
     * Normalize numeric input without unnecessary floating point conversion.
     */
    protected function normalizeNumericValue(
        string $value
    ): int|float|string {
        if (str_contains($value, '.')) {
            return (float) $value;
        }

        return (int) $value;
    }


    /**
     * Execute authoritative calculation.
     */
    protected function calculate(
        array $request
    ): array {
        try {

            $result = $this->decisionProvider->calculate(
                $request
            );

            if (
                !is_array($result)
            ) {
                throw new \RuntimeException(
                    'The Decision Provider returned an invalid response.'
                );
            }

            return $result;

        } catch (Throwable $e) {

            Log::error(
                'Direct collection calculation failed.',
                [
                    'service_id' =>
                        $request['service_id'] ?? null,

                    'citizen_id' =>
                        $request['citizen_id'] ?? null,

                    'error' =>
                        $e->getMessage(),
                ]
            );

            throw ValidationException::withMessages([
                'service_id' =>
                    'The revenue service amount could not be calculated. Please try again.',
            ]);
        }
    }


    /**
     * Validate Decision Provider result.
     */
    protected function validateCalculationResult(
        array $decision
    ): void {
        if (
            !array_key_exists(
                'amount',
                $decision
            )
        ) {
            throw ValidationException::withMessages([
                'service_id' =>
                    'The calculation provider did not return an amount.',
            ]);
        }


        if (
            !is_numeric(
                $decision['amount']
            )
        ) {
            throw ValidationException::withMessages([
                'service_id' =>
                    'The calculation provider returned an invalid amount.',
            ]);
        }


        $amount = (float) $decision['amount'];

        if ($amount < 0) {
            throw ValidationException::withMessages([
                'service_id' =>
                    'The calculated amount cannot be negative.',
            ]);
        }


        if (
            $amount > 999999999999.9999
        ) {
            throw ValidationException::withMessages([
                'service_id' =>
                    'The calculated amount exceeds the allowed limit.',
            ]);
        }


        /*
        |--------------------------------------------------------------------------
        | Currency validation
        |--------------------------------------------------------------------------
        */

        if (
            isset($decision['currency'])
            && (
                !is_string($decision['currency'])
                || strlen($decision['currency']) !== 3
            )
        ) {
            throw ValidationException::withMessages([
                'service_id' =>
                    'The calculation provider returned an invalid currency.',
            ]);
        }
    }


    /**
     * Build immutable input snapshot.
     */
    protected function buildInputSnapshot(
        Collection $fields,
        array $inputs
    ): array {
        $snapshot = [];

        foreach ($fields as $field) {

            $key = $this->fieldInputKey($field);

            if (
                !array_key_exists(
                    $key,
                    $inputs
                )
            ) {
                continue;
            }

            $snapshot[$key] = [
                'field_id' => (string) $field->id,

                'field_code' =>
                    $field->field_code
                    ?? null,

                'field_label' =>
                    $field->field_label
                    ?? $field->label
                    ?? null,

                'data_type' =>
                    $field->data_type
                    ?? null,

                'input_type' =>
                    $field->input_type
                    ?? null,

                'value' =>
                    $inputs[$key],
            ];
        }

        return $snapshot;
    }


    /**
     * Build immutable calculation snapshot.
     */
    protected function buildCalculationSnapshot(
        array $decision,
        array $inputs
    ): array {
        return [
            'calculation_type' =>
                $decision['calculation_type']
                ?? null,

            'amount' =>
                $this->decimal(
                    $decision['amount']
                ),

            'currency' =>
                strtoupper(
                    $decision['currency']
                    ?? 'ETB'
                ),

            'tariff_version_id' =>
                $decision['tariff_version_id']
                ?? null,

            'tariff_rule_id' =>
                $decision['tariff_rule_id']
                ?? null,

            'quantity' =>
                $decision['quantity']
                ?? null,

            'unit' =>
                $decision['unit']
                ?? null,

            'unit_price' =>
                isset($decision['unit_price'])
                    ? $this->decimal(
                        $decision['unit_price']
                    )
                    : null,

            'minimum_amount' =>
                isset($decision['minimum_amount'])
                    ? $this->decimal(
                        $decision['minimum_amount']
                    )
                    : null,

            'maximum_amount' =>
                isset($decision['maximum_amount'])
                    ? $this->decimal(
                        $decision['maximum_amount']
                    )
                    : null,

            'rounding_rule' =>
                $decision['rounding_rule']
                ?? null,

            'formula' =>
                $decision['formula']
                ?? null,

            'inputs' => $inputs,

            'provider_metadata' =>
                $decision['metadata']
                ?? null,

            'calculated_at' =>
                $decision['calculated_at']
                ?? now()->toISOString(),
        ];
    }


    /**
     * Generate a unique human-readable invoice number.
     */
    protected function generateInvoiceNumber(): string
    {
        /*
        |--------------------------------------------------------------------------
        | IMPORTANT
        |--------------------------------------------------------------------------
        |
        | The database UNIQUE constraint remains the final protection
        | against duplicates.
        |
        | For a distributed production environment, replace this with
        | your centralized invoice-number generator if you already have
        | one.
        |
        */

        $year = now()->format('Y');

        $lastNumber = Invoice::query()
            ->whereYear(
                'created_at',
                (int) $year
            )
            ->lockForUpdate()
            ->max('id');

        /*
        |--------------------------------------------------------------------------
        | Use an application-generated UUID-safe sequence fallback.
        |--------------------------------------------------------------------------
        |
        | If you already have an invoice number sequence table/service,
        | use that instead.
        |
        */

        $sequence = Invoice::query()
            ->where(
                'invoice_number',
                'like',
                "INV-{$year}-%"
            )
            ->count() + 1;

        return sprintf(
            'INV-%s-%06d',
            $year,
            $sequence
        );
    }


    /**
     * Build invoice item description.
     */
    protected function buildDescription(
        RevenueService $service
    ): string {
        return (string) (
            $service->name
            ?? $service->service_name
            ?? $service->code
            ?? 'Revenue Service'
        );
    }


    /**
     * Extract quantity from calculation result.
     */
    protected function extractQuantity(
        array $decision
    ): ?string {
        if (
            !array_key_exists(
                'quantity',
                $decision
            )
        ) {
            return null;
        }

        if (
            $decision['quantity'] === null
        ) {
            return null;
        }

        return $this->decimal(
            $decision['quantity']
        );
    }


    /**
     * Extract unit.
     */
    protected function extractUnit(
        array $decision,
        RevenueService $service
    ): ?string {
        return $decision['unit']
            ?? $service->unit
            ?? null;
    }


    /**
     * Extract unit price.
     */
    protected function extractUnitPrice(
        array $decision
    ): ?string {
        if (
            !array_key_exists(
                'unit_price',
                $decision
            )
        ) {
            return null;
        }

        if (
            $decision['unit_price'] === null
        ) {
            return null;
        }

        return $this->decimal(
            $decision['unit_price']
        );
    }


    /**
     * Resolve field input key.
     *
     * The preferred key is field_code.
     * Field ID is used as a fallback.
     */
    protected function fieldInputKey(
        RevenueServiceField $field
    ): string {
        return (string) (
            $field->field_code
            ?? $field->id
        );
    }


    /**
     * Determine whether a field is required.
     */
    protected function fieldIsRequired(
        RevenueServiceField $field
    ): bool {
        if (
            isset($field->is_required)
        ) {
            return (bool) $field->is_required;
        }

        if (
            isset($field->required)
        ) {
            return (bool) $field->required;
        }

        return false;
    }


    /**
     * Determine whether a value is empty.
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
     * Validate date value.
     */
    protected function isValidDate(
        mixed $value
    ): bool {
        if (!is_string($value)) {
            return false;
        }

        $date = \DateTime::createFromFormat(
            'Y-m-d',
            $value
        );

        return $date !== false
            && $date->format('Y-m-d') === $value;
    }


    /**
     * Normalize a decimal value.
     */
    protected function decimal(
        mixed $value
    ): string {
        if (!is_numeric($value)) {
            throw new \InvalidArgumentException(
                'Expected a numeric decimal value.'
            );
        }

        return number_format(
            (float) $value,
            4,
            '.',
            ''
        );
    }
}