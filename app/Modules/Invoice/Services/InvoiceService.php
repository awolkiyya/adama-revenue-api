<?php

namespace App\Modules\Invoice\Services;

use App\Enums\PaymentScheduleStatus;
use App\Models\Assessment;
use App\Models\AssessmentService as AssessmentServiceModel;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PaymentSchedule;
use App\Models\User;
use App\Services\Authorization\AdministrativeScopeService;
use App\Services\Authorization\SectorAccessService;
use App\Services\DocumentSequenceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InvoiceService
{
    public function __construct(
        protected DocumentSequenceService $documentSequenceService,
        protected AdministrativeScopeService $administrativeScope,
        protected SectorAccessService $sectorAccessService,
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | INVOICE AUTHORIZATION
    |--------------------------------------------------------------------------
    */

    /**
     * Resolve the authenticated application user.
     */
    protected function authenticatedUser(): User
    {
        $user = Auth::user();

        abort_unless(
            $user instanceof User,
            401,
            'Authentication is required to access invoices.'
        );

        return $user;
    }

    /**
     * Apply administrative and sector authorization to an invoice query.
     *
     * Rules:
     * - SYSTEM_ADMIN has global access.
     * - All other users are restricted by administrative unit.
     * - Assessment invoices additionally require access to every
     *   service associated with the assessment.
     * - Direct-collection invoices require administrative access only.
     */
    protected function applyInvoiceAccessScope(
        Builder $query,
        ?User $user = null
    ): Builder {
        $user ??= $this->authenticatedUser();

        // Administrative unit and descendant authorization.
        $this->administrativeScope->applyAdministrativeScope(
            $query,
            $user,
            'invoices.administrative_unit_id'
        );

        // Global users bypass sector-level restrictions.
        if ($this->sectorAccessService->isGlobalUser($user)) {
            return $query;
        }

        return $query->where(
            function (Builder $invoiceQuery) use ($user) {
                // Direct collection / non-assessment invoices.
                $invoiceQuery->whereNull(
                    'invoices.assessment_id'
                );

                // Assessment-based invoices.
                $invoiceQuery->orWhereHas(
                    'assessment',
                    function (Builder $assessmentQuery) use ($user) {
                        $this->sectorAccessService
                            ->scopeAssessmentsForUser(
                                $assessmentQuery,
                                $user,
                                requireAllServices: true
                            );
                    }
                );
            }
        );
    }

    /**
     * Authorize access to an invoice's assessment.
     *
     * Administrative authorization must already have been applied
     * when retrieving the invoice.
     */
    protected function authorizeInvoiceAssessment(
        Invoice $invoice,
        ?User $user = null
    ): void {
        $user ??= $this->authenticatedUser();

        if ($this->sectorAccessService->isGlobalUser($user)) {
            return;
        }

        // Direct collection invoices do not require sector authorization.
        if (!$invoice->assessment_id) {
            return;
        }

        $assessment = $invoice->assessment;

        abort_unless(
            $assessment !== null,
            404,
            'Invoice assessment was not found.'
        );

        $this->sectorAccessService->authorizeAssessment(
            $user,
            $assessment,
            requireAllServices: true
        );
    }

    /*
    |--------------------------------------------------------------------------
    | LIST INVOICES
    |--------------------------------------------------------------------------
    */

    public function paginate(
        array $filters = [],
        int $perPage = 20
    ) {
        $perPage = max(1, min($perPage, 100));

        $query = Invoice::query()
            ->with([
                'citizen',
                'assessment',
                'items.service',
                'items.paymentSchedule',
                'administrativeUnit',
                'creator',
                'issuer',
            ]);

        // Always apply authorization before user-supplied filters.
        $this->applyInvoiceAccessScope($query);

        $this->applyListFilters(
            $query,
            $filters
        );

        return $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /*
    |--------------------------------------------------------------------------
    | PAGINATE UNPAID INVOICES
    |--------------------------------------------------------------------------
    */

    public function paginateUnpaid(
        string $search,
        int $perPage = 20
    ) {
        $search = trim($search);
        $perPage = max(1, min($perPage, 50));

        if (mb_strlen($search) < 2) {
            throw ValidationException::withMessages([
                'search' => [
                    'The search term must contain at least 2 characters.',
                ],
            ]);
        }

        $searchTerm = "%{$search}%";

        $query = Invoice::query()
            ->with([
                'citizen',
                'assessment',
                'items.service',
                'items.paymentSchedule',
                'administrativeUnit',
                'creator',
                'issuer',
            ]);

        // Authorization scope.
        $this->applyInvoiceAccessScope($query);

        // Financial eligibility.
        $query
            ->whereIn('status', [
                'ISSUED',
                'PARTIALLY_PAID',
                'OVERDUE',
            ])
            ->where('balance_due', '>', 0);

        // Search by invoice number or assessment number.
        $query->where(
            function (Builder $searchQuery) use ($searchTerm) {
                $searchQuery->where(
                    'invoice_number',
                    'like',
                    $searchTerm
                );

                $searchQuery->orWhereHas(
                    'assessment',
                    function (Builder $assessmentQuery) use ($searchTerm) {
                        $assessmentQuery->where(
                            'assessment_number',
                            'like',
                            $searchTerm
                        );
                    }
                );
            }
        );

        return $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /*
    |--------------------------------------------------------------------------
    | INVOICE SUMMARY
    |--------------------------------------------------------------------------
    */

    public function summary(
        array $filters = []
    ): array {
        $query = Invoice::query();

        // Summary must use exactly the same authorization as invoice listing.
        $this->applyInvoiceAccessScope($query);

        $this->applyListFilters(
            $query,
            $filters
        );

        $financial = (clone $query)
            ->selectRaw('COUNT(*) as total_invoices')
            ->selectRaw('COALESCE(SUM(subtotal), 0) as subtotal')
            ->selectRaw('COALESCE(SUM(discount_amount), 0) as discount_amount')
            ->selectRaw('COALESCE(SUM(penalty_amount), 0) as penalty_amount')
            ->selectRaw('COALESCE(SUM(interest_amount), 0) as interest_amount')
            ->selectRaw('COALESCE(SUM(total_amount), 0) as total_amount')
            ->selectRaw('COALESCE(SUM(paid_amount), 0) as paid_amount')
            ->selectRaw('COALESCE(SUM(balance_due), 0) as balance_due')
            ->first();

        $statusCounts = (clone $query)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        return [
            'total_invoices' =>
                (int) $financial->total_invoices,

            'subtotal' =>
                (float) $financial->subtotal,

            'discount_amount' =>
                (float) $financial->discount_amount,

            'penalty_amount' =>
                (float) $financial->penalty_amount,

            'interest_amount' =>
                (float) $financial->interest_amount,

            'total_amount' =>
                (float) $financial->total_amount,

            'paid_amount' =>
                (float) $financial->paid_amount,

            'balance_due' =>
                (float) $financial->balance_due,

            'status_counts' => [
                'DRAFT' =>
                    (int) ($statusCounts['DRAFT'] ?? 0),

                'ISSUED' =>
                    (int) ($statusCounts['ISSUED'] ?? 0),

                'PARTIALLY_PAID' =>
                    (int) ($statusCounts['PARTIALLY_PAID'] ?? 0),

                'PAID' =>
                    (int) ($statusCounts['PAID'] ?? 0),

                'OVERDUE' =>
                    (int) ($statusCounts['OVERDUE'] ?? 0),

                'CANCELLED' =>
                    (int) ($statusCounts['CANCELLED'] ?? 0),

                'VOID' =>
                    (int) ($statusCounts['VOID'] ?? 0),
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | APPLY INVOICE LIST FILTERS
    |--------------------------------------------------------------------------
    */

    protected function applyListFilters(
        Builder $query,
        array $filters
    ): void {
        // General search.
        if (!empty($filters['search'])) {
            $search = trim((string) $filters['search']);

            if ($search !== '') {
                $query->where(
                    function (Builder $q) use ($search) {
                        $q->where(
                            'invoice_number',
                            'like',
                            "%{$search}%"
                        );

                        $q->orWhereHas(
                            'citizen',
                            function (Builder $citizenQuery) use ($search) {
                                $citizenQuery
                                    ->where(
                                        'citizen_number',
                                        'like',
                                        "%{$search}%"
                                    )
                                    ->orWhere(
                                        'name',
                                        'like',
                                        "%{$search}%"
                                    );
                            }
                        );

                        $q->orWhereHas(
                            'assessment',
                            function (Builder $assessmentQuery) use ($search) {
                                $assessmentQuery->where(
                                    'assessment_number',
                                    'like',
                                    "%{$search}%"
                                );
                            }
                        );
                    }
                );
            }
        }

        // Fiscal year.
        if (
            isset($filters['fiscal_year'])
            && $filters['fiscal_year'] !== ''
        ) {
            $query->where(
                'fiscal_year',
                (int) $filters['fiscal_year']
            );
        }

        // Status: supports a single status or an array of statuses.
        if (
            isset($filters['status'])
            && $filters['status'] !== ''
        ) {
            if (is_array($filters['status'])) {
                $statuses = array_values(
                    array_filter(
                        array_map(
                            static fn ($status) => trim((string) $status),
                            $filters['status']
                        ),
                        static fn ($status) => $status !== ''
                    )
                );

                if ($statuses !== []) {
                    $query->whereIn('status', $statuses);
                }
            } else {
                $query->where(
                    'status',
                    trim((string) $filters['status'])
                );
            }
        }

        // Source type.
        if (!empty($filters['source_type'])) {
            $query->where(
                'source_type',
                $filters['source_type']
            );
        }

        // Administrative unit.
        if (!empty($filters['administrative_unit_id'])) {
            $query->where(
                'administrative_unit_id',
                $filters['administrative_unit_id']
            );
        }

        // Due date range.
        if (!empty($filters['due_date_from'])) {
            $query->whereDate(
                'due_date',
                '>=',
                $filters['due_date_from']
            );
        }

        if (!empty($filters['due_date_to'])) {
            $query->whereDate(
                'due_date',
                '<=',
                $filters['due_date_to']
            );
        }

        // Issued date range.
        if (!empty($filters['issued_from'])) {
            $query->whereDate(
                'issued_at',
                '>=',
                $filters['issued_from']
            );
        }

        if (!empty($filters['issued_to'])) {
            $query->whereDate(
                'issued_at',
                '<=',
                $filters['issued_to']
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | FIND INVOICE FOR DETAILS
    |--------------------------------------------------------------------------
    |
    | The invoice is retrieved through the same authorization scope used
    | by listing and summary queries.
    |
    | Unauthorized invoices are therefore returned as not found.
    |--------------------------------------------------------------------------
    */

    public function findForDetails(
        string $id
    ): ?Invoice {
        $query = Invoice::query()
            ->with([
                // Invoice source / owner.
                'citizen',
                'assessment',
                'administrativeUnit',

                // Invoice items.
                'items.service',
                'items.assessmentService',
                'items.paymentSchedule',

                // Payment history.
                'payments.processedBy',
                'payments.verifiedBy',

                // Payment method details.
                'payments.cashDetails',
                'payments.bankTransferDetails',
                'payments.onlineDetails',

                // Payment files and receipts.
                'payments.files',
                'payments.receipt',

                // Audit relationships.
                'creator',
                'issuer',
                'cancelledBy',
                'voidedBy',
            ]);

        $this->applyInvoiceAccessScope($query);

        $invoice = $query->find($id);

        if ($invoice) {
            $this->authorizeInvoiceAssessment($invoice);
        }

        return $invoice;
    }

    /*
    |--------------------------------------------------------------------------
    | CREATE INVOICE FROM APPROVED ASSESSMENT SERVICES
    |--------------------------------------------------------------------------
    |
    | This method creates DRAFT invoices only.
    |
    | InvoiceIssuanceService remains the only authority responsible
    | for the DRAFT -> ISSUED transition.
    |--------------------------------------------------------------------------
    */

    public function createFromAssessmentServices(
        Assessment $assessment,
        Collection $invoiceableServices,
    ): Invoice {
        return DB::transaction(
            function () use (
                $assessment,
                $invoiceableServices
            ): Invoice {
                /*
                |--------------------------------------------------------------------------
                | 1. LOCK ASSESSMENT
                |--------------------------------------------------------------------------
                */

                $assessment = Assessment::query()
                    ->with([
                        'citizen',
                        'services.service',
                        'services.values',
                    ])
                    ->lockForUpdate()
                    ->findOrFail($assessment->id);

                /*
                |--------------------------------------------------------------------------
                | 2. VALIDATE ASSESSMENT STATUS
                |--------------------------------------------------------------------------
                */

                if ($assessment->status !== 'APPROVED') {
                    throw ValidationException::withMessages([
                        'assessment' => [
                            'Only approved assessments can generate an invoice.',
                        ],
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | 3. VALIDATE SUPPLIED SERVICES
                |--------------------------------------------------------------------------
                */

                if ($invoiceableServices->isEmpty()) {
                    throw ValidationException::withMessages([
                        'assessment' => [
                            'No invoiceable assessment services were supplied.',
                        ],
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | 4. BUILD LOCKED SERVICE MAP
                |--------------------------------------------------------------------------
                */

                $loadedServices = $assessment->services->keyBy(
                    fn (AssessmentServiceModel $service) =>
                        (string) $service->id
                );

                /*
                |--------------------------------------------------------------------------
                | 5. VALIDATE OWNERSHIP AND TYPE
                |--------------------------------------------------------------------------
                */

                foreach ($invoiceableServices as $assessmentService) {
                    if (
                        !$assessmentService
                        instanceof AssessmentServiceModel
                    ) {
                        throw ValidationException::withMessages([
                            'assessment' => [
                                'Invalid assessment service supplied for invoicing.',
                            ],
                        ]);
                    }

                    $serviceId = (string) $assessmentService->id;

                    if (!$loadedServices->has($serviceId)) {
                        throw ValidationException::withMessages([
                            'assessment' => [
                                'One or more invoiceable services do not belong to this assessment.',
                            ],
                        ]);
                    }
                }

                /*
                |--------------------------------------------------------------------------
                | 6. REPLACE INPUT WITH LOCKED MODELS
                |--------------------------------------------------------------------------
                */

                $invoiceableServices = $invoiceableServices
                    ->map(
                        fn (AssessmentServiceModel $service) =>
                            $loadedServices->get((string) $service->id)
                    )
                    ->filter()
                    ->unique('id')
                    ->values();

                /*
                |--------------------------------------------------------------------------
                | 7. VALIDATE SERVICES
                |--------------------------------------------------------------------------
                */

                $assessmentDueDate = null;

                foreach ($invoiceableServices as $assessmentService) {
                    if ($assessmentService->status !== 'COMPLETED') {
                        throw ValidationException::withMessages([
                            'assessment' => [
                                'All invoiceable assessment services must be completed before an invoice can be generated.',
                            ],
                        ]);
                    }

                    if ($assessmentService->computed_amount === null) {
                        throw ValidationException::withMessages([
                            'assessment' => [
                                'Every invoiceable assessment service must have a computed amount before invoicing.',
                            ],
                        ]);
                    }

                    if ((float) $assessmentService->computed_amount < 0) {
                        throw ValidationException::withMessages([
                            'assessment' => [
                                'Every invoiceable assessment service must have a non-negative computed amount before invoicing.',
                            ],
                        ]);
                    }

                    if (
                        $assessmentService->calculation_metadata !== null
                        && !is_array($assessmentService->calculation_metadata)
                    ) {
                        throw ValidationException::withMessages([
                            'assessment' => [
                                'Invalid calculation metadata found for an assessment service.',
                            ],
                        ]);
                    }

                    if (!$assessmentService->service_id) {
                        throw ValidationException::withMessages([
                            'assessment' => [
                                'Every invoiceable assessment service must reference a revenue service.',
                            ],
                        ]);
                    }

                    if ($assessmentService->due_date === null) {
                        throw ValidationException::withMessages([
                            'assessment' => [
                                'Every invoiceable assessment service must have a due date before an invoice can be generated.',
                            ],
                        ]);
                    }

                    if ($assessmentDueDate === null) {
                        $assessmentDueDate = $assessmentService->due_date;
                    } elseif (
                        $assessmentDueDate->format('Y-m-d')
                        !== $assessmentService->due_date->format('Y-m-d')
                    ) {
                        throw ValidationException::withMessages([
                            'assessment' => [
                                'All invoiceable assessment services must have the same due date before an invoice can be generated.',
                            ],
                        ]);
                    }
                }

                /*
                |--------------------------------------------------------------------------
                | 8. PREVENT DUPLICATE INVOICING
                |--------------------------------------------------------------------------
                */

                $alreadyInvoicedService = InvoiceItem::query()
                    ->whereIn(
                        'assessment_service_id',
                        $invoiceableServices->pluck('id')
                    )
                    ->whereHas(
                        'invoice',
                        function (Builder $query) {
                            $query->whereIn('status', [
                                'DRAFT',
                                'ISSUED',
                                'PARTIALLY_PAID',
                                'PAID',
                                'OVERDUE',
                            ]);
                        }
                    )
                    ->exists();

                if ($alreadyInvoicedService) {
                    throw ValidationException::withMessages([
                        'assessment' => [
                            'One or more assessment services have already been invoiced.',
                        ],
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | 9. GENERATE INVOICE NUMBER
                |--------------------------------------------------------------------------
                */

                $invoiceNumber = $this->documentSequenceService->generate(
                    sequenceType: 'invoice',
                );

                /*
                |--------------------------------------------------------------------------
                | 10. CREATOR
                |--------------------------------------------------------------------------
                */

                $createdBy = Auth::id();

                /*
                |--------------------------------------------------------------------------
                | 11. CREATE DRAFT INVOICE
                |--------------------------------------------------------------------------
                */

                $invoice = Invoice::query()->create([
                    'id' => (string) Str::uuid(),
                    'invoice_number' => $invoiceNumber,
                    'source_type' => 'ASSESSMENT',
                    'assessment_id' => $assessment->id,
                    'citizen_id' => $assessment->citizen_id,
                    'administrative_unit_id' => $assessment->administrative_unit_id,
                    'status' => 'DRAFT',
                    'currency' => 'ETB',
                    'due_date' => $assessmentDueDate,
                    'subtotal' => 0,
                    'discount_amount' => 0,
                    'penalty_amount' => 0,
                    'interest_amount' => 0,
                    'total_amount' => 0,
                    'paid_amount' => 0,
                    'balance_due' => 0,
                    'created_by' => $createdBy,
                    'issued_by' => null,
                    'issued_at' => null,
                ]);

                /*
                |--------------------------------------------------------------------------
                | 12. CREATE INVOICE ITEMS
                |--------------------------------------------------------------------------
                */

                $lineNumber = 1;

                foreach ($invoiceableServices as $assessmentService) {
                    $amount = $assessmentService->computed_amount;

                    $metadata = is_array(
                        $assessmentService->calculation_metadata
                    )
                        ? $assessmentService->calculation_metadata
                        : [];

                    $tariffVersionId =
                        $metadata['tariff_version_id'] ?? null;

                    $tariffRuleId =
                        $metadata['tariff_rule_id'] ?? null;

                    $inputSnapshot = $assessmentService->values
                        ->mapWithKeys(
                            function ($value) {
                                return [
                                    $value->field_code => $value->value,
                                ];
                            }
                        )
                        ->toArray();

                    InvoiceItem::query()->create([
                        'id' => (string) Str::uuid(),
                        'invoice_id' => $invoice->id,
                        'assessment_service_id' => $assessmentService->id,
                        'payment_schedule_id' => null,
                        'service_id' => $assessmentService->service_id,
                        'line_number' => $lineNumber,
                        'description' =>
                            $assessmentService->service?->name
                            ?? 'Revenue Service',
                        'quantity' => $metadata['quantity'] ?? null,
                        'unit' => $metadata['unit'] ?? null,
                        'unit_price' => $metadata['unit_price'] ?? null,
                        'amount' => $amount,
                        'discount_amount' => 0,
                        'penalty_amount' => 0,
                        'interest_amount' => 0,
                        'total_amount' => $amount,
                        'currency' => $assessmentService->currency_code ?? 'ETB',
                        'tariff_version_id' => $tariffVersionId,
                        'tariff_rule_id' => $tariffRuleId,
                        'input_snapshot' => $inputSnapshot,
                        'calculation_snapshot' => $metadata,
                    ]);

                    $lineNumber++;
                }

                /*
                |--------------------------------------------------------------------------
                | 13. AGGREGATE TOTALS
                |--------------------------------------------------------------------------
                */

                $totals = InvoiceItem::query()
                    ->where('invoice_id', $invoice->id)
                    ->selectRaw('COALESCE(SUM(amount), 0) as subtotal')
                    ->selectRaw('COALESCE(SUM(discount_amount), 0) as discount_amount')
                    ->selectRaw('COALESCE(SUM(penalty_amount), 0) as penalty_amount')
                    ->selectRaw('COALESCE(SUM(interest_amount), 0) as interest_amount')
                    ->selectRaw('COALESCE(SUM(total_amount), 0) as total_amount')
                    ->first();

                /*
                |--------------------------------------------------------------------------
                | 14. UPDATE FINAL TOTALS
                |--------------------------------------------------------------------------
                */

                $invoice->update([
                    'subtotal' => $totals->subtotal,
                    'discount_amount' => $totals->discount_amount,
                    'penalty_amount' => $totals->penalty_amount,
                    'interest_amount' => $totals->interest_amount,
                    'total_amount' => $totals->total_amount,
                    'paid_amount' => 0,
                    'balance_due' => $totals->total_amount,
                    'status' => 'DRAFT',
                    'issued_by' => null,
                    'issued_at' => null,
                ]);

                /*
                |--------------------------------------------------------------------------
                | 15. LOG
                |--------------------------------------------------------------------------
                */

                Log::info(
                    'Invoice created successfully from assessment services.',
                    [
                        'invoice_id' => $invoice->id,
                        'invoice_number' => $invoice->invoice_number,
                        'assessment_id' => $assessment->id,
                        'status' => $invoice->status,
                        'total_amount' => $invoice->total_amount,
                        'invoiceable_service_count' => $invoiceableServices->count(),
                        'created_by' => $createdBy,
                    ]
                );

                /*
                |--------------------------------------------------------------------------
                | 16. RETURN FRESH DRAFT INVOICE
                |--------------------------------------------------------------------------
                */

                return $invoice->fresh([
                    'items',
                    'items.service',
                    'assessment',
                    'citizen',
                    'creator',
                    'issuer',
                ]);
            }
        );
    }

    /*
    |--------------------------------------------------------------------------
    | CREATE INVOICE FROM PAYMENT SCHEDULES
    |--------------------------------------------------------------------------
    |
    | One selected payment schedule creates one invoice item.
    | Multiple selected schedules create one invoice with multiple items.
    |
    | This method creates DRAFT invoices only.
    | InvoiceIssuanceService owns DRAFT -> ISSUED.
    |--------------------------------------------------------------------------
    */

    public function createFromPaymentSchedules(
        AssessmentServiceModel $assessmentService,
        Collection $paymentSchedules,
    ): Invoice {
        return DB::transaction(
            function () use (
                $assessmentService,
                $paymentSchedules
            ): Invoice {
                /*
                |--------------------------------------------------------------------------
                | 1. VALIDATE INPUT COLLECTION
                |--------------------------------------------------------------------------
                */

                if ($paymentSchedules->isEmpty()) {
                    throw ValidationException::withMessages([
                        'payment_schedule_ids' => [
                            'At least one payment schedule must be supplied.',
                        ],
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | 2. LOCK ASSESSMENT SERVICE
                |--------------------------------------------------------------------------
                */

                $lockedAssessmentService = AssessmentServiceModel::query()
                    ->with([
                        'assessment.citizen',
                        'service.revenueCode',
                        'values',
                    ])
                    ->lockForUpdate()
                    ->findOrFail($assessmentService->id);

                /*
                |--------------------------------------------------------------------------
                | 3. VALIDATE ASSESSMENT RELATION
                |--------------------------------------------------------------------------
                */

                if (!$lockedAssessmentService->assessment) {
                    throw ValidationException::withMessages([
                        'assessment_service_id' => [
                            'The assessment service is not associated with an assessment.',
                        ],
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | 4. VALIDATE ASSESSMENT STATUS
                |--------------------------------------------------------------------------
                */

                if (
                    strtoupper(
                        (string) $lockedAssessmentService->assessment->status
                    ) !== 'APPROVED'
                ) {
                    throw ValidationException::withMessages([
                        'assessment_service_id' => [
                            'Payment schedules can only be invoiced for an approved assessment.',
                        ],
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | 5. NORMALIZE IDS
                |--------------------------------------------------------------------------
                */

                $paymentScheduleIds = $paymentSchedules
                    ->pluck('id')
                    ->map(
                        static fn ($id) => (string) $id
                    )
                    ->filter(
                        static fn ($id) => trim($id) !== ''
                    )
                    ->unique()
                    ->values()
                    ->all();

                if ($paymentScheduleIds === []) {
                    throw ValidationException::withMessages([
                        'payment_schedule_ids' => [
                            'At least one valid payment schedule must be supplied.',
                        ],
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | 6. RELOAD AND LOCK SELECTED SCHEDULES
                |--------------------------------------------------------------------------
                */

                $lockedSchedules = PaymentSchedule::query()
                    ->where(
                        'assessment_service_id',
                        $lockedAssessmentService->id
                    )
                    ->whereIn('id', $paymentScheduleIds)
                    ->with([
                        'invoiceItems',
                    ])
                    ->lockForUpdate()
                    ->orderBy('installment_number')
                    ->get();

                /*
                |--------------------------------------------------------------------------
                | 7. VERIFY ALL SELECTED SCHEDULES EXIST
                |--------------------------------------------------------------------------
                */

                $foundScheduleIds = $lockedSchedules
                    ->pluck('id')
                    ->map(
                        static fn ($id) => (string) $id
                    )
                    ->values()
                    ->all();

                $missingScheduleIds = array_values(
                    array_diff(
                        $paymentScheduleIds,
                        $foundScheduleIds
                    )
                );

                if ($missingScheduleIds !== []) {
                    throw ValidationException::withMessages([
                        'payment_schedule_ids' => [
                            'One or more selected payment schedules do not belong to this assessment service.',
                        ],
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | 8. VALIDATE ASSESSMENT SERVICE
                |--------------------------------------------------------------------------
                */

                if (!$lockedAssessmentService->service_id) {
                    throw ValidationException::withMessages([
                        'assessment_service_id' => [
                            'The assessment service must reference a revenue service before an invoice can be generated.',
                        ],
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | 9. VALIDATE CALCULATION METADATA
                |--------------------------------------------------------------------------
                */

                if (
                    $lockedAssessmentService->calculation_metadata !== null
                    && !is_array($lockedAssessmentService->calculation_metadata)
                ) {
                    throw ValidationException::withMessages([
                        'assessment_service_id' => [
                            'Invalid calculation metadata found for the assessment service.',
                        ],
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | 10. VALIDATE PAYMENT SCHEDULES
                |--------------------------------------------------------------------------
                */

                foreach ($lockedSchedules as $schedule) {
                    if ($schedule->invoiceItems->isNotEmpty()) {
                        throw ValidationException::withMessages([
                            'payment_schedule_ids' => [
                                "Installment {$schedule->installment_number} has already been invoiced.",
                            ],
                        ]);
                    }

                    $status = $schedule->status;

                    if ($status === null) {
                        throw ValidationException::withMessages([
                            'payment_schedule_ids' => [
                                "Installment {$schedule->installment_number} has no valid payment schedule status.",
                            ],
                        ]);
                    }

                    if (
                        in_array(
                            $status,
                            [
                                PaymentScheduleStatus::PAID,
                                PaymentScheduleStatus::CANCELLED,
                            ],
                            true
                        )
                    ) {
                        throw ValidationException::withMessages([
                            'payment_schedule_ids' => [
                                sprintf(
                                    'Installment %d cannot be invoiced because its status is %s.',
                                    $schedule->installment_number,
                                    $status->value
                                ),
                            ],
                        ]);
                    }

                    $amountDue = (float) ($schedule->amount_due ?? 0);

                    if ($amountDue < 0) {
                        throw ValidationException::withMessages([
                            'payment_schedule_ids' => [
                                "Installment {$schedule->installment_number} has an invalid amount due.",
                            ],
                        ]);
                    }

                    $amountPaid = (float) ($schedule->amount_paid ?? 0);

                    if ($amountPaid < 0) {
                        throw ValidationException::withMessages([
                            'payment_schedule_ids' => [
                                "Installment {$schedule->installment_number} has an invalid paid amount.",
                            ],
                        ]);
                    }

                    if ($amountPaid > $amountDue) {
                        throw ValidationException::withMessages([
                            'payment_schedule_ids' => [
                                "Installment {$schedule->installment_number} has a paid amount greater than its amount due.",
                            ],
                        ]);
                    }

                    $remainingAmount = max(
                        $amountDue - $amountPaid,
                        0
                    );

                    if ($remainingAmount <= 0) {
                        throw ValidationException::withMessages([
                            'payment_schedule_ids' => [
                                "Installment {$schedule->installment_number} has no remaining amount to invoice.",
                            ],
                        ]);
                    }

                    if (!$schedule->due_date) {
                        throw ValidationException::withMessages([
                            'payment_schedule_ids' => [
                                "Installment {$schedule->installment_number} cannot be invoiced because its due date is missing.",
                            ],
                        ]);
                    }
                }

                /*
                |--------------------------------------------------------------------------
                | 11. RESOLVE INVOICE DUE DATE
                |--------------------------------------------------------------------------
                */

                $invoiceDueDate = $lockedSchedules->min('due_date');

                if (!$invoiceDueDate) {
                    throw ValidationException::withMessages([
                        'payment_schedule_ids' => [
                            'The selected payment schedules do not contain a valid due date.',
                        ],
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | 12. GENERATE INVOICE NUMBER
                |--------------------------------------------------------------------------
                */

                $invoiceNumber = $this->documentSequenceService->generate(
                    sequenceType: 'invoice',
                );

                /*
                |--------------------------------------------------------------------------
                | 13. CREATOR
                |--------------------------------------------------------------------------
                */

                $createdBy = Auth::id();

                /*
                |--------------------------------------------------------------------------
                | 14. CREATE DRAFT INVOICE
                |--------------------------------------------------------------------------
                */

                $invoice = Invoice::query()->create([
                    'id' => (string) Str::uuid(),
                    'invoice_number' => $invoiceNumber,
                    'source_type' => 'ASSESSMENT',
                    'assessment_id' => $lockedAssessmentService->assessment_id,
                    'citizen_id' => $lockedAssessmentService->assessment->citizen_id,
                    'administrative_unit_id' => $lockedAssessmentService->assessment->administrative_unit_id,
                    'status' => 'DRAFT',
                    'currency' => $lockedAssessmentService->currency_code ?? 'ETB',
                    'due_date' => $invoiceDueDate,
                    'subtotal' => 0,
                    'discount_amount' => 0,
                    'penalty_amount' => 0,
                    'interest_amount' => 0,
                    'total_amount' => 0,
                    'paid_amount' => 0,
                    'balance_due' => 0,
                    'created_by' => $createdBy,
                    'issued_by' => null,
                    'issued_at' => null,
                ]);

                /*
                |--------------------------------------------------------------------------
                | 15. BUILD ASSESSMENT SNAPSHOT
                |--------------------------------------------------------------------------
                */

                $assessmentMetadata = is_array(
                    $lockedAssessmentService->calculation_metadata
                )
                    ? $lockedAssessmentService->calculation_metadata
                    : [];

                $tariffVersionId =
                    $assessmentMetadata['tariff_version_id'] ?? null;

                $tariffRuleId =
                    $assessmentMetadata['tariff_rule_id'] ?? null;

                $inputSnapshot = $lockedAssessmentService->values
                    ->mapWithKeys(
                        function ($value) {
                            return [
                                $value->field_code => $value->value,
                            ];
                        }
                    )
                    ->toArray();

                /*
                |--------------------------------------------------------------------------
                | 16. CREATE ONE ITEM PER PAYMENT SCHEDULE
                |--------------------------------------------------------------------------
                */

                $lineNumber = 1;

                foreach ($lockedSchedules as $schedule) {
                    $amountDue = (float) ($schedule->amount_due ?? 0);
                    $amountPaid = (float) ($schedule->amount_paid ?? 0);

                    $remainingAmount = max(
                        $amountDue - $amountPaid,
                        0
                    );

                    $serviceName =
                        $lockedAssessmentService->service?->name
                        ?? 'Revenue Service';

                    $description = sprintf(
                        '%s - Installment %d',
                        $serviceName,
                        $schedule->installment_number
                    );

                    $paymentScheduleSnapshot = [
                        'source' => 'PAYMENT_SCHEDULE',
                        'assessment_service_id' =>
                            (string) $lockedAssessmentService->id,
                        'payment_schedule_id' =>
                            (string) $schedule->id,
                        'installment_number' =>
                            (int) $schedule->installment_number,
                        'rule_percentage' =>
                            $schedule->rule_percentage,
                        'due_date' =>
                            $schedule->due_date?->toDateString(),
                        'amount_due' =>
                            $schedule->amount_due,
                        'amount_paid_before_invoice' =>
                            $schedule->amount_paid,
                        'remaining_amount_invoiced' =>
                            number_format(
                                $remainingAmount,
                                4,
                                '.',
                                ''
                            ),
                        'schedule_status' =>
                            $schedule->status?->value,
                        'assessment_calculation' =>
                            $assessmentMetadata,
                    ];

                    InvoiceItem::query()->create([
                        'id' => (string) Str::uuid(),
                        'invoice_id' => $invoice->id,
                        'assessment_service_id' => $lockedAssessmentService->id,
                        'payment_schedule_id' => $schedule->id,
                        'service_id' => $lockedAssessmentService->service_id,
                        'line_number' => $lineNumber,
                        'description' => $description,
                        'quantity' => 1,
                        'unit' => 'installment',
                        'unit_price' => null,
                        'amount' => $remainingAmount,
                        'discount_amount' => 0,
                        'penalty_amount' => 0,
                        'interest_amount' => 0,
                        'total_amount' => $remainingAmount,
                        'currency' => $lockedAssessmentService->currency_code ?? 'ETB',
                        'tariff_version_id' => $tariffVersionId,
                        'tariff_rule_id' => $tariffRuleId,
                        'input_snapshot' => $inputSnapshot,
                        'calculation_snapshot' => $paymentScheduleSnapshot,
                    ]);

                    $lineNumber++;
                }

                /*
                |--------------------------------------------------------------------------
                | 17. AGGREGATE INVOICE TOTALS
                |--------------------------------------------------------------------------
                */

                $totals = InvoiceItem::query()
                    ->where('invoice_id', $invoice->id)
                    ->selectRaw('COALESCE(SUM(amount), 0) as subtotal')
                    ->selectRaw('COALESCE(SUM(discount_amount), 0) as discount_amount')
                    ->selectRaw('COALESCE(SUM(penalty_amount), 0) as penalty_amount')
                    ->selectRaw('COALESCE(SUM(interest_amount), 0) as interest_amount')
                    ->selectRaw('COALESCE(SUM(total_amount), 0) as total_amount')
                    ->first();

                /*
                |--------------------------------------------------------------------------
                | 18. UPDATE FINAL TOTALS
                |--------------------------------------------------------------------------
                */

                $invoice->update([
                    'subtotal' => $totals->subtotal,
                    'discount_amount' => $totals->discount_amount,
                    'penalty_amount' => $totals->penalty_amount,
                    'interest_amount' => $totals->interest_amount,
                    'total_amount' => $totals->total_amount,
                    'paid_amount' => 0,
                    'balance_due' => $totals->total_amount,
                    'status' => 'DRAFT',
                    'issued_by' => null,
                    'issued_at' => null,
                ]);

                /*
                |--------------------------------------------------------------------------
                | 19. LOG
                |--------------------------------------------------------------------------
                */

                Log::info(
                    'Invoice created successfully from payment schedules.',
                    [
                        'invoice_id' => $invoice->id,
                        'invoice_number' => $invoice->invoice_number,
                        'assessment_id' => $lockedAssessmentService->assessment_id,
                        'assessment_service_id' => $lockedAssessmentService->id,
                        'invoice_status' => $invoice->status,
                        'total_amount' => $invoice->total_amount,
                        'selected_payment_schedule_count' => $lockedSchedules->count(),
                        'selected_payment_schedule_ids' => $lockedSchedules
                            ->pluck('id')
                            ->map(
                                static fn ($id) => (string) $id
                            )
                            ->values()
                            ->all(),
                        'created_by' => $createdBy,
                    ]
                );

                /*
                |--------------------------------------------------------------------------
                | 20. RETURN FRESH DRAFT INVOICE
                |--------------------------------------------------------------------------
                */

                return $invoice->fresh([
                    'items',
                    'items.service',
                    'items.paymentSchedule',
                    'assessment',
                    'citizen',
                    'creator',
                    'issuer',
                ]);
            }
        );
    }
}