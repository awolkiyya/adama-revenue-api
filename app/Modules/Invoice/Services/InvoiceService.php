<?php

namespace App\Modules\Invoice\Services;

use App\Models\Assessment;
use App\Models\AssessmentService as AssessmentServiceModel;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\DocumentSequenceService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InvoiceService
{
    /*
    |--------------------------------------------------------------------------
    | CONSTRUCTOR
    |--------------------------------------------------------------------------
    */

    public function __construct(
        protected DocumentSequenceService $documentSequenceService,
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | LIST INVOICES
    |--------------------------------------------------------------------------
    |
    | Returns a paginated list of invoices.
    |
    | Search:
    |
    | - invoice number
    | - citizen number
    | - citizen name
    | - assessment number
    |
    | Filters:
    |
    | - fiscal year
    | - status
    | - source type
    | - administrative unit
    | - due date range
    | - issued date range
    |
    |--------------------------------------------------------------------------
    */

    public function paginate(
        array $filters = [],
        int $perPage = 20
    ) {
        $perPage = max(
            1,
            min($perPage, 100)
        );

        $query = Invoice::query()
            ->with([
                'citizen',
                'assessment',
                'items.service',
                'administrativeUnit',
                'creator',
                'issuer',
            ]);

        /*
        |--------------------------------------------------------------------------
        | APPLY FILTERS
        |--------------------------------------------------------------------------
        */

        $this->applyListFilters(
            $query,
            $filters
        );

        /*
        |--------------------------------------------------------------------------
        | SORTING
        |--------------------------------------------------------------------------
        |
        | Newest invoices first.
        |
        */

        $query
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        /*
        |--------------------------------------------------------------------------
        | PAGINATION
        |--------------------------------------------------------------------------
        */

        return $query->paginate(
            $perPage
        );
    }

    /*
    |--------------------------------------------------------------------------
    | INVOICE SUMMARY
    |--------------------------------------------------------------------------
    |
    | Returns aggregate invoice statistics using exactly the same
    | filters as the invoice listing.
    |
    |--------------------------------------------------------------------------
    */

    public function summary(
        array $filters = []
    ): array {
        $query = Invoice::query();

        /*
        |--------------------------------------------------------------------------
        | APPLY SAME FILTERS AS LIST
        |--------------------------------------------------------------------------
        */

        $this->applyListFilters(
            $query,
            $filters
        );

        /*
        |--------------------------------------------------------------------------
        | FINANCIAL AGGREGATES
        |--------------------------------------------------------------------------
        */

        $financial = (clone $query)
            ->selectRaw(
                'COUNT(*) as total_invoices'
            )
            ->selectRaw(
                'COALESCE(SUM(subtotal), 0) as subtotal'
            )
            ->selectRaw(
                'COALESCE(SUM(discount_amount), 0) as discount_amount'
            )
            ->selectRaw(
                'COALESCE(SUM(penalty_amount), 0) as penalty_amount'
            )
            ->selectRaw(
                'COALESCE(SUM(total_amount), 0) as total_amount'
            )
            ->selectRaw(
                'COALESCE(SUM(paid_amount), 0) as paid_amount'
            )
            ->selectRaw(
                'COALESCE(SUM(balance_due), 0) as balance_due'
            )
            ->first();

        /*
        |--------------------------------------------------------------------------
        | STATUS COUNTS
        |--------------------------------------------------------------------------
        */

        $statusCounts = (clone $query)
            ->selectRaw(
                'status, COUNT(*) as count'
            )
            ->groupBy('status')
            ->pluck(
                'count',
                'status'
            );

        /*
        |--------------------------------------------------------------------------
        | RETURN SUMMARY
        |--------------------------------------------------------------------------
        */

        return [
            'total_invoices' =>
                (int) $financial->total_invoices,

            'subtotal' =>
                (float) $financial->subtotal,

            'discount_amount' =>
                (float) $financial->discount_amount,

            'penalty_amount' =>
                (float) $financial->penalty_amount,

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
    |
    | Centralized filter logic shared by:
    |
    | - paginate()
    | - summary()
    |
    |--------------------------------------------------------------------------
    */

    protected function applyListFilters(
        $query,
        array $filters
    ): void {
        /*
        |--------------------------------------------------------------------------
        | GENERAL SEARCH
        |--------------------------------------------------------------------------
        |
        | Search across business-facing identifiers:
        |
        | 1. Invoice number
        | 2. Citizen number
        | 3. Citizen name
        | 4. Assessment number
        |
        */

        if (! empty($filters['search'])) {
            $search = trim(
                $filters['search']
            );

            $query->where(function ($q) use ($search) {
                /*
                |--------------------------------------------------------------------------
                | INVOICE NUMBER
                |--------------------------------------------------------------------------
                */

                $q->where(
                    'invoice_number',
                    'like',
                    "%{$search}%"
                );

                /*
                |--------------------------------------------------------------------------
                | CITIZEN NUMBER / NAME
                |--------------------------------------------------------------------------
                */

                $q->orWhereHas(
                    'citizen',
                    function ($citizenQuery) use ($search) {
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

                /*
                |--------------------------------------------------------------------------
                | ASSESSMENT NUMBER
                |--------------------------------------------------------------------------
                */

                $q->orWhereHas(
                    'assessment',
                    function ($assessmentQuery) use ($search) {
                        $assessmentQuery->where(
                            'assessment_number',
                            'like',
                            "%{$search}%"
                        );
                    }
                );
            });
        }

        /*
        |--------------------------------------------------------------------------
        | FISCAL YEAR
        |--------------------------------------------------------------------------
        */

        if (
            isset($filters['fiscal_year'])
            &&
            $filters['fiscal_year'] !== ''
        ) {
            $query->where(
                'fiscal_year',
                (int) $filters['fiscal_year']
            );
        }

        /*
        |--------------------------------------------------------------------------
        | STATUS
        |--------------------------------------------------------------------------
        */

        if (! empty($filters['status'])) {
            if (is_array($filters['status'])) {
                $query->whereIn(
                    'status',
                    $filters['status']
                );
            } else {
                $query->where(
                    'status',
                    $filters['status']
                );
            }
        }

        /*
        |--------------------------------------------------------------------------
        | SOURCE TYPE
        |--------------------------------------------------------------------------
        */

        if (! empty($filters['source_type'])) {
            $query->where(
                'source_type',
                $filters['source_type']
            );
        }

        /*
        |--------------------------------------------------------------------------
        | ADMINISTRATIVE UNIT
        |--------------------------------------------------------------------------
        */

        if (! empty($filters['administrative_unit_id'])) {
            $query->where(
                'administrative_unit_id',
                $filters['administrative_unit_id']
            );
        }

        /*
        |--------------------------------------------------------------------------
        | DUE DATE FROM
        |--------------------------------------------------------------------------
        */

        if (! empty($filters['due_date_from'])) {
            $query->whereDate(
                'due_date',
                '>=',
                $filters['due_date_from']
            );
        }

        /*
        |--------------------------------------------------------------------------
        | DUE DATE TO
        |--------------------------------------------------------------------------
        */

        if (! empty($filters['due_date_to'])) {
            $query->whereDate(
                'due_date',
                '<=',
                $filters['due_date_to']
            );
        }

        /*
        |--------------------------------------------------------------------------
        | ISSUED FROM
        |--------------------------------------------------------------------------
        */

        if (! empty($filters['issued_from'])) {
            $query->whereDate(
                'issued_at',
                '>=',
                $filters['issued_from']
            );
        }

        /*
        |--------------------------------------------------------------------------
        | ISSUED TO
        |--------------------------------------------------------------------------
        */

        if (! empty($filters['issued_to'])) {
            $query->whereDate(
                'issued_at',
                '<=',
                $filters['issued_to']
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | CREATE FROM APPROVED ASSESSMENT SERVICES
    |--------------------------------------------------------------------------
    |
    | Responsibility:
    |
    | - Validate approved assessment
    | - Validate supplied invoiceable assessment services
    | - Create one invoice
    | - Create invoice items for supplied services only
    | - Preserve Decision Provider snapshot
    | - Preserve authoritative due date
    | - Aggregate invoice totals
    | - Generate the invoice document number
    |
    | This service DOES NOT:
    |
    | - determine whether a service is scheduled
    | - resolve payment schedule rules
    | - calculate tariffs
    | - resolve tariff rules
    | - calculate penalties
    | - calculate interest
    | - recalculate assessment amounts
    | - issue invoices
    | - send SMS
    |
    | The caller must provide only services that have already been
    | classified as immediately invoiceable.
    |
    | Scheduled services must never be passed to this method.
    |
    |--------------------------------------------------------------------------
    */

    public function createFromAssessmentServices(
        Assessment $assessment,
        Collection $invoiceableServices,
    ): Invoice {
        return DB::transaction(function () use (
            $assessment,
            $invoiceableServices
        ) {
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
                ->findOrFail(
                    $assessment->id
                );

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
            | 3. VALIDATE INVOICEABLE SERVICES
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
            | 4. VALIDATE SERVICE OWNERSHIP
            |--------------------------------------------------------------------------
            */

            $loadedServices = $assessment->services
                ->keyBy(
                    fn (
                        AssessmentServiceModel $service
                    ) => (string) $service->id
                );

            foreach (
                $invoiceableServices
                as $assessmentService
            ) {
                if (
                    ! $assessmentService
                    instanceof AssessmentServiceModel
                ) {
                    throw ValidationException::withMessages([
                        'assessment' => [
                            'Invalid assessment service supplied for invoicing.',
                        ],
                    ]);
                }

                $serviceId =
                    (string) $assessmentService->id;

                if (
                    ! $loadedServices->has(
                        $serviceId
                    )
                ) {
                    throw ValidationException::withMessages([
                        'assessment' => [
                            'One or more invoiceable services do not belong to this assessment.',
                        ],
                    ]);
                }
            }

            /*
            |--------------------------------------------------------------------------
            | 5. USE LOCKED / FRESH SERVICES
            |--------------------------------------------------------------------------
            |
            | Never trust potentially stale AssessmentService instances
            | supplied by the caller.
            |
            */

            $invoiceableServices =
                $invoiceableServices
                    ->map(
                        fn (
                            AssessmentServiceModel $service
                        ) =>
                            $loadedServices->get(
                                (string) $service->id
                            )
                    )
                    ->filter()
                    ->values();

            /*
            |--------------------------------------------------------------------------
            | 6. VALIDATE SERVICES
            |--------------------------------------------------------------------------
            */

            $assessmentDueDate = null;

            foreach (
                $invoiceableServices
                as $assessmentService
            ) {
                /*
                |--------------------------------------------------------------------------
                | SERVICE STATUS
                |--------------------------------------------------------------------------
                */

                if (
                    $assessmentService->status
                    !== 'COMPLETED'
                ) {
                    throw ValidationException::withMessages([
                        'assessment' => [
                            'All invoiceable assessment services must be completed before an invoice can be generated.',
                        ],
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | COMPUTED AMOUNT
                |--------------------------------------------------------------------------
                */

                if (
                    $assessmentService->computed_amount
                    === null
                ) {
                    throw ValidationException::withMessages([
                        'assessment' => [
                            'Every invoiceable assessment service must have a computed amount before invoicing.',
                        ],
                    ]);
                }

                if (
                    (float) $assessmentService->computed_amount
                    < 0
                ) {
                    throw ValidationException::withMessages([
                        'assessment' => [
                            'Every invoiceable assessment service must have a non-negative computed amount before invoicing.',
                        ],
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | DECISION PROVIDER METADATA
                |--------------------------------------------------------------------------
                */

                if (
                    $assessmentService->calculation_metadata
                    !== null
                    &&
                    ! is_array(
                        $assessmentService->calculation_metadata
                    )
                ) {
                    throw ValidationException::withMessages([
                        'assessment' => [
                            'Invalid calculation metadata found for an assessment service.',
                        ],
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | REVENUE SERVICE
                |--------------------------------------------------------------------------
                */

                if (
                    ! $assessmentService->service_id
                ) {
                    throw ValidationException::withMessages([
                        'assessment' => [
                            'Every invoiceable assessment service must reference a revenue service.',
                        ],
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | DUE DATE
                |--------------------------------------------------------------------------
                */

                if (
                    $assessmentService->due_date
                    === null
                ) {
                    throw ValidationException::withMessages([
                        'assessment' => [
                            'Every invoiceable assessment service must have a due date before an invoice can be generated.',
                        ],
                    ]);
                }

                if (
                    $assessmentDueDate === null
                ) {
                    $assessmentDueDate =
                        $assessmentService->due_date;
                } elseif (
                    $assessmentDueDate->format('Y-m-d')
                    !==
                    $assessmentService
                        ->due_date
                        ->format('Y-m-d')
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
            | 7. PREVENT DUPLICATE INVOICING
            |--------------------------------------------------------------------------
            |
            | We intentionally do NOT check:
            |
            |     where('assessment_id', $assessment->id)
            |
            | because one assessment can legitimately have multiple
            | invoices during its lifecycle.
            |
            | Duplicate prevention is performed at the
            | AssessmentService level through invoice_items.
            |
            |--------------------------------------------------------------------------
            */

            $alreadyInvoicedService =
                InvoiceItem::query()
                    ->whereIn(
                        'assessment_service_id',
                        $invoiceableServices->pluck('id')
                    )
                    ->whereHas(
                        'invoice',
                        function ($query) {
                            $query->whereIn(
                                'status',
                                [
                                    'DRAFT',
                                    'ISSUED',
                                    'PARTIAL',
                                    'PAID',
                                    'OVERDUE',
                                ]
                            );
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
            | 8. GENERATE INVOICE NUMBER
            |--------------------------------------------------------------------------
            |
            | Centralized document numbering.
            |
            | Example:
            |
            | INV-2019-000001
            |
            | The sequence is independently maintained by:
            |
            | invoice + Ethiopian year
            |
            | DocumentSequenceService is responsible for:
            |
            | - calendar year resolution
            | - sequence initialization
            | - concurrency locking
            | - incrementing the sequence
            | - formatting the document number
            |
            |--------------------------------------------------------------------------
            */

            $invoiceNumber =
                $this->documentSequenceService->generate(
                    sequenceType: 'invoice',
                    prefix: 'INV',
                );

            /*
            |--------------------------------------------------------------------------
            | 9. CREATE INVOICE
            |--------------------------------------------------------------------------
            */

            $invoice = Invoice::query()->create([
                'id' =>
                    (string) Str::uuid(),

                'invoice_number' =>
                    $invoiceNumber,

                'source_type' =>
                    'ASSESSMENT',

                'assessment_id' =>
                    $assessment->id,

                'citizen_id' =>
                    $assessment->citizen_id,

                'administrative_unit_id' =>
                    $assessment->administrative_unit_id,

                'status' =>
                    'DRAFT',

                'currency' =>
                    'ETB',

                'due_date' =>
                    $assessmentDueDate,

                'subtotal' =>
                    0,

                'discount_amount' =>
                    0,

                'penalty_amount' =>
                    0,

                'interest_amount' =>
                    0,

                'total_amount' =>
                    0,

                'paid_amount' =>
                    0,

                'balance_due' =>
                    0,

                'created_by' =>
                    Auth::id(),
            ]);

            /*
            |--------------------------------------------------------------------------
            | 10. CREATE INVOICE ITEMS
            |--------------------------------------------------------------------------
            */

            $lineNumber = 1;

            foreach (
                $invoiceableServices
                as $assessmentService
            ) {
                /*
                |--------------------------------------------------------------------------
                | AUTHORITATIVE AMOUNT
                |--------------------------------------------------------------------------
                |
                | This amount comes from the completed AssessmentService.
                |
                | No tariff calculation is performed here.
                |
                */

                $amount =
                    $assessmentService->computed_amount;

                /*
                |--------------------------------------------------------------------------
                | DECISION PROVIDER METADATA
                |--------------------------------------------------------------------------
                */

                $metadata =
                    is_array(
                        $assessmentService
                            ->calculation_metadata
                    )
                        ? $assessmentService
                            ->calculation_metadata
                        : [];

                /*
                |--------------------------------------------------------------------------
                | TARIFF SNAPSHOT REFERENCES
                |--------------------------------------------------------------------------
                */

                $tariffVersionId =
                    $metadata[
                        'tariff_version_id'
                    ]
                    ?? null;

                $tariffRuleId =
                    $metadata[
                        'tariff_rule_id'
                    ]
                    ?? null;

                /*
                |--------------------------------------------------------------------------
                | INPUT SNAPSHOT
                |--------------------------------------------------------------------------
                |
                | Preserve the exact input values used during
                | assessment calculation.
                |
                */

                $inputSnapshot =
                    $assessmentService
                        ->values
                        ->mapWithKeys(
                            function ($value) {
                                return [
                                    $value->field_code =>
                                        $value->value,
                                ];
                            }
                        )
                        ->toArray();

                /*
                |--------------------------------------------------------------------------
                | CREATE ITEM
                |--------------------------------------------------------------------------
                */

                InvoiceItem::query()->create([
                    'id' =>
                        (string) Str::uuid(),

                    'invoice_id' =>
                        $invoice->id,

                    'assessment_service_id' =>
                        $assessmentService->id,

                    'service_id' =>
                        $assessmentService->service_id,

                    'line_number' =>
                        $lineNumber,

                    'description' =>
                        $assessmentService
                            ->service
                            ?->name
                        ?? 'Revenue Service',

                    'quantity' =>
                        $metadata[
                            'quantity'
                        ]
                        ?? null,

                    'unit' =>
                        $metadata[
                            'unit'
                        ]
                        ?? null,

                    'unit_price' =>
                        $metadata[
                            'unit_price'
                        ]
                        ?? null,

                    /*
                    |--------------------------------------------------------------------------
                    | AUTHORITATIVE ASSESSMENT AMOUNT
                    |--------------------------------------------------------------------------
                    */

                    'amount' =>
                        $amount,

                    'discount_amount' =>
                        0,

                    'penalty_amount' =>
                        0,

                    'interest_amount' =>
                        0,

                    'total_amount' =>
                        $amount,

                    'currency' =>
                        $assessmentService
                            ->currency_code
                        ?? 'ETB',

                    /*
                    |--------------------------------------------------------------------------
                    | TARIFF SNAPSHOT
                    |--------------------------------------------------------------------------
                    */

                    'tariff_version_id' =>
                        $tariffVersionId,

                    'tariff_rule_id' =>
                        $tariffRuleId,

                    /*
                    |--------------------------------------------------------------------------
                    | INPUT SNAPSHOT
                    |--------------------------------------------------------------------------
                    */

                    'input_snapshot' =>
                        $inputSnapshot,

                    /*
                    |--------------------------------------------------------------------------
                    | DECISION PROVIDER SNAPSHOT
                    |--------------------------------------------------------------------------
                    */

                    'calculation_snapshot' =>
                        $metadata,
                ]);

                $lineNumber++;
            }

            /*
            |--------------------------------------------------------------------------
            | 11. AGGREGATE TOTALS
            |--------------------------------------------------------------------------
            */

            $totals =
                InvoiceItem::query()
                    ->where(
                        'invoice_id',
                        $invoice->id
                    )
                    ->selectRaw(
                        'COALESCE(SUM(amount), 0) as subtotal'
                    )
                    ->selectRaw(
                        'COALESCE(SUM(discount_amount), 0) as discount_amount'
                    )
                    ->selectRaw(
                        'COALESCE(SUM(penalty_amount), 0) as penalty_amount'
                    )
                    ->selectRaw(
                        'COALESCE(SUM(interest_amount), 0) as interest_amount'
                    )
                    ->selectRaw(
                        'COALESCE(SUM(total_amount), 0) as total_amount'
                    )
                    ->first();

            /*
            |--------------------------------------------------------------------------
            | 12. UPDATE INVOICE TOTALS
            |--------------------------------------------------------------------------
            */

            $invoice->update([
                'subtotal' =>
                    $totals->subtotal,

                'discount_amount' =>
                    $totals->discount_amount,

                'penalty_amount' =>
                    $totals->penalty_amount,

                'interest_amount' =>
                    $totals->interest_amount,

                'total_amount' =>
                    $totals->total_amount,

                'paid_amount' =>
                    0,

                'balance_due' =>
                    $totals->total_amount,
            ]);

            /*
            |--------------------------------------------------------------------------
            | 13. RETURN FRESH INVOICE
            |--------------------------------------------------------------------------
            */

            return $invoice->fresh([
                'items',
                'assessment',
                'citizen',
            ]);
        });
    }
}