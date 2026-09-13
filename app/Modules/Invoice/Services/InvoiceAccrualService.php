<?php

namespace App\Modules\Invoice\Services;

use  App\Services\Calculations\InterestCalculator;
use App\Services\Calculations\PenaltyCalculator;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceAccrualService
{
    public function __construct(
        protected PenaltyCalculator $penaltyCalculator,
        protected InterestCalculator $interestCalculator,
    ) {
    }

    /**
     * ================================================================
     * ACCRUE CURRENT PENALTY AND INTEREST
     * ================================================================
     *
     * Recalculate the current penalty and interest for an issued
     * invoice and persist the resulting financial state.
     *
     * Responsibilities:
     *
     * - Lock the invoice
     * - Validate invoice state
     * - Determine the calculation date
     * - Calculate penalty per invoice item
     * - Calculate interest per invoice item
     * - Update invoice item totals
     * - Aggregate invoice totals
     * - Recalculate balance_due
     * - Update invoice status
     *
     * This service DOES NOT:
     *
     * - recalculate the original assessment principal
     * - recalculate tariffs
     * - modify assessment_services.computed_amount
     * - change invoice.due_date
     * - issue invoices
     * - send SMS
     * - record payments
     *
     * IMPORTANT:
     *
     * Accrual is idempotent.
     *
     * The service replaces the current penalty/interest values with
     * the amounts applicable as of $asOfDate instead of blindly adding
     * another amount every time the scheduler runs.
     *
     * Example:
     *
     * Day 1:
     *     penalty = 100
     *     interest = 20
     *
     * Day 2:
     *     penalty = 100
     *     interest = 25
     *
     * The second execution updates interest from 20 to 25.
     *
     * It does NOT create:
     *
     *     20 + 25 = 45
     *
     * ================================================================
     */
    public function accrue(
        Invoice|string $invoice,
        CarbonInterface|string|null $asOfDate = null
    ): Invoice {
        $invoiceId = $invoice instanceof Invoice
            ? $invoice->getKey()
            : $invoice;

        if (! is_string($invoiceId) || trim($invoiceId) === '') {
            throw ValidationException::withMessages([
                'invoice' => [
                    'A valid invoice ID is required.',
                ],
            ]);
        }

        $asOfDate = $this->normalizeDate($asOfDate);

        return DB::transaction(
            function () use ($invoiceId, $asOfDate): Invoice {

                /*
                |--------------------------------------------------------------------------
                | 1. LOCK INVOICE
                |--------------------------------------------------------------------------
                |
                | Prevent concurrent accrual processes from modifying the
                | same invoice at the same time.
                |
                */

                $invoice = Invoice::query()
                    ->with([
                        'items.assessmentService.penaltyRule',
                        'items.assessmentService.interestRule',
                        'assessment',
                        'citizen',
                    ])
                    ->lockForUpdate()
                    ->findOrFail($invoiceId);


                /*
                |--------------------------------------------------------------------------
                | 2. VALIDATE INVOICE STATUS
                |--------------------------------------------------------------------------
                |
                | Accrual only applies to active issued receivables.
                |
                | DRAFT invoices must not accrue statutory charges.
                |
                | CANCELLED / VOID invoices must not accrue charges.
                |
                */

                if (in_array(
                    $invoice->status,
                    [
                        'DRAFT',
                        'CANCELLED',
                        'VOID',
                    ],
                    true
                )) {
                    throw ValidationException::withMessages([
                        'invoice' => [
                            'Penalty and interest cannot be accrued for this invoice status.',
                        ],
                    ]);
                }


                /*
                |--------------------------------------------------------------------------
                | 3. VALIDATE INVOICE ITEMS
                |--------------------------------------------------------------------------
                */

                if ($invoice->items->isEmpty()) {
                    throw ValidationException::withMessages([
                        'invoice' => [
                            'An invoice must contain at least one item before accrual can be calculated.',
                        ],
                    ]);
                }


                /*
                |--------------------------------------------------------------------------
                | 4. PROCESS EACH INVOICE ITEM
                |--------------------------------------------------------------------------
                */

                foreach ($invoice->items as $invoiceItem) {

                    $this->accrueItem(
                        $invoiceItem,
                        $asOfDate
                    );
                }


                /*
                |--------------------------------------------------------------------------
                | 5. AGGREGATE INVOICE TOTALS
                |--------------------------------------------------------------------------
                |
                | Invoice totals are always derived from invoice items.
                |
                | This prevents invoice-level totals from drifting away
                | from their underlying financial lines.
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
                        'COALESCE(SUM(interest_amount), 0) as interest_amount'
                    )

                    ->selectRaw(
                        'COALESCE(SUM(total_amount), 0) as total_amount'
                    )

                    ->first();


                /*
                |--------------------------------------------------------------------------
                | 6. RESOLVE PAID AMOUNT
                |--------------------------------------------------------------------------
                |
                | Payment allocation should be the authoritative source
                | for paid_amount.
                |
                | This service does not invent or modify payment records.
                |
                | Until the payment allocation service is integrated,
                | the existing invoice paid_amount is preserved.
                |
                */

                $paidAmount = max(
                    0.0,
                    (float) ($invoice->paid_amount ?? 0)
                );


                /*
                |--------------------------------------------------------------------------
                | 7. CALCULATE CURRENT BALANCE
                |--------------------------------------------------------------------------
                */

                $totalAmount = max(
                    0.0,
                    (float) $totals->total_amount
                );

                $balanceDue = max(
                    0.0,
                    $totalAmount - $paidAmount
                );


                /*
                |--------------------------------------------------------------------------
                | 8. DETERMINE INVOICE STATUS
                |--------------------------------------------------------------------------
                |
                | Status reflects the current financial position.
                |
                | ISSUED:
                |     Nothing has been paid.
                |
                | PARTIALLY_PAID:
                |     Some amount has been paid but a balance remains.
                |
                | PAID:
                |     Entire current invoice amount has been paid.
                |
                | OVERDUE:
                |     Balance remains and the invoice due date has passed.
                |
                */

                $status = $this->resolveStatus(
                    invoice: $invoice,
                    paidAmount: $paidAmount,
                    balanceDue: $balanceDue,
                    asOfDate: $asOfDate,
                );


                /*
                |--------------------------------------------------------------------------
                | 9. UPDATE INVOICE
                |--------------------------------------------------------------------------
                */

                $invoice->update([
                    'subtotal' =>
                        $this->roundMoney(
                            (float) $totals->subtotal
                        ),

                    'discount_amount' =>
                        $this->roundMoney(
                            (float) $totals->discount_amount
                        ),

                    'penalty_amount' =>
                        $this->roundMoney(
                            (float) $totals->penalty_amount
                        ),

                    'interest_amount' =>
                        $this->roundMoney(
                            (float) $totals->interest_amount
                        ),

                    'total_amount' =>
                        $this->roundMoney(
                            $totalAmount
                        ),

                    'paid_amount' =>
                        $this->roundMoney(
                            $paidAmount
                        ),

                    'balance_due' =>
                        $this->roundMoney(
                            $balanceDue
                        ),

                    'status' =>
                        $status,

                    'paid_at' =>
                        $status === 'PAID'
                            ? ($invoice->paid_at ?? now())
                            : null,
                ]);


                /*
                |--------------------------------------------------------------------------
                | 10. RETURN FRESH INVOICE
                |--------------------------------------------------------------------------
                */

                return $invoice->fresh([
                    'items',
                    'assessment',
                    'citizen',
                ]);
            }
        );
    }


    /**
     * ================================================================
     * ACCRUE SINGLE INVOICE ITEM
     * ================================================================
     *
     * Calculates and persists the current penalty and interest for
     * one invoice item.
     *
     * The invoice item must originate from an assessment service when
     * statutory penalty / interest rules are required.
     */
    protected function accrueItem(
        InvoiceItem $invoiceItem,
        CarbonInterface $asOfDate
    ): void {

        /*
        |--------------------------------------------------------------------------
        | DIRECT COLLECTION
        |--------------------------------------------------------------------------
        |
        | Direct collection items may not have an assessment service,
        | therefore there may be no assessment-level penalty or
        | interest rules available.
        |
        | In that case:
        |
        | penalty  = 0
        | interest = 0
        |
        */

        $assessmentService =
            $invoiceItem->assessmentService;


        if (! $assessmentService) {

            $this->updateItemAmounts(
                invoiceItem: $invoiceItem,
                penalty: 0.0,
                interest: 0.0,
            );

            return;
        }


        /*
        |--------------------------------------------------------------------------
        | PRINCIPAL
        |--------------------------------------------------------------------------
        |
        | The invoice item amount is the authoritative invoiced principal.
        |
        | We do NOT replace it with a newly calculated assessment amount.
        |
        */

        $principal = max(
            0.0,
            (float) $invoiceItem->amount
        );


        /*
        |--------------------------------------------------------------------------
        | IF NO PRINCIPAL EXISTS
        |--------------------------------------------------------------------------
        |
        | There is nothing against which penalty or interest should
        | currently accrue.
        |
        */

        if ($principal <= 0) {

            $this->updateItemAmounts(
                invoiceItem: $invoiceItem,
                penalty: 0.0,
                interest: 0.0,
            );

            return;
        }


        /*
        |--------------------------------------------------------------------------
        | DUE DATE
        |--------------------------------------------------------------------------
        |
        | No due date means no overdue accrual.
        |
        */

        if (! $assessmentService->due_date) {

            $this->updateItemAmounts(
                invoiceItem: $invoiceItem,
                penalty: 0.0,
                interest: 0.0,
            );

            return;
        }


        $dueDate = Carbon::parse(
            $assessmentService->due_date
        )->startOfDay();


        /*
        |--------------------------------------------------------------------------
        | NOT YET OVERDUE
        |--------------------------------------------------------------------------
        |
        | If the due date has not passed, penalty and interest remain zero.
        |
        */

        if (! $asOfDate->gt($dueDate)) {

            $this->updateItemAmounts(
                invoiceItem: $invoiceItem,
                penalty: 0.0,
                interest: 0.0,
            );

            return;
        }


        /*
        |--------------------------------------------------------------------------
        | CALCULATE PENALTY
        |--------------------------------------------------------------------------
        */

        $penalty = $this->penaltyCalculator->calculate(
            $assessmentService,
            $asOfDate
        );


        /*
        |--------------------------------------------------------------------------
        | CALCULATE INTEREST
        |--------------------------------------------------------------------------
        */

        $interest = $this->interestCalculator->calculate(
            $assessmentService,
            $asOfDate
        );


        /*
        |--------------------------------------------------------------------------
        | PROTECT AGAINST INVALID CALCULATOR RESULTS
        |--------------------------------------------------------------------------
        */

        $penalty = max(
            0.0,
            (float) $penalty
        );

        $interest = max(
            0.0,
            (float) $interest
        );


        /*
        |--------------------------------------------------------------------------
        | UPDATE ITEM
        |--------------------------------------------------------------------------
        */

        $this->updateItemAmounts(
            invoiceItem: $invoiceItem,
            penalty: $penalty,
            interest: $interest,
        );
    }


    /**
     * ================================================================
     * UPDATE INVOICE ITEM FINANCIAL VALUES
     * ================================================================
     *
     * Formula:
     *
     * total_amount =
     *     amount
     *     - discount_amount
     *     + penalty_amount
     *     + interest_amount
     */
    protected function updateItemAmounts(
        InvoiceItem $invoiceItem,
        float $penalty,
        float $interest
    ): void {

        $amount = max(
            0.0,
            (float) $invoiceItem->amount
        );

        $discount = max(
            0.0,
            (float) ($invoiceItem->discount_amount ?? 0)
        );

        $penalty = max(
            0.0,
            $penalty
        );

        $interest = max(
            0.0,
            $interest
        );

        $total = max(
            0.0,
            $amount
            - $discount
            + $penalty
            + $interest
        );


        $invoiceItem->update([
            'penalty_amount' =>
                $this->roundMoney(
                    $penalty
                ),

            'interest_amount' =>
                $this->roundMoney(
                    $interest
                ),

            'total_amount' =>
                $this->roundMoney(
                    $total
                ),
        ]);
    }


    /**
     * ================================================================
     * RESOLVE INVOICE STATUS
     * ================================================================
     */
    protected function resolveStatus(
        Invoice $invoice,
        float $paidAmount,
        float $balanceDue,
        CarbonInterface $asOfDate
    ): string {

        /*
        |--------------------------------------------------------------------------
        | FULLY PAID
        |--------------------------------------------------------------------------
        */

        if ($balanceDue <= 0) {
            return 'PAID';
        }


        /*
        |--------------------------------------------------------------------------
        | PARTIALLY PAID
        |--------------------------------------------------------------------------
        */

        if ($paidAmount > 0) {
            return 'PARTIALLY_PAID';
        }


        /*
        |--------------------------------------------------------------------------
        | OVERDUE
        |--------------------------------------------------------------------------
        |
        | Due date remains immutable.
        |
        | The invoice becomes overdue when:
        |
        |     due_date < asOfDate
        |     AND
        |     balance_due > 0
        |
        */

        if (
            $invoice->due_date
            &&
            $asOfDate->gt(
                Carbon::parse(
                    $invoice->due_date
                )->startOfDay()
            )
        ) {
            return 'OVERDUE';
        }


        /*
        |--------------------------------------------------------------------------
        | STILL ISSUED
        |--------------------------------------------------------------------------
        */

        return 'ISSUED';
    }


    /**
     * ================================================================
     * NORMALIZE DATE
     * ================================================================
     */
    protected function normalizeDate(
        CarbonInterface|string|null $date
    ): CarbonInterface {

        if ($date instanceof CarbonInterface) {
            return $date->copy()->startOfDay();
        }

        return $date
            ? Carbon::parse($date)->startOfDay()
            : now()->startOfDay();
    }


    /**
     * ================================================================
     * ROUND MONEY
     * ================================================================
     */
    protected function roundMoney(
        float $amount
    ): float {
        return round(
            $amount,
            2
        );
    }
}