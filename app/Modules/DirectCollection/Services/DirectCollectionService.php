<?php

declare(strict_types=1);

namespace App\Modules\DirectCollection\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class DirectCollectionService
{
    public function __construct(
        protected DirectCollectionInputService $inputService,
        protected DirectCollectionCalculator $calculator,
        protected DirectCollectionInvoiceService $invoiceService,
    ) {
    }

    /**
     * Calculate direct collection amount.
     *
     * This does NOT create an invoice.
     *
     * Returns only the collection details required by the frontend review.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function calculateAmount(array $data): array
    {
        $this->ensureAuthenticated();

        $context = $this->inputService->resolve($data);

        $calculation = $this->calculateEntries($context);

        return $this->buildReviewPayload(
            context: $context,
            calculation: $calculation,
        );
    }


    /**
     * Build a simple frontend review payload.
     *
     * Internal calculation snapshots are intentionally excluded.
     *
     * @param array<string,mixed> $context
     * @param array<string,mixed> $calculation
     * @return array<string,mixed>
     */
    protected function buildReviewPayload(
        array $context,
        array $calculation,
    ): array {
        $service = $context['service'];

        $serviceName =
            $service->name
            ?? $service->service_name
            ?? 'Collection';

        $reviewEntries = [];

        foreach ($calculation['entries'] as $position => $entry) {
            $inputs = $entry['fields'] ?? [];

            $reviewFields = [];

            foreach ($context['fields'] as $field) {
                $fieldId = (string) $field->id;

                if (! array_key_exists($fieldId, $inputs)) {
                    continue;
                }

                $reviewFields[] = [
                    'label' =>
                        $field->field_label
                        ?? $field->label
                        ?? $field->field_code
                        ?? 'Field',

                    'value' => $inputs[$fieldId],
                ];
            }

            $entryCalculation = $entry['calculation'];

            $reviewEntries[] = [
                'line_number' => $position + 1,

                'fields' => $reviewFields,

                'quantity' =>
                    $entryCalculation['quantity'] ?? null,

                'unit' =>
                    $entryCalculation['unit'] ?? null,

                'unit_price' =>
                    $entryCalculation['unit_price'] ?? null,

                'amount' =>
                    $entryCalculation['amount'],
            ];
        }

        return [
            'service' => [
                'name' => $serviceName,
            ],

            'entry_count' => count($reviewEntries),

            'currency' => $calculation['currency'],

            'total_amount' => $calculation['amount'],

            'entries' => $reviewEntries,
        ];
    }

    /**
     * Create and issue a direct collection invoice.
     *
     * One taxpayer + one revenue service + multiple entries
     * produce one invoice containing multiple invoice items.
     *
     * @param array<string,mixed> $data
     */
    public function create(array $data): Invoice
    {
        $this->ensureAuthenticated();

        /*
        |--------------------------------------------------------------------------
        | 1. Resolve + Validate Inputs
        |--------------------------------------------------------------------------
        */

        $context = $this->inputService->resolve($data);

        /*
        |--------------------------------------------------------------------------
        | 2. Calculate Every Entry
        |--------------------------------------------------------------------------
        |
        | Always calculate on the server.
        | Never trust frontend amounts or tariff information.
        |
        */

        $calculation = $this->calculateEntries($context);

        /*
        |--------------------------------------------------------------------------
        | 3. Create + Issue One Invoice
        |--------------------------------------------------------------------------
        */

        return $this->invoiceService->create(
            data: $data,
            context: $context,
            calculation: $calculation,
        );
    }

