<?php

declare(strict_types=1);

namespace App\Modules\DirectCollection\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use App\Services\Authorization\AdministrativeScopeService;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DirectCollectionService
{
    private const BUSINESS_TIMEZONE = 'Africa/Addis_Ababa';

    private const MAX_SEARCH_LENGTH = 100;
    private const MAX_SEARCH_TOKENS = 5;

    private const DEFAULT_PER_PAGE = 20;
    private const MAX_PER_PAGE = 100;

    public function __construct(
        protected DirectCollectionInputService $inputService,
        protected DirectCollectionCalculator $calculator,
        protected DirectCollectionInvoiceService $invoiceService,
        protected AdministrativeScopeService $administrativeScopeService,
    ) {
    }

    /**
     * Build a direct-collection invoice query restricted to the
     * authenticated user's administrative-unit scope.
     *
     * Direct-collection invoices do not have an assessment, so
     * sector authorization is not applied here.
     */
    protected function authorizedDirectCollectionInvoiceQuery(
        User $user
    ): Builder {
        $query = Invoice::query()
            ->where('invoices.source_type', 'DIRECT_COLLECTION');

        $this->administrativeScopeService->applyAdministrativeScope(
            $query,
            $user,
            'invoices.administrative_unit_id'
        );

        return $query;
    }

    /**
     * Calculate direct collection amount.
     *
     * This does not create an invoice.
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
     * Build the frontend review payload.
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
                'quantity' => $entryCalculation['quantity'] ?? null,
                'unit' => $entryCalculation['unit'] ?? null,
                'unit_price' => $entryCalculation['unit_price'] ?? null,
                'amount' => $entryCalculation['amount'],
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
        | 1. Resolve and validate inputs
        |--------------------------------------------------------------------------
        */

        $context = $this->inputService->resolve($data);

        /*
        |--------------------------------------------------------------------------
        | 2. Calculate every entry on the server
        |--------------------------------------------------------------------------
        |
        | Never trust frontend amounts or tariff information.
        |
        */

        $calculation = $this->calculateEntries($context);

