<?php

declare(strict_types=1);

namespace App\Modules\DirectCollection\Services;

use App\Models\Citizen;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\RevenueService;
use App\Modules\Invoice\Services\InvoiceIssuanceService;
use App\Services\DocumentSequenceService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class DirectCollectionInvoiceService
{
    private const SOURCE_TYPE = 'DIRECT_COLLECTION';

    private const MAX_AMOUNT = 999999999999.9999;

    public function __construct(
        protected InvoiceIssuanceService $invoiceIssuanceService,
        protected DocumentSequenceService $documentSequenceService,
    ) {
    }

    /**
     * Create and issue one invoice containing multiple collection items.
     *
     * @param array<string,mixed> $data
     * @param array<string,mixed> $context
     * @param array<string,mixed> $calculation
     */
    public function create(
        array $data,
        array $context,
        array $calculation,
    ): Invoice {
        $user = Auth::user();

        if (! $user) {
            throw ValidationException::withMessages([
                'user' => [
                    'An authenticated user is required.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Resolve Taxpayer and Revenue Service
        |--------------------------------------------------------------------------
        */

        /** @var Citizen|null $taxpayer */
        $taxpayer = $context['taxpayer'] ?? null;

        /** @var RevenueService|null $service */
        $service = $context['service'] ?? null;

        if (! $taxpayer instanceof Citizen) {
            throw ValidationException::withMessages([
                'taxpayer_id' => [
                    'A valid taxpayer is required.',
                ],
            ]);
        }

        if (! $service instanceof RevenueService) {
            throw ValidationException::withMessages([
                'revenue_service_id' => [
                    'A valid revenue service is required.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Validate Individual Entry Calculations
        |--------------------------------------------------------------------------
        */

        $entries = $this->validateEntries($calculation);

        /*
        |--------------------------------------------------------------------------
        | Validate Aggregate Financial Values
        |--------------------------------------------------------------------------
        */

        $financials = $this->resolveFinancialValues(
            $entries,
            $calculation,
        );

        /*
        |--------------------------------------------------------------------------
        | Create Invoice Transaction
        |--------------------------------------------------------------------------
        */

        return DB::transaction(
            function () use (
                $data,
                $taxpayer,
                $service,
                $entries,
                $financials,
                $user,
                $calculation,
            ): Invoice {
                /*
                |--------------------------------------------------------------------------
                | Lock Taxpayer
                |--------------------------------------------------------------------------
                */

                $lockedTaxpayer = Citizen::query()
                    ->lockForUpdate()
                    ->find($taxpayer->id);

                if (! $lockedTaxpayer) {
                    throw ValidationException::withMessages([
                        'taxpayer_id' => [
                            'The selected taxpayer could not be found.',
                        ],
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Verify Revenue Service Availability
                |--------------------------------------------------------------------------
                */

                $this->ensureServiceIsAvailable($service);

                /*
                |--------------------------------------------------------------------------
                | Generate Invoice Number
                |--------------------------------------------------------------------------
                */

                $invoiceNumber = $this->documentSequenceService->generate(
                    sequenceType: 'invoice',
                );

                /*
                |--------------------------------------------------------------------------
                | Build Entry Snapshots
                |--------------------------------------------------------------------------
                */

                $entrySnapshots = [];

                foreach ($entries as $entry) {
                    $entryCalculation = $entry['calculation'];

                    $entrySnapshots[] = [
                        'entry_index' => $entry['index'],
                        'amount' => $entryCalculation['amount'],
                        'currency' => $entryCalculation['currency'],
                        'input_snapshot' => $entryCalculation['input_snapshot'],
                        'calculation_snapshot' => $entryCalculation['calculation_snapshot'],
                    ];
                }

                /*
                |--------------------------------------------------------------------------
                | Create Direct Collection Invoice
                |--------------------------------------------------------------------------
                |
                | One taxpayer + one revenue service + multiple entries
                | produce one invoice with multiple invoice items.
                |
                | Direct collection invoices do not have due dates.
                |
                */

                $invoice = Invoice::query()->create([
                    'invoice_number' => $invoiceNumber,

                    'source_type' => self::SOURCE_TYPE,

                    'assessment_id' => null,

                    'citizen_id' => $lockedTaxpayer->id,

                    'administrative_unit_id' =>
                        $data['administrative_unit_id'] ?? null,

                    'status' => 'DRAFT',

                    'currency' => $financials['currency'],

                    /*
                    |--------------------------------------------------------------------------
                    | Financial Values
                    |--------------------------------------------------------------------------
                    */

                    'subtotal' => $financials['amount'],

                    'discount_amount' => 0,

                    'penalty_amount' => 0,

                    'interest_amount' => 0,

                    'total_amount' => $financials['amount'],

                    /*
                    |--------------------------------------------------------------------------
                    | Payment Values
                    |--------------------------------------------------------------------------
                    */

                    'paid_amount' => 0,

                    'balance_due' => $financials['amount'],

                    /*
                    |--------------------------------------------------------------------------
                    | Invoice Dates
                    |--------------------------------------------------------------------------
                    */

                    'issued_at' => null,

                    'due_date' => null,

                    'paid_at' => null,

                    /*
                    |--------------------------------------------------------------------------
                    | Cancellation
                    |--------------------------------------------------------------------------
                    */

                    'cancelled_at' => null,

                    'cancelled_by' => null,

                    'cancellation_reason' => null,

                    /*
                    |--------------------------------------------------------------------------
                    | Void
                    |--------------------------------------------------------------------------
                    */

                    'voided_at' => null,

                    'voided_by' => null,

                    'void_reason' => null,

                    /*
                    |--------------------------------------------------------------------------
                    | Additional Information
                    |--------------------------------------------------------------------------
                    */

                    'notes' => $data['notes'] ?? null,

                    'created_by' => $user->id,

                    'issued_by' => null,

                    /*
                    |--------------------------------------------------------------------------
                    | Source Metadata
                    |--------------------------------------------------------------------------
                    */

                    'source_metadata' => [
                        'source' => self::SOURCE_TYPE,

                        'service_id' => (string) $service->id,

                        'service_code' => $service->code ?? null,

                        'taxpayer_id' => (string) $lockedTaxpayer->id,

                        'created_by' => (string) $user->id,

                        'created_at' => now()->toISOString(),

                        'metadata' => $data['metadata'] ?? null,

                        'entry_count' => count($entries),

                        'entries' => $entrySnapshots,

                        'calculation' =>
                            $calculation['calculation_snapshot'] ?? null,
                    ],
                ]);

                /*
                |--------------------------------------------------------------------------
                | Create One Invoice Item Per Entry
                |--------------------------------------------------------------------------
                */

                foreach ($entries as $position => $entry) {
                    $entryCalculation = $entry['calculation'];

                    InvoiceItem::query()->create([
                        'invoice_id' => $invoice->id,

                        'assessment_service_id' => null,

                        'service_id' => $service->id,

                        'line_number' => $position + 1,

                        'description' => $this->buildDescription(
                            $service,
                            $position + 1,
                        ),

                        /*
                        |--------------------------------------------------------------------------
                        | Quantity and Unit
                        |--------------------------------------------------------------------------
                        |
                        | These values are available for PER_UNIT calculations.
                        | Other calculation types may legitimately leave them null.
                        |
                        */

                        'quantity' => $entryCalculation['quantity'] ?? null,

                        'unit' => $entryCalculation['unit'] ?? null,

                        'unit_price' => $entryCalculation['unit_price'] ?? null,

                        /*
                        |--------------------------------------------------------------------------
                        | Financial Values
                        |--------------------------------------------------------------------------
                        */

                        'amount' => $entryCalculation['amount'],

                        'discount_amount' => 0,

                        'penalty_amount' => 0,

                        'interest_amount' => 0,

                        'total_amount' => $entryCalculation['amount'],

                        'currency' => $entryCalculation['currency'],

                        /*
                        |--------------------------------------------------------------------------
                        | Tariff References
                        |--------------------------------------------------------------------------
                        */

                        'tariff_version_id' =>
                            $entryCalculation['tariff_version_id'],

                        'tariff_rule_id' =>
                            $entryCalculation['tariff_rule_id'],

                        /*
                        |--------------------------------------------------------------------------
                        | Audit Snapshots
                        |--------------------------------------------------------------------------
                        */

                        'input_snapshot' =>
                            $entryCalculation['input_snapshot'],

                        'calculation_snapshot' =>
                            $entryCalculation['calculation_snapshot'],
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Issue Invoice
                |--------------------------------------------------------------------------
                */

                $issuedInvoice = $this->invoiceIssuanceService->issue(
                    $invoice
                );

                /*
                |--------------------------------------------------------------------------
                | Log Invoice Creation
                |--------------------------------------------------------------------------
                */

                Log::info(
                    'Direct collection invoice created and issued.',
                    [
                        'invoice_id' => $issuedInvoice->id,

                        'invoice_number' => $issuedInvoice->invoice_number,

                        'taxpayer_id' => $lockedTaxpayer->id,

                        'revenue_service_id' => $service->id,

                        'entry_count' => count($entries),

                        'amount' => $financials['amount'],

                        'currency' => $financials['currency'],

                        'created_by' => $user->id,

                        'issued_by' => $issuedInvoice->issued_by,
                    ]
                );

                /*
                |--------------------------------------------------------------------------
                | Return Issued Invoice
                |--------------------------------------------------------------------------
                */

                return $issuedInvoice->fresh([
                    'citizen',
                    'items',
                    'items.service',
                ]);
            },
            attempts: 3,
        );
    }

    /**
     * Validate individual entry calculations.
     *
     * Quantity, unit and unit_price are optional because not every
     * tariff calculation type uses them.
     *
     * @param array<string,mixed> $calculation
     * @return array<int,array{
     *     index:int,
     *     fields:array<string,mixed>,
     *     calculation:array<string,mixed>
     * }>
     */
    protected function validateEntries(
        array $calculation,
    ): array {
        $entries = $calculation['entries'] ?? null;

        if (! is_array($entries) || $entries === []) {
            throw ValidationException::withMessages([
                'entries' => [
                    'At least one calculated collection entry is required.',
                ],
            ]);
        }

        $validated = [];

        foreach (array_values($entries) as $position => $entry) {
            if (
                ! is_array($entry)
                || ! isset($entry['calculation'])
                || ! is_array($entry['calculation'])
            ) {
                throw ValidationException::withMessages([
                    "entries.{$position}" => [
                        'The collection entry calculation is invalid.',
                    ],
                ]);
            }

            $entryCalculation = $entry['calculation'];

            /*
            |--------------------------------------------------------------------------
            | Required Calculation Properties
            |--------------------------------------------------------------------------
            */

            foreach (
                [
                    'amount',
                    'currency',
                    'tariff_version_id',
                    'tariff_rule_id',
                    'input_snapshot',
                    'calculation_snapshot',
                ] as $requiredKey
            ) {
                if (! array_key_exists($requiredKey, $entryCalculation)) {
                    throw ValidationException::withMessages([
                        "entries.{$position}.{$requiredKey}" => [
                            'The entry calculation is missing required information.',
                        ],
                    ]);
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Validate Amount
            |--------------------------------------------------------------------------
            */

            $this->validateNonNegativeNumeric(
                $entryCalculation['amount'],
                "entries.{$position}.amount",
                self::MAX_AMOUNT,
            );

            /*
            |--------------------------------------------------------------------------
            | Validate Optional Quantity
            |--------------------------------------------------------------------------
            */

            $quantity = $entryCalculation['quantity'] ?? null;

            if ($quantity !== null) {
                $this->validateNonNegativeNumeric(
                    $quantity,
                    "entries.{$position}.quantity",
                    self::MAX_AMOUNT,
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Validate Optional Unit Price
            |--------------------------------------------------------------------------
            */

            $unitPrice = $entryCalculation['unit_price'] ?? null;

            if ($unitPrice !== null) {
                $this->validateNonNegativeNumeric(
                    $unitPrice,
                    "entries.{$position}.unit_price",
                    self::MAX_AMOUNT,
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Validate Optional Unit
            |--------------------------------------------------------------------------
            */

            $unit = $entryCalculation['unit'] ?? null;

            if (
                $unit !== null
                && ! is_string($unit)
            ) {
                throw ValidationException::withMessages([
                    "entries.{$position}.unit" => [
                        'The entry unit must be a string or null.',
                    ],
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Validate Currency
            |--------------------------------------------------------------------------
            */

            if (
                ! is_string($entryCalculation['currency'])
                || trim($entryCalculation['currency']) === ''
            ) {
                throw ValidationException::withMessages([
                    "entries.{$position}.currency" => [
                        'The entry currency is invalid.',
                    ],
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Validate Snapshots
            |--------------------------------------------------------------------------
            */

            if (
                ! is_array($entryCalculation['input_snapshot'])
                || ! is_array($entryCalculation['calculation_snapshot'])
            ) {
                throw ValidationException::withMessages([
                    "entries.{$position}" => [
                        'The entry audit snapshots are invalid.',
                    ],
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Normalize Optional Values
            |--------------------------------------------------------------------------
            */

            $entryCalculation['quantity'] = $quantity;

            $entryCalculation['unit'] = $unit !== null
                ? trim($unit)
                : null;

            $entryCalculation['unit_price'] = $unitPrice;

            $validated[] = [
                'index' => (int) ($entry['index'] ?? $position),

                'fields' => is_array($entry['fields'] ?? null)
                    ? $entry['fields']
                    : [],

                'calculation' => $entryCalculation,
            ];
        }

        return $validated;
    }

    /**
     * Validate a nonnegative numeric value.
     */
    protected function validateNonNegativeNumeric(
        mixed $value,
        string $field,
        float $maximum,
    ): void {
        if (
            ! is_numeric($value)
            || ! is_finite((float) $value)
            || (float) $value < 0
            || (float) $value > $maximum
        ) {
            throw ValidationException::withMessages([
                $field => [
                    'The value must be a valid nonnegative number within the allowed limit.',
                ],
            ]);
        }
    }

    /**
     * Resolve and verify invoice-level financial values.
     *
     * All entry calculations must use the same currency.
     *
     * @param array<int,array<string,mixed>> $entries
     * @param array<string,mixed> $calculation
     * @return array{amount:string,currency:string}
     */
    protected function resolveFinancialValues(
        array $entries,
        array $calculation,
    ): array {
        $total = 0.0;

        $currency = null;

        foreach ($entries as $position => $entry) {
            $entryCalculation = $entry['calculation'];

            $entryCurrency = strtoupper(
                trim((string) $entryCalculation['currency'])
            );

            if ($currency === null) {
                $currency = $entryCurrency;
            } elseif ($currency !== $entryCurrency) {
                throw ValidationException::withMessages([
                    "entries.{$position}.currency" => [
                        'All collection entries must use the same currency.',
                    ],
                ]);
            }

            $total += (float) $entryCalculation['amount'];

            if (! is_finite($total) || $total > self::MAX_AMOUNT) {
                throw ValidationException::withMessages([
                    'entries' => [
                        'The total collection amount exceeds the allowed limit.',
                    ],
                ]);
            }
        }

        $totalAmount = $this->decimal($total);

        /*
        |--------------------------------------------------------------------------
        | Verify Supplied Aggregate Amount
        |--------------------------------------------------------------------------
        */

        if (
            isset($calculation['amount'])
            && (
                ! is_numeric($calculation['amount'])
                || ! is_finite((float) $calculation['amount'])
                || abs(
                    (float) $calculation['amount'] - $total
                ) > 0.0001
            )
        ) {
            throw ValidationException::withMessages([
                'amount' => [
                    'The invoice total does not match the sum of its entries.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Verify Supplied Aggregate Currency
        |--------------------------------------------------------------------------
        */

        if (
            isset($calculation['currency'])
            && strtoupper(trim((string) $calculation['currency'])) !== $currency
        ) {
            throw ValidationException::withMessages([
                'currency' => [
                    'The invoice currency does not match its entry calculations.',
                ],
            ]);
        }

        return [
            'amount' => $totalAmount,

            'currency' => $currency ?? 'ETB',
        ];
    }

    /**
     * Ensure the selected revenue service is available.
     */
    protected function ensureServiceIsAvailable(
        RevenueService $service,
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
     * Build invoice item description.
     */
    protected function buildDescription(
        RevenueService $service,
        int $entryNumber,
    ): string {
        $serviceName = (string) (
            $service->name
            ?? $service->service_name
            ?? $service->code
            ?? 'Revenue Service'
        );

        return sprintf(
            '%s - Entry %d',
            $serviceName,
            $entryNumber,
        );
    }

    /**
     * Format numeric values as fixed-point decimals.
     */
    protected function decimal(mixed $value): string
    {
        return number_format(
            (float) $value,
            4,
            '.',
            '',
        );
    }
}