    /**
     * Update an existing direct collection.
     *
     * Only ISSUED invoices with no recorded payment can be edited.
     *
     * The invoice number remains unchanged.
     *
     * Existing invoice items are synchronized by line number:
     * - Existing lines are updated.
     * - New lines are created.
     * - Excess lines are deleted.
     *
     * @param string $invoiceId
     * @param array<string,mixed> $data
     */
    public function update(
        string $invoiceId,
        array $data
    ): Invoice {
        $this->ensureAuthenticated();

        return DB::transaction(
            function () use ($invoiceId, $data): Invoice {

                /*
                |--------------------------------------------------------------------------
                | 1. Find + Lock Invoice
                |--------------------------------------------------------------------------
                */

                $invoice = Invoice::query()
                    ->where('source_type', 'DIRECT_COLLECTION')
                    ->where('id', $invoiceId)
                    ->lockForUpdate()
                    ->firstOrFail();

                /*
                |--------------------------------------------------------------------------
                | 2. Verify Invoice Status
                |--------------------------------------------------------------------------
                */

                $status = strtoupper(
                    (string) $invoice->status
                );

                if ($status !== 'ISSUED') {
                    throw ValidationException::withMessages([
                        'invoice' => [
                            'Only pending-payment direct collections can be edited.',
                        ],
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | 3. Verify Payment State
                |--------------------------------------------------------------------------
                */

                $paidAmount = (float) (
                    $invoice->paid_amount ?? 0
                );

                if ($paidAmount > 0) {
                    throw ValidationException::withMessages([
                        'invoice' => [
                            'This direct collection cannot be edited because payment has already been recorded.',
                        ],
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | 4. Resolve New Input
                |--------------------------------------------------------------------------
                */

                $context = $this->inputService->resolve($data);

                /*
                |--------------------------------------------------------------------------
                | 5. Recalculate Every Entry
                |--------------------------------------------------------------------------
                */

                $calculation = $this->calculateEntries($context);

                /*
                |--------------------------------------------------------------------------
                | 6. Update Invoice
                |--------------------------------------------------------------------------
                */

                $this->updateInvoice(
                    invoice: $invoice,
                    context: $context,
                    calculation: $calculation,
                    data: $data,
                );

                /*
                |--------------------------------------------------------------------------
                | 7. Synchronize Invoice Items
                |--------------------------------------------------------------------------
                */

                $this->updateInvoiceItems(
                    invoice: $invoice,
                    context: $context,
                    calculation: $calculation,
                );

                /*
                |--------------------------------------------------------------------------
                | 8. Return Updated Invoice
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
     * Calculate every collection entry independently and aggregate
     * the results into one invoice-level calculation.
     *
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    protected function calculateEntries(array $context): array
    {
        $entries = $context['entries'] ?? [];

        if (! is_array($entries) || $entries === []) {
            throw ValidationException::withMessages([
                'entries' => [
                    'At least one collection entry is required.',
                ],
            ]);
        }

        $calculatedEntries = [];

        $totalAmount = 0.0;

        $currency = null;

        /*
        |--------------------------------------------------------------------------
        | Calculate Each Entry
        |--------------------------------------------------------------------------
        */

        foreach ($entries as $position => $entry) {
            $inputs = $entry['fields'] ?? [];

            if (! is_array($inputs)) {
                throw ValidationException::withMessages([
                    "entries.{$position}.fields" => [
                        'Invalid collection entry fields.',
                    ],
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Individual Tariff Calculation
            |--------------------------------------------------------------------------
            */

            $entryCalculation = $this->calculator->calculate(
                taxpayer: $context['taxpayer'],
                service: $context['service'],
                fields: $context['fields'],
                inputs: $inputs,
            );

            /*
            |--------------------------------------------------------------------------
            | Validate Entry Amount
            |--------------------------------------------------------------------------
            */

            $amount = $entryCalculation['amount'] ?? null;

            if (
                ! is_numeric($amount)
                || ! is_finite((float) $amount)
                || (float) $amount < 0
            ) {
                throw ValidationException::withMessages([
                    "entries.{$position}.amount" => [
                        'The calculated entry amount is invalid.',
                    ],
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Resolve Currency
            |--------------------------------------------------------------------------
            */

            $entryCurrency = strtoupper(
                trim((string) ($entryCalculation['currency'] ?? ''))
            );

            if ($entryCurrency === '') {
                throw ValidationException::withMessages([
                    "entries.{$position}.currency" => [
                        'The calculated entry currency is missing.',
                    ],
                ]);
            }

            if ($currency === null) {
                $currency = $entryCurrency;
            } elseif ($currency !== $entryCurrency) {
                throw ValidationException::withMessages([
                    "entries.{$position}.currency" => [
                        'All collection entries must use the same currency.',
                    ],
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Aggregate Amount
            |--------------------------------------------------------------------------
            */

            $totalAmount += (float) $amount;

            if (! is_finite($totalAmount)) {
                throw ValidationException::withMessages([
                    'entries' => [
                        'The total collection amount is invalid.',
                    ],
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Preserve Entry Calculation
            |--------------------------------------------------------------------------
            */

            $calculatedEntries[] = [
                'index' => (int) ($entry['index'] ?? $position),

                'fields' => $inputs,

                'calculation' => $entryCalculation,
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Validate Total Amount
        |--------------------------------------------------------------------------
        */

        if ($totalAmount > 999999999999.9999) {
            throw ValidationException::withMessages([
                'entries' => [
                    'The total collection amount exceeds the allowed limit.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Build Aggregate Input Snapshot
        |--------------------------------------------------------------------------
        */

        $inputSnapshot = [
            'entry_count' => count($calculatedEntries),

            'entries' => array_map(
                fn (array $entry): array => [
                    'entry_index' => $entry['index'],
                    'fields' => $entry['calculation']['input_snapshot'],
                ],
                $calculatedEntries,
            ),
        ];

        /*
        |--------------------------------------------------------------------------
        | Build Aggregate Calculation Snapshot
        |--------------------------------------------------------------------------
        |
        | Direct collection does not use due dates.
        | Each entry retains its own tariff and calculation snapshot.
        |
        */

        $calculationSnapshot = [
            'calculation_type' => 'MULTI_ENTRY',

            'entry_count' => count($calculatedEntries),

            'total_amount' => $this->decimal($totalAmount),

            'currency' => $currency,

            'entries' => array_map(
                fn (array $entry): array => [
                    'entry_index' => $entry['index'],

                    'amount' => $entry['calculation']['amount'],

                    'currency' => $entry['calculation']['currency'],

                    'tariff_version_id' =>
                        $entry['calculation']['tariff_version_id'],

                    'tariff_rule_id' =>
                        $entry['calculation']['tariff_rule_id'],

                    'calculation_snapshot' =>
                        $entry['calculation']['calculation_snapshot'],
                ],
                $calculatedEntries,
            ),

            'calculated_at' => now()->toISOString(),
        ];

        /*
        |--------------------------------------------------------------------------
        | Return Aggregate Calculation
        |--------------------------------------------------------------------------
        */

        return [
            'taxpayer_id' => (string) $context['taxpayer']->id,

            'revenue_service_id' => (string) $context['service']->id,

            'service' => [
                'id' => (string) $context['service']->id,
                'code' => $context['service']->code ?? null,
                'name' =>
                    $context['service']->name
                    ?? $context['service']->service_name
                    ?? null,
            ],

            'amount' => $this->decimal($totalAmount),

            'currency' => $currency,

            'entry_count' => count($calculatedEntries),

            'entries' => $calculatedEntries,

            'input_snapshot' => $inputSnapshot,

            'calculation_snapshot' => $calculationSnapshot,
        ];
    }

    /**
     * Update invoice financial information and metadata.
     *
     * @param array<string,mixed> $context
     * @param array<string,mixed> $calculation
     * @param array<string,mixed> $data
     */
    protected function updateInvoice(
        Invoice $invoice,
        array $context,
        array $calculation,
        array $data
    ): void {
        $amount = $calculation['amount'];

        $currency = $calculation['currency']
            ?? $invoice->currency
            ?? 'ETB';

        /*
        |--------------------------------------------------------------------------
        | Financial Values
        |--------------------------------------------------------------------------
        */

        $invoice->subtotal = $amount;

        $invoice->discount_amount = 0;

        $invoice->penalty_amount = 0;

        $invoice->interest_amount = 0;

        $invoice->total_amount = $amount;

        /*
        |--------------------------------------------------------------------------
        | Payment State
        |--------------------------------------------------------------------------
        */

        $invoice->paid_amount = 0;

        $invoice->balance_due = $amount;

        $invoice->paid_at = null;

        /*
        |--------------------------------------------------------------------------
        | Currency
        |--------------------------------------------------------------------------
        */

        $invoice->currency = strtoupper(
            (string) $currency
        );

        /*
        |--------------------------------------------------------------------------
        | Due Date
        |--------------------------------------------------------------------------
        |
        | Direct collection is paid immediately through the cash
        | collection workflow. It does not have a due date.
        |
        */

        $invoice->due_date = null;

        /*
        |--------------------------------------------------------------------------
        | Taxpayer
        |--------------------------------------------------------------------------
        */

        $invoice->citizen_id = $context['taxpayer']->id;

        /*
        |--------------------------------------------------------------------------
        | Administrative Unit
        |--------------------------------------------------------------------------
        */

        if (array_key_exists('administrative_unit_id', $data)) {
            $invoice->administrative_unit_id =
                $data['administrative_unit_id'];
        }

        /*
        |--------------------------------------------------------------------------
        | Notes
        |--------------------------------------------------------------------------
        */

        if (array_key_exists('notes', $data)) {
            $invoice->notes = $data['notes'];
        }

        /*
        |--------------------------------------------------------------------------
        | Preserve ISSUED Status
        |--------------------------------------------------------------------------
        */

        $invoice->status = 'ISSUED';

        /*
        |--------------------------------------------------------------------------
        | Updated By
        |--------------------------------------------------------------------------
        */

        if ($invoice->isFillable('updated_by')) {
            $invoice->updated_by = Auth::id();
        }

        /*
        |--------------------------------------------------------------------------
        | Source Metadata
        |--------------------------------------------------------------------------
        */

        $metadata = is_array($invoice->source_metadata)
            ? $invoice->source_metadata
            : [];

        $metadata['last_edited_at'] = now()->toISOString();

        $metadata['last_edited_by'] = Auth::id();

        $metadata['calculation'] =
            $calculation['calculation_snapshot'];

        $metadata['input_snapshot'] =
            $calculation['input_snapshot'];

        $metadata['entries'] = array_map(
            fn (array $entry): array => [
                'entry_index' => $entry['index'],
                'fields' => $entry['fields'],
                'calculation' => $entry['calculation']['calculation_snapshot'],
                'input_snapshot' => $entry['calculation']['input_snapshot'],
            ],
            $calculation['entries'],
        );

        $metadata['entry_count'] = $calculation['entry_count'];

        $metadata['service_id'] =
            (string) $context['service']->id;

        $metadata['service_code'] =
            $context['service']->code ?? null;

        $metadata['taxpayer_id'] =
            (string) $context['taxpayer']->id;

        /*
        |--------------------------------------------------------------------------
        | Edit Reason
        |--------------------------------------------------------------------------
        */

        if (
            isset($data['reason'])
            && trim((string) $data['reason']) !== ''
        ) {
            $metadata['edit_reason'] = trim(
                (string) $data['reason']
            );
        }

        $invoice->source_metadata = $metadata;

        $invoice->save();
    }

    /**
     * Synchronize invoice items with the newly calculated entries.
     *
     * Existing lines are updated by line number.
     * Missing lines are created.
     * Excess lines are deleted.
     *
     * @param array<string,mixed> $context
     * @param array<string,mixed> $calculation
     */
    protected function updateInvoiceItems(
        Invoice $invoice,
        array $context,
        array $calculation
    ): void {
        /*
        |--------------------------------------------------------------------------
        | Lock Existing Items
        |--------------------------------------------------------------------------
        */

        $existingItems = $invoice->items()
            ->orderBy('line_number')
            ->lockForUpdate()
            ->get();

        $entries = $calculation['entries'];

        /*
        |--------------------------------------------------------------------------
        | Synchronize Each Entry
        |--------------------------------------------------------------------------
        */

        foreach ($entries as $position => $entry) {
            $lineNumber = $position + 1;

            $entryCalculation = $entry['calculation'];

            $item = $existingItems->firstWhere(
                'line_number',
                $lineNumber
            );

            /*
            |--------------------------------------------------------------------------
            | Create Missing Item
            |--------------------------------------------------------------------------
            */

            if (! $item) {
                $item = new InvoiceItem();

                $item->invoice_id = $invoice->id;

                $item->assessment_service_id = null;

                $item->line_number = $lineNumber;
            }

            /*
            |--------------------------------------------------------------------------
            | Revenue Service
            |--------------------------------------------------------------------------
            */

            $item->service_id = $context['service']->id;

            /*
            |--------------------------------------------------------------------------
            | Description
            |--------------------------------------------------------------------------
            */

            $serviceName = (string) (
                $context['service']->name
                ?? $context['service']->service_name
                ?? $context['service']->code
                ?? 'Revenue Service'
            );

            $item->description = sprintf(
                '%s - Entry %d',
                $serviceName,
                $lineNumber,
            );

            /*
            |--------------------------------------------------------------------------
            | Quantity
            |--------------------------------------------------------------------------
            */

            $item->quantity = $entryCalculation['quantity'] ?? null;

            /*
            |--------------------------------------------------------------------------
            | Unit
            |--------------------------------------------------------------------------
            */

            $item->unit = $entryCalculation['unit'] ?? null;

            /*
            |--------------------------------------------------------------------------
            | Unit Price
            |--------------------------------------------------------------------------
            */

            $item->unit_price = $entryCalculation['unit_price'] ?? null;

            /*
            |--------------------------------------------------------------------------
            | Amount
            |--------------------------------------------------------------------------
            */

            $item->amount = $entryCalculation['amount'];

            $item->discount_amount = 0;

            $item->penalty_amount = 0;

            $item->interest_amount = 0;

            $item->total_amount = $entryCalculation['amount'];

            /*
            |--------------------------------------------------------------------------
            | Currency
            |--------------------------------------------------------------------------
            */

            $item->currency = $entryCalculation['currency'];

            /*
            |--------------------------------------------------------------------------
            | Tariff Version
            |--------------------------------------------------------------------------
            */

            $item->tariff_version_id =
                $entryCalculation['tariff_version_id'];

            /*
            |--------------------------------------------------------------------------
            | Tariff Rule
            |--------------------------------------------------------------------------
            */

            $item->tariff_rule_id =
                $entryCalculation['tariff_rule_id'];

            /*
            |--------------------------------------------------------------------------
            | Input Snapshot
            |--------------------------------------------------------------------------
            */

            $item->input_snapshot =
                $entryCalculation['input_snapshot'];

            /*
            |--------------------------------------------------------------------------
            | Calculation Snapshot
            |--------------------------------------------------------------------------
            */

            $item->calculation_snapshot =
                $entryCalculation['calculation_snapshot'];

            /*
            |--------------------------------------------------------------------------
            | Save
            |--------------------------------------------------------------------------
            */

            $item->save();
        }

        /*
        |--------------------------------------------------------------------------
        | Delete Excess Items
        |--------------------------------------------------------------------------
        */

        $existingItems
            ->filter(
                fn (InvoiceItem $item): bool =>
                    (int) $item->line_number > count($entries)
            )
            ->each(
                fn (InvoiceItem $item) => $item->delete()
            );
    }


/**
 * Timezone used by field collectors.
 *
 * Date filters are interpreted as calendar dates in Africa/Addis_Ababa,
 * then converted to the configured Laravel application timezone.
 */
private const BUSINESS_TIMEZONE = 'Africa/Addis_Ababa';

private const MAX_SEARCH_LENGTH = 100;
private const MAX_SEARCH_TOKENS = 5;

private const DEFAULT_PER_PAGE = 20;
private const MAX_PER_PAGE = 100;

/**
 * Get paginated direct collections.
 *
 * Supported filters:
 * - search              Invoice number, taxpayer name, or phone.
 * - status              One status or comma-separated statuses.
 * - taxpayer_id         Filter by taxpayer.
 * - revenue_service_id  Filter by revenue service.
 * - from_date           Inclusive YYYY-MM-DD.
 * - to_date             Inclusive YYYY-MM-DD.
 * - per_page            Number of results per page (1-100).
 *
 * @param array<string, mixed> $filters
 */
public function paginate(array $filters = []): LengthAwarePaginator
{
    $this->ensureAuthenticated();

    $query = Invoice::query()
        ->with([
            'citizen',
            'items',
            'items.service',
        ])
        ->where('source_type', 'DIRECT_COLLECTION');

    $this->applySearch(
        $query,
        $filters['search'] ?? null
    );

    $this->applyStatus(
        $query,
        $filters['status'] ?? null
    );

    $this->applyTaxpayer(
        $query,
        $filters['taxpayer_id'] ?? null
    );

    $this->applyRevenueService(
        $query,
        $filters['revenue_service_id'] ?? null
    );

    $this->applyDateRange(
        $query,
        $filters['from_date'] ?? null,
        $filters['to_date'] ?? null
    );

    // Stable ordering prevents duplicate or skipped records
    // when multiple invoices have the same creation timestamp.
    $query
        ->orderByDesc('created_at')
        ->orderByDesc('id');

    $requestedPerPage = $filters['per_page']
        ?? self::DEFAULT_PER_PAGE;

    $perPage = max(
        1,
        min(
            (int) $requestedPerPage,
            self::MAX_PER_PAGE
        )
    );

    return $query->paginate($perPage);
}


/**
 * Search direct collections by:
 *
 * - Invoice number
 * - Citizen full name
 * - Citizen phone number
 * - Citizen email
 * - Citizen national ID
 * - Citizen UID
 *
 * Multi-word names are supported.
 *
 * Examples:
 * Abebe
 * Abebe Kebede
 * 0911234567
 * +251911234567
 * 911234567
 */
private function applySearch(
    Builder $query,
    mixed $value
): void {
    $search = $this->cleanString($value);

    if ($search === null) {
        return;
    }

    $search = mb_substr(
        $search,
        0,
        self::MAX_SEARCH_LENGTH
    );

    $tokens = preg_split('/\s+/u', $search) ?: [];

    $tokens = array_slice(
        array_values(array_filter($tokens)),
        0,
        self::MAX_SEARCH_TOKENS
    );

    if ($tokens === []) {
        return;
    }

    $phrasePattern = $this->likePattern($search);

    $query->where(
        function (Builder $builder) use (
            $phrasePattern,
            $tokens
        ): void {

            // 1. Search invoice number using the complete phrase.
            $builder->where(
                'invoice_number',
                'ilike',
                $phrasePattern
            );

            // 2. Search citizen information.
            $builder->orWhereHas(
                'citizen',
                function (Builder $citizenQuery) use ($tokens): void {

                    // Every search token must match at least one citizen field.
                    foreach ($tokens as $token) {
                        $pattern = $this->likePattern($token);
                        $phone = $this->normalizePhone($token);

                        $citizenQuery->where(
                            function (Builder $group) use (
                                $pattern,
                                $phone
                            ): void {

                                $group
                                    ->where('full_name', 'ilike', $pattern)
                                    ->orWhere('phone', 'ilike', $pattern)
                                    ->orWhere('email', 'ilike', $pattern)
                                    ->orWhere('national_id', 'ilike', $pattern)
                                    ->orWhere('citizen_uid', 'ilike', $pattern);

                                // Also support Ethiopian phone formats.
                                if ($phone !== null) {
                                    $group->orWhere(
                                        'phone',
                                        'ilike',
                                        $this->likePattern($phone)
                                    );
                                }
                            }
                        );
                    }
                }
            );
        }
    );
}


// ============================================================================
// STATUS
// ============================================================================

/**
 * Supports:
 *
 * PAID
 * paid
 * ISSUED,OVERDUE
 * PAID,PARTIALLY_PAID
 */
private function applyStatus(
    Builder $query,
    mixed $value
): void {
    $raw = $this->cleanString($value);

    if ($raw === null) {
        return;
    }

    $statuses = array_values(array_unique(array_filter(
        array_map(
            static fn (string $status): string => strtoupper(trim($status)),
            explode(',', $raw)
        )
    )));

    if ($statuses === []) {
        return;
    }

    if (count($statuses) === 1) {
        $query->where('status', $statuses[0]);

        return;
    }

    $query->whereIn('status', $statuses);
}

// ============================================================================
// TAXPAYER
// ============================================================================

private function applyTaxpayer(
    Builder $query,
    mixed $value
): void {
    $taxpayerId = $this->cleanString($value);

    if ($taxpayerId === null) {
        return;
    }

    $query->where('citizen_id', $taxpayerId);
}

// ============================================================================
// REVENUE SERVICE
// ============================================================================

/**
 * Filter invoices containing at least one item belonging
 * to the selected revenue service.
 */
private function applyRevenueService(
    Builder $query,
    mixed $value
): void {
    $serviceId = $this->cleanString($value);

    if ($serviceId === null) {
        return;
    }

    $query->whereHas(
        'items',
        function (Builder $itemQuery) use ($serviceId): void {
            $itemQuery->where('service_id', $serviceId);
        }
    );
}

// ============================================================================
// DATE RANGE
// ============================================================================

/**
 * Apply inclusive date filtering using the business timezone.
 *
 * from_date = 2026-11-01
 * to_date   = 2026-11-06
 *
 * Includes all records created during those calendar days
 * in Africa/Addis_Ababa.
 *
 * Uses direct timestamp comparisons instead of whereDate(),
 * allowing normal indexes on created_at to be used.
 */
private function applyDateRange(
    Builder $query,
    mixed $from,
    mixed $to
): void {
    $start = $this->parseDay($from);
    $end = $this->parseDay($to);

    // Swap reversed dates instead of silently returning no results.
    if (
        $start !== null &&
        $end !== null &&
        $start->gt($end)
    ) {
        [$start, $end] = [$end, $start];
    }

    $appTimezone = config('app.timezone', 'UTC');

    if ($start !== null) {
        $query->where(
            'created_at',
            '>=',
            $start
                ->copy()
                ->startOfDay()
                ->setTimezone($appTimezone)
        );
    }

    if ($end !== null) {
        $query->where(
            'created_at',
            '<=',
            $end
                ->copy()
                ->endOfDay()
                ->setTimezone($appTimezone)
        );
    }
}

/**
 * Strictly parse YYYY-MM-DD using the business timezone.
 *
 * Invalid dates such as 2026-02-31 return null.
 */
private function parseDay(mixed $value): ?Carbon
{
    $string = $this->cleanString($value);

    if ($string === null) {
        return null;
    }

    try {
        $date = Carbon::createFromFormat(
            '!Y-m-d',
            $string,
            self::BUSINESS_TIMEZONE
        );
    } catch (\Throwable) {
        return null;
    }

    if (
        $date === false ||
        $date->format('Y-m-d') !== $string
    ) {
        return null;
    }

    return $date;
}

// ============================================================================
// GENERAL HELPERS
// ============================================================================

/**
 * Convert scalar input to a trimmed string.
 *
 * Returns null for null, empty, or non-scalar values.
 */
private function cleanString(mixed $value): ?string
{
    if (
        $value === null ||
        !is_scalar($value)
    ) {
        return null;
    }

    $string = trim((string) $value);

    return $string === '' ? null : $string;
}

/**
 * Escape LIKE wildcard characters supplied by the user.
 *
 * PostgreSQL uses backslash as the default LIKE escape character.
 */
private function likePattern(string $term): string
{
    $escaped = str_replace(
        ['\\', '%', '_'],
        ['\\\\', '\\%', '\\_'],
        $term
    );

    return "%{$escaped}%";
}

/**
 * Normalize Ethiopian phone numbers for flexible searching.
 *
 * Examples:
 *
 * 0911234567   => 911234567
 * +251911234567 => 911234567
 * 251911234567  => 911234567
 * 911234567     => 911234567
 *
 * Returns null when the token does not look like a phone number.
 */
private function normalizePhone(string $token): ?string
{
    $digits = preg_replace('/\D+/', '', $token) ?? '';

    if (strlen($digits) < 5) {
        return null;
    }

    if (str_starts_with($digits, '251')) {
        $digits = substr($digits, 3);
    } elseif (str_starts_with($digits, '0')) {
        $digits = substr($digits, 1);
    }

    return strlen($digits) >= 4
        ? $digits
        : null;
}


    /**
     * Find one direct collection.
     */
    public function find(string $invoiceId): Invoice
    {
        $this->ensureAuthenticated();

        return Invoice::query()
            ->with([
                'citizen',
                'items',
                'items.service',
            ])
            ->where('source_type', 'DIRECT_COLLECTION')
            ->where('id', $invoiceId)
            ->firstOrFail();
    }

    /**
     * Require authenticated user.
     */
    protected function ensureAuthenticated(): void
    {
        if (! Auth::user()) {
            throw ValidationException::withMessages([
                'user' => [
                    'An authenticated user is required.',
                ],
            ]);
        }
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