        /*
        |--------------------------------------------------------------------------
        | 3. Create and issue one invoice
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
     * The invoice number and administrative unit remain unchanged.
     *
     * @param string $invoiceId
     * @param array<string,mixed> $data
     */
    public function update(
        string $invoiceId,
        array $data
    ): Invoice {
        $user = $this->ensureAuthenticated();

        return DB::transaction(
            function () use ($invoiceId, $data, $user): Invoice {

                /*
                |--------------------------------------------------------------------------
                | 1. Find and lock an authorized invoice
                |--------------------------------------------------------------------------
                |
                | The administrative scope is applied before retrieving
                | the invoice. An invoice outside the user's scope is
                | therefore not available for modification.
                |
                */

                $invoice = $this->authorizedDirectCollectionInvoiceQuery($user)
                    ->where('invoices.id', $invoiceId)
                    ->lockForUpdate()
                    ->firstOrFail();

                /*
                |--------------------------------------------------------------------------
                | 2. Verify invoice status
                |--------------------------------------------------------------------------
                */

                $status = strtoupper((string) $invoice->status);

                if ($status !== 'ISSUED') {
                    throw ValidationException::withMessages([
                        'invoice' => [
                            'Only pending-payment direct collections can be edited.',
                        ],
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | 3. Verify payment state
                |--------------------------------------------------------------------------
                */

                $paidAmount = (float) ($invoice->paid_amount ?? 0);

                if ($paidAmount > 0) {
                    throw ValidationException::withMessages([
                        'invoice' => [
                            'This direct collection cannot be edited because payment has already been recorded.',
                        ],
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | 4. Resolve new input
                |--------------------------------------------------------------------------
                */

                $context = $this->inputService->resolve($data);

                /*
                |--------------------------------------------------------------------------
                | 5. Recalculate every entry
                |--------------------------------------------------------------------------
                */

                $calculation = $this->calculateEntries($context);

                /*
                |--------------------------------------------------------------------------
                | 6. Update invoice
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
                | 7. Synchronize invoice items
                |--------------------------------------------------------------------------
                */

                $this->updateInvoiceItems(
                    invoice: $invoice,
                    context: $context,
                    calculation: $calculation,
                );

                /*
                |--------------------------------------------------------------------------
                | 8. Return updated invoice
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
            | Individual tariff calculation
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
            | Validate entry amount
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
            | Resolve currency
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
            | Aggregate amount
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
            | Preserve entry calculation
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
        | Validate total amount
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
        | Build aggregate input snapshot
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
        | Build aggregate calculation snapshot
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
     * The administrative_unit_id is deliberately not updated from
     * request data. A client must not be able to move an invoice
     * between administrative units through this update operation.
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
        | Financial values
        |--------------------------------------------------------------------------
        */

        $invoice->subtotal = $amount;
        $invoice->discount_amount = 0;
        $invoice->penalty_amount = 0;
        $invoice->interest_amount = 0;
        $invoice->total_amount = $amount;

        /*
        |--------------------------------------------------------------------------
        | Payment state
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

        $invoice->currency = strtoupper((string) $currency);

        /*
        |--------------------------------------------------------------------------
        | Due date
        |--------------------------------------------------------------------------
        |
        | Direct collection does not have a due date.
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
        | Administrative unit
        |--------------------------------------------------------------------------
        |
        | Intentionally preserved from the existing invoice.
        | Do not assign administrative_unit_id from request data.
        |
        */

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
        | Preserve ISSUED status
        |--------------------------------------------------------------------------
        */

        $invoice->status = 'ISSUED';

        /*
        |--------------------------------------------------------------------------
        | Updated by
        |--------------------------------------------------------------------------
        */

        if ($invoice->isFillable('updated_by')) {
            $invoice->updated_by = Auth::id();
        }

        /*
        |--------------------------------------------------------------------------
        | Source metadata
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
                'calculation' =>
                    $entry['calculation']['calculation_snapshot'],
                'input_snapshot' =>
                    $entry['calculation']['input_snapshot'],
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
        | Edit reason
        |--------------------------------------------------------------------------
        */

        if (
            isset($data['reason'])
            && trim((string) $data['reason']) !== ''
        ) {
            $metadata['edit_reason'] = trim((string) $data['reason']);
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
        | Lock existing items
        |--------------------------------------------------------------------------
        */

        $existingItems = $invoice->items()
            ->orderBy('line_number')
            ->lockForUpdate()
            ->get();

        $entries = $calculation['entries'];

        /*
        |--------------------------------------------------------------------------
        | Synchronize each entry
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
            | Create missing item
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
            | Revenue service
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
            | Quantity and unit
            |--------------------------------------------------------------------------
            */

            $item->quantity = $entryCalculation['quantity'] ?? null;
            $item->unit = $entryCalculation['unit'] ?? null;

            /*
            |--------------------------------------------------------------------------
            | Unit price and amount
            |--------------------------------------------------------------------------
            */

            $item->unit_price = $entryCalculation['unit_price'] ?? null;
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
            | Tariff version and rule
            |--------------------------------------------------------------------------
            */

            $item->tariff_version_id =
                $entryCalculation['tariff_version_id'];

            $item->tariff_rule_id =
                $entryCalculation['tariff_rule_id'];

            /*
            |--------------------------------------------------------------------------
            | Input and calculation snapshots
            |--------------------------------------------------------------------------
            */

            $item->input_snapshot =
                $entryCalculation['input_snapshot'];

            $item->calculation_snapshot =
                $entryCalculation['calculation_snapshot'];

            $item->save();
        }

        /*
        |--------------------------------------------------------------------------
        | Delete excess items
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
     * @param array<string,mixed> $filters
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $user = $this->ensureAuthenticated();

        $query = $this->authorizedDirectCollectionInvoiceQuery($user)
            ->with([
                'citizen',
                'items',
                'items.service',
            ]);

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

        $query
            ->orderByDesc('invoices.created_at')
            ->orderByDesc('invoices.id');

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
     * Search direct collections by invoice and citizen information.
     *
     * Supports:
     * - Invoice number
     * - Citizen full name
     * - Citizen phone number
     * - Citizen email
     * - Citizen national ID
     * - Citizen UID
     *
     * Multi-word names are supported.
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

                /*
                |--------------------------------------------------------------------------
                | Invoice number
                |--------------------------------------------------------------------------
                */

                $builder->where(
                    'invoices.invoice_number',
                    'ilike',
                    $phrasePattern
                );

                /*
                |--------------------------------------------------------------------------
                | Citizen information
                |--------------------------------------------------------------------------
                */

                $builder->orWhereHas(
                    'citizen',
                    function (Builder $citizenQuery) use ($tokens): void {

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

    /**
     * Apply status filter.
     *
     * Supports:
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
            $query->where('invoices.status', $statuses[0]);

            return;
        }

        $query->whereIn('invoices.status', $statuses);
    }

    /**
     * Filter by taxpayer.
     */
    private function applyTaxpayer(
        Builder $query,
        mixed $value
    ): void {
        $taxpayerId = $this->cleanString($value);

        if ($taxpayerId === null) {
            return;
        }

        $query->where('invoices.citizen_id', $taxpayerId);
    }

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

    /**
     * Apply inclusive date filtering using the business timezone.
     *
     * Date values are interpreted as calendar dates in
     * Africa/Addis_Ababa and converted to the application timezone.
     */
    private function applyDateRange(
        Builder $query,
        mixed $from,
        mixed $to
    ): void {
        $start = $this->parseDay($from);
        $end = $this->parseDay($to);

        if (
            $start !== null
            && $end !== null
            && $start->gt($end)
        ) {
            [$start, $end] = [$end, $start];
        }

        $appTimezone = config('app.timezone', 'UTC');

        if ($start !== null) {
            $query->where(
                'invoices.created_at',
                '>=',
                $start
                    ->copy()
                    ->startOfDay()
                    ->setTimezone($appTimezone)
            );
        }

        if ($end !== null) {
            $query->where(
                'invoices.created_at',
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
            $date === false
            || $date->format('Y-m-d') !== $string
        ) {
            return null;
        }

        return $date;
    }

    /**
     * Convert scalar input to a trimmed string.
     *
     * Returns null for null, empty, or non-scalar values.
     */
    private function cleanString(mixed $value): ?string
    {
        if (
            $value === null
            || ! is_scalar($value)
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
     * 0911234567    => 911234567
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
     * Find one authorized direct collection.
     */
    public function find(string $invoiceId): Invoice
    {
        $user = $this->ensureAuthenticated();

        return $this->authorizedDirectCollectionInvoiceQuery($user)
            ->with([
                'citizen',
                'items',
                'items.service',
            ])
            ->where('invoices.id', $invoiceId)
            ->firstOrFail();
    }

    /**
     * Require an authenticated user.
     */
    protected function ensureAuthenticated(): User
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw ValidationException::withMessages([
                'user' => [
                    'An authenticated user is required.',
                ],
            ]);
        }

        return $user;
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
