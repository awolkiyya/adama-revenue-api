<?php

namespace App\Modules\Invoice\Services;

use App\Models\Assessment;
use App\Models\Invoice;
use App\Models\InvoiceItem;
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
        protected InvoiceNumberService $invoiceNumberService,
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
| This is intentionally separate from pagination.
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
| This prevents the list and summary endpoints from
| accidentally returning different results.
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
    | Example:
    |
    | ?search=INV-2018
    | ?search=ET123456
    | ?search=Abebe
    | ?search=ASM-2018-0001
    |
    */

    if (!empty($filters['search'])) {

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

    if (!empty($filters['status'])) {

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

    if (!empty($filters['source_type'])) {

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

    if (!empty($filters['administrative_unit_id'])) {

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

    if (!empty($filters['due_date_from'])) {

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

    if (!empty($filters['due_date_to'])) {

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

    if (!empty($filters['issued_from'])) {

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
    | CREATE FROM APPROVED ASSESSMENT
    |--------------------------------------------------------------------------
    |
    | Responsibility:
    |
    | - Validate approved assessment
    | - Create invoice
    | - Create invoice items
    | - Preserve Decision Provider snapshot
    | - Aggregate invoice totals
    |
    | This service DOES NOT:
    |
    | - calculate tariffs
    | - resolve tariff rules
    | - recalculate amounts
    | - issue invoices
    | - send SMS
    |
    | Issuance and notification are handled by:
    |
    | InvoiceIssuanceService
    |
    |--------------------------------------------------------------------------
    */

    public function createFromAssessment(
        Assessment $assessment
    ): Invoice {

        return DB::transaction(function () use ($assessment) {

            /*
            |--------------------------------------------------------------------------
            | 1. LOCK ASSESSMENT
            |--------------------------------------------------------------------------
            |
            | Prevent concurrent invoice creation against the same assessment.
            |
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
            | 3. PREVENT DUPLICATE INVOICE
            |--------------------------------------------------------------------------
            |
            | One approved assessment should normally produce one invoice.
            |
            */

            $existingInvoice = Invoice::query()
                ->where('assessment_id', $assessment->id)
                ->first();

            if ($existingInvoice) {

                return $existingInvoice->load([
                    'items',
                    'assessment',
                    'citizen',
                ]);
            }


            /*
            |--------------------------------------------------------------------------
            | 4. VALIDATE SERVICES
            |--------------------------------------------------------------------------
            */

            if ($assessment->services->isEmpty()) {

                throw ValidationException::withMessages([
                    'assessment' => [
                        'The approved assessment has no services.',
                    ],
                ]);
            }


            /*
            |--------------------------------------------------------------------------
            | 5. VALIDATE ALL SERVICES
            |--------------------------------------------------------------------------
            */

            foreach ($assessment->services as $assessmentService) {

                /*
                |--------------------------------------------------------------------------
                | SERVICE STATUS
                |--------------------------------------------------------------------------
                */

                if ($assessmentService->status !== 'COMPLETED') {

                    throw ValidationException::withMessages([
                        'assessment' => [
                            'All assessment services must be completed before an invoice can be generated.',
                        ],
                    ]);
                }


                /*
                |--------------------------------------------------------------------------
                | COMPUTED AMOUNT
                |--------------------------------------------------------------------------
                */

                if ($assessmentService->computed_amount === null) {

                    throw ValidationException::withMessages([
                        'assessment' => [
                            'Every assessment service must have a computed amount before invoicing.',
                        ],
                    ]);
                }


                /*
                |--------------------------------------------------------------------------
                | DECISION PROVIDER METADATA
                |--------------------------------------------------------------------------
                */

                if (
                    $assessmentService->calculation_metadata !== null
                    &&
                    !is_array($assessmentService->calculation_metadata)
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

                if (!$assessmentService->service_id) {

                    throw ValidationException::withMessages([
                        'assessment' => [
                            'Every assessment service must reference a revenue service.',
                        ],
                    ]);
                }
            }


            /*
            |--------------------------------------------------------------------------
            | 6. GENERATE INVOICE NUMBER
            |--------------------------------------------------------------------------
            |
            | InvoiceNumberService uses the Ethiopian calendar year and
            | InvoiceSequence for concurrency-safe numbering.
            |
            | IMPORTANT:
            |
            | We are already inside DB::transaction().
            |
            | InvoiceNumberService therefore MUST NOT start another
            | transaction.
            |
            */

            $invoiceNumber =
                $this->invoiceNumberService->generate();


            /*
            |--------------------------------------------------------------------------
            | 7. CREATE INVOICE
            |--------------------------------------------------------------------------
            |
            | The invoice starts as DRAFT.
            |
            | InvoiceIssuanceService is responsible for:
            |
            | DRAFT → ISSUED
            |
            | and taxpayer notification.
            |
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

                'subtotal' =>
                    0,

                'discount_amount' =>
                    0,

                'penalty_amount' =>
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
            | 8. CREATE INVOICE ITEMS
            |--------------------------------------------------------------------------
            */

            $lineNumber = 1;

            foreach ($assessment->services as $assessmentService) {

                /*
                |--------------------------------------------------------------------------
                | AUTHORITATIVE AMOUNT
                |--------------------------------------------------------------------------
                |
                | This amount was already calculated by the Decision Provider.
                |
                | InvoiceService NEVER recalculates it.
                |
                */

                $amount =
                    $assessmentService->computed_amount;


                /*
                |--------------------------------------------------------------------------
                | CALCULATION METADATA
                |--------------------------------------------------------------------------
                */

                $metadata =
                    is_array($assessmentService->calculation_metadata)
                        ? $assessmentService->calculation_metadata
                        : [];


                /*
                |--------------------------------------------------------------------------
                | TARIFF REFERENCES
                |--------------------------------------------------------------------------
                */

                $tariffVersionId =
                    $metadata['tariff_version_id']
                    ?? null;

                $tariffRuleId =
                    $metadata['tariff_rule_id']
                    ?? null;


                /*
                |--------------------------------------------------------------------------
                | INPUT SNAPSHOT
                |--------------------------------------------------------------------------
                |
                | Preserve exactly what was used during assessment.
                |
                */

                $inputSnapshot =
                    $assessmentService->values
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
                | DECISION SNAPSHOT
                |--------------------------------------------------------------------------
                |
                | Preserve the exact Decision Provider metadata.
                |
                */

                $calculationSnapshot =
                    $metadata;


                /*
                |--------------------------------------------------------------------------
                | CREATE INVOICE ITEM
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

                    /*
                    |--------------------------------------------------------------------------
                    | DESCRIPTION SNAPSHOT
                    |--------------------------------------------------------------------------
                    */

                    'description' =>
                        $assessmentService
                            ->service
                            ?->name
                        ?? 'Revenue Service',

                    /*
                    |--------------------------------------------------------------------------
                    | PRESENTATION VALUES
                    |--------------------------------------------------------------------------
                    */

                    'quantity' =>
                        $metadata['quantity']
                        ?? null,

                    'unit' =>
                        $metadata['unit']
                        ?? null,

                    'unit_price' =>
                        $metadata['unit_price']
                        ?? null,

                    /*
                    |--------------------------------------------------------------------------
                    | FINANCIAL VALUES
                    |--------------------------------------------------------------------------
                    */

                    'amount' =>
                        $amount,

                    'discount_amount' =>
                        0,

                    'penalty_amount' =>
                        0,

                    'total_amount' =>
                        $amount,

                    'currency' =>
                        $assessmentService->currency_code
                        ?? 'ETB',

                    /*
                    |--------------------------------------------------------------------------
                    | TARIFF SNAPSHOT REFERENCES
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
                        $calculationSnapshot,
                ]);


                $lineNumber++;
            }


            /*
            |--------------------------------------------------------------------------
            | 9. AGGREGATE INVOICE TOTALS
            |--------------------------------------------------------------------------
            |
            | Accounting aggregation only.
            |
            | No tariff calculation occurs here.
            |
            */

            $totals = InvoiceItem::query()
                ->where('invoice_id', $invoice->id)
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
                    'COALESCE(SUM(total_amount), 0) as total_amount'
                )
                ->first();


            /*
            |--------------------------------------------------------------------------
            | 10. UPDATE FINANCIAL TOTALS
            |--------------------------------------------------------------------------
            */

            $invoice->update([

                'subtotal' =>
                    $totals->subtotal,

                'discount_amount' =>
                    $totals->discount_amount,

                'penalty_amount' =>
                    $totals->penalty_amount,

                'total_amount' =>
                    $totals->total_amount,

                'paid_amount' =>
                    0,

                'balance_due' =>
                    $totals->total_amount,
            ]);


            /*
            |--------------------------------------------------------------------------
            | 11. RETURN COMPLETE INVOICE
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