<?php

namespace App\Modules\Invoice\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\Calculations\InterestCalculator;
use App\Services\Calculations\PenaltyCalculator;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class InvoiceAccrualService
{
    /**
     * Invoice statuses that are not eligible for accrual.
     */
    private const NON_ACCRUABLE_STATUSES = [
        'DRAFT',
        'CANCELLED',
        'VOID',
    ];

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
     * This method is intentionally idempotent.
     *
     * Running it multiple times for the same invoice and as-of date
     * recalculates penalty and interest instead of adding them again.
     */
    public function accrue(
        Invoice|string $invoice,
        CarbonInterface|string|null $asOfDate = null
    ): Invoice {
        $invoiceId = $invoice instanceof Invoice
            ? $invoice->getKey()
            : $invoice;

        Log::info('Invoice accrual request started.', [
            'invoice_id' => $invoiceId,
            'as_of_date_input' => $asOfDate instanceof CarbonInterface
                ? $asOfDate->toDateTimeString()
                : $asOfDate,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Validate invoice ID
        |--------------------------------------------------------------------------
        */

        if (! is_string($invoiceId) || trim($invoiceId) === '') {
            Log::error('Invoice accrual rejected: invalid invoice ID.', [
                'invoice_id' => $invoiceId,
            ]);

            throw ValidationException::withMessages([
                'invoice' => [
                    'A valid invoice ID is required.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Normalize calculation date
        |--------------------------------------------------------------------------
        */

        $asOfDate = $this->normalizeDate($asOfDate);

        Log::info('Invoice accrual date normalized.', [
            'invoice_id' => $invoiceId,
            'as_of_date' => $asOfDate->toDateString(),
        ]);

        try {
            return DB::transaction(
                function () use ($invoiceId, $asOfDate): Invoice {

                    /*
                    |--------------------------------------------------------------------------
                    | 1. LOAD AND LOCK INVOICE
                    |--------------------------------------------------------------------------
                    */

                    Log::info('Loading invoice for accrual.', [
                        'invoice_id' => $invoiceId,
                        'as_of_date' => $asOfDate->toDateString(),
                    ]);

                    $invoice = Invoice::query()
                        ->with([
                            'items.assessmentService.penaltyRule',
                            'items.assessmentService.interestRule',
                            'assessment',
                            'citizen',
                        ])
                        ->lockForUpdate()
                        ->findOrFail($invoiceId);

                    Log::info(
                        'Invoice loaded and locked for accrual.',
                        [
                            'invoice_id' => $invoice->id,
                            'status' => $invoice->status,
                            'due_date' => $invoice->due_date?->toDateString(),
                            'items_count' => $invoice->items->count(),
                            'paid_amount' => $invoice->paid_amount,
                            'current_total_amount' => $invoice->total_amount,
                            'current_balance_due' => $invoice->balance_due,
                        ]
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | 2. VALIDATE INVOICE STATUS
                    |--------------------------------------------------------------------------
                    */

                    $this->validateInvoiceStatus($invoice);

                    /*
                    |--------------------------------------------------------------------------
                    | 3. VALIDATE INVOICE ITEMS
                    |--------------------------------------------------------------------------
                    */

                    $this->validateInvoiceItems($invoice);

                    /*
                    |--------------------------------------------------------------------------
                    | 4. PROCESS EACH INVOICE ITEM
                    |--------------------------------------------------------------------------
                    */

                    foreach ($invoice->items as $invoiceItem) {

                        Log::info('Starting invoice item accrual.', [
                            'invoice_id' => $invoice->id,
                            'invoice_item_id' => $invoiceItem->id,
                            'invoice_item_class' => $invoiceItem::class,
                            'amount' => $invoiceItem->amount,
                            'discount_amount' => $invoiceItem->discount_amount,
                            'penalty_amount_before' => $invoiceItem->penalty_amount,
                            'interest_amount_before' => $invoiceItem->interest_amount,
                            'total_amount_before' => $invoiceItem->total_amount,
                        ]);

                        $this->accrueItem(
                            invoiceItem: $invoiceItem,
                            asOfDate: $asOfDate,
                        );

                        Log::info('Invoice item accrual completed.', [
                            'invoice_id' => $invoice->id,
                            'invoice_item_id' => $invoiceItem->id,
                            'penalty_amount_after' => $invoiceItem->penalty_amount,
                            'interest_amount_after' => $invoiceItem->interest_amount,
                            'total_amount_after' => $invoiceItem->total_amount,
                        ]);
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | 5. AGGREGATE INVOICE TOTALS
                    |--------------------------------------------------------------------------
                    */

                    $totals = $this->aggregateInvoiceTotals(
                        invoiceId: $invoice->id,
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | 6. RESOLVE PAID AMOUNT
                    |--------------------------------------------------------------------------
                    */

                    $paidAmount = $this->resolvePaidAmount($invoice);

                    /*
                    |--------------------------------------------------------------------------
                    | 7. CALCULATE BALANCE
                    |--------------------------------------------------------------------------
                    */

                    $totalAmount = max(
                        0.0,
                        $this->roundMoney(
                            (float) $totals->total_amount
                        )
                    );

                    $balanceDue = max(
                        0.0,
                        $this->roundMoney(
                            $totalAmount - $paidAmount
                        )
                    );

                    Log::info('Invoice balance calculated.', [
                        'invoice_id' => $invoice->id,
                        'total_amount' => $totalAmount,
                        'paid_amount' => $paidAmount,
                        'balance_due' => $balanceDue,
                    ]);

                    /*
                    |--------------------------------------------------------------------------
                    | 8. RESOLVE STATUS
                    |--------------------------------------------------------------------------
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

                    $this->updateInvoiceFinancialValues(
                        invoice: $invoice,
                        totals: $totals,
                        totalAmount: $totalAmount,
                        paidAmount: $paidAmount,
                        balanceDue: $balanceDue,
                        status: $status,
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | 10. COMPLETE
                    |--------------------------------------------------------------------------
                    */

                    Log::info(
                        'Invoice accrual completed successfully.',
                        [
                            'invoice_id' => $invoice->id,
                            'as_of_date' => $asOfDate->toDateString(),
                            'subtotal' => $this->roundMoney(
                                (float) $totals->subtotal
                            ),
                            'discount_amount' => $this->roundMoney(
                                (float) $totals->discount_amount
                            ),
                            'penalty_amount' => $this->roundMoney(
                                (float) $totals->penalty_amount
                            ),
                            'interest_amount' => $this->roundMoney(
                                (float) $totals->interest_amount
                            ),
                            'total_amount' => $totalAmount,
                            'paid_amount' => $paidAmount,
                            'balance_due' => $balanceDue,
                            'status' => $status,
                        ]
                    );

                    return $invoice->fresh([
                        'items',
                        'assessment',
                        'citizen',
                    ]);
                }
            );
        } catch (Throwable $exception) {

            Log::error('Invoice accrual failed.', [
                'invoice_id' => $invoiceId,
                'as_of_date' => $asOfDate->toDateString(),
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /**
     * ================================================================
     * VALIDATE INVOICE STATUS
     * ================================================================
     */
    protected function validateInvoiceStatus(
        Invoice $invoice
    ): void {
        if (
            in_array(
                $invoice->status,
                self::NON_ACCRUABLE_STATUSES,
                true
            )
        ) {
            Log::warning(
                'Invoice accrual rejected: invoice status does not allow accrual.',
                [
                    'invoice_id' => $invoice->id,
                    'status' => $invoice->status,
                ]
            );

            throw ValidationException::withMessages([
                'invoice' => [
                    'Penalty and interest cannot be accrued for this invoice status.',
                ],
            ]);
        }

        Log::info('Invoice status accepted for accrual.', [
            'invoice_id' => $invoice->id,
            'status' => $invoice->status,
        ]);
    }

    /**
     * ================================================================
     * VALIDATE INVOICE ITEMS
     * ================================================================
     */
    protected function validateInvoiceItems(
        Invoice $invoice
    ): void {
        if ($invoice->items->isEmpty()) {
            Log::warning(
                'Invoice accrual stopped: invoice has no items.',
                [
                    'invoice_id' => $invoice->id,
                ]
            );

            throw ValidationException::withMessages([
                'invoice' => [
                    'An invoice must contain at least one item before accrual can be calculated.',
                ],
            ]);
        }

        Log::info('Invoice items found for accrual.', [
            'invoice_id' => $invoice->id,
            'items_count' => $invoice->items->count(),
        ]);
    }

    /**
     * ================================================================
     * ACCRUE SINGLE INVOICE ITEM
     * ================================================================
     */
    protected function accrueItem(
        InvoiceItem $invoiceItem,
        CarbonInterface $asOfDate
    ): void {

        Log::info('Invoice item accrual entered.', [
            'invoice_id' => $invoiceItem->invoice_id,
            'invoice_item_id' => $invoiceItem->id,
            'invoice_item_class' => $invoiceItem::class,
            'as_of_date' => $asOfDate->toDateString(),
        ]);

        /*
        |--------------------------------------------------------------------------
        | 1. RESOLVE ASSESSMENT SERVICE
        |--------------------------------------------------------------------------
        */

        $assessmentService = $invoiceItem->assessmentService;

        if (! $assessmentService) {

            Log::warning(
                'Invoice item accrual stopped: assessment service not found.',
                [
                    'invoice_id' => $invoiceItem->invoice_id,
                    'invoice_item_id' => $invoiceItem->id,
                ]
            );

            $this->updateItemAmounts(
                invoiceItem: $invoiceItem,
                penalty: 0.0,
                interest: 0.0,
            );

            return;
        }

        Log::info('Assessment service resolved.', [
            'invoice_id' => $invoiceItem->invoice_id,
            'invoice_item_id' => $invoiceItem->id,
            'assessment_service_id' => $assessmentService->id ?? null,
            'assessment_service_class' => $assessmentService::class,
            'due_date' => $assessmentService->due_date,
            'agreement_date' => $assessmentService->agreement_date ?? null,
            'principal_amount' => $assessmentService->principal_amount ?? null,
            'computed_amount' => $assessmentService->computed_amount ?? null,
            'paid_principal_amount' => $assessmentService->paid_principal_amount ?? null,
            'has_penalty_rule' => (bool) $assessmentService->penaltyRule,
            'has_interest_rule' => (bool) $assessmentService->interestRule,
        ]);

        /*
        |--------------------------------------------------------------------------
        | 2. RESOLVE INVOICED PRINCIPAL
        |--------------------------------------------------------------------------
        |
        | The invoice item amount is the principal actually invoiced.
        |
        | This is intentionally kept separate from AssessmentService's
        | computed_amount because the invoice is the financial document
        | being accrued.
        |
        */

        $principal = max(
            0.0,
            $this->roundMoney(
                (float) $invoiceItem->amount
            )
        );

        Log::info('Invoice item principal resolved.', [
            'invoice_id' => $invoiceItem->invoice_id,
            'invoice_item_id' => $invoiceItem->id,
            'invoice_item_amount' => $invoiceItem->amount,
            'principal_used_for_accrual' => $principal,
        ]);

        if ($principal <= 0) {

            Log::warning(
                'Invoice item accrual stopped: principal is zero or negative.',
                [
                    'invoice_id' => $invoiceItem->invoice_id,
                    'invoice_item_id' => $invoiceItem->id,
                    'principal' => $principal,
                ]
            );

            $this->updateItemAmounts(
                invoiceItem: $invoiceItem,
                penalty: 0.0,
                interest: 0.0,
            );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | 3. RESOLVE DUE DATE
        |--------------------------------------------------------------------------
        */

        if (! $assessmentService->due_date) {

            Log::warning(
                'Invoice item accrual stopped: assessment service has no due date.',
                [
                    'invoice_id' => $invoiceItem->invoice_id,
                    'invoice_item_id' => $invoiceItem->id,
                    'assessment_service_id' => $assessmentService->id ?? null,
                ]
            );

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

        Log::info('Invoice item due date resolved.', [
            'invoice_id' => $invoiceItem->invoice_id,
            'invoice_item_id' => $invoiceItem->id,
            'due_date' => $dueDate->toDateString(),
            'as_of_date' => $asOfDate->toDateString(),
        ]);

        /*
        |--------------------------------------------------------------------------
        | 4. OVERDUE CHECK
        |--------------------------------------------------------------------------
        */

        if (! $asOfDate->gt($dueDate)) {

            Log::info(
                'Invoice item accrual stopped: item is not overdue.',
                [
                    'invoice_id' => $invoiceItem->invoice_id,
                    'invoice_item_id' => $invoiceItem->id,
                    'due_date' => $dueDate->toDateString(),
                    'as_of_date' => $asOfDate->toDateString(),
                ]
            );

            $this->updateItemAmounts(
                invoiceItem: $invoiceItem,
                penalty: 0.0,
                interest: 0.0,
            );

            return;
        }

        Log::info('Invoice item is overdue and will be accrued.', [
            'invoice_id' => $invoiceItem->invoice_id,
            'invoice_item_id' => $invoiceItem->id,
            'due_date' => $dueDate->toDateString(),
            'as_of_date' => $asOfDate->toDateString(),
            'overdue_days' => $dueDate->diffInDays($asOfDate),
        ]);

        /*
        |--------------------------------------------------------------------------
        | 5. PENALTY
        |--------------------------------------------------------------------------
        */

        Log::info('Calling PenaltyCalculator.', [
            'invoice_id' => $invoiceItem->invoice_id,
            'invoice_item_id' => $invoiceItem->id,
            'assessment_service_id' => $assessmentService->id ?? null,
            'calculator_class' => $this->penaltyCalculator::class,
            'as_of_date' => $asOfDate->toDateString(),
        ]);

        $penalty = $this->penaltyCalculator->calculate(
            $assessmentService,
            $asOfDate
        );

        Log::info('PenaltyCalculator returned result.', [
            'invoice_id' => $invoiceItem->invoice_id,
            'invoice_item_id' => $invoiceItem->id,
            'penalty_amount' => $penalty,
        ]);

        /*
        |--------------------------------------------------------------------------
        | 6. INTEREST
        |--------------------------------------------------------------------------
        */

        Log::info('Calling InterestCalculator.', [
            'invoice_id' => $invoiceItem->invoice_id,
            'invoice_item_id' => $invoiceItem->id,
            'assessment_service_id' => $assessmentService->id ?? null,
            'calculator_class' => $this->interestCalculator::class,
            'as_of_date' => $asOfDate->toDateString(),
        ]);

        $interest = $this->interestCalculator->calculate(
            $assessmentService,
            $asOfDate
        );

        Log::info('InterestCalculator returned result.', [
            'invoice_id' => $invoiceItem->invoice_id,
            'invoice_item_id' => $invoiceItem->id,
            'interest_amount' => $interest,
        ]);

        /*
        |--------------------------------------------------------------------------
        | 7. SANITIZE CALCULATOR RESULTS
        |--------------------------------------------------------------------------
        */

        $penalty = max(
            0.0,
            $this->roundMoney(
                (float) $penalty
            )
        );

        $interest = max(
            0.0,
            $this->roundMoney(
                (float) $interest
            )
        );

        Log::info('Accrual calculator results normalized.', [
            'invoice_id' => $invoiceItem->invoice_id,
            'invoice_item_id' => $invoiceItem->id,
            'penalty_amount' => $penalty,
            'interest_amount' => $interest,
        ]);

        /*
        |--------------------------------------------------------------------------
        | 8. UPDATE ITEM
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
     * Replaces the current penalty and interest values.
     *
     * This makes the operation idempotent.
     */
    protected function updateItemAmounts(
        InvoiceItem $invoiceItem,
        float $penalty,
        float $interest
    ): void {

        $amount = max(
            0.0,
            $this->roundMoney(
                (float) $invoiceItem->amount
            )
        );

        $discount = max(
            0.0,
            $this->roundMoney(
                (float) ($invoiceItem->discount_amount ?? 0)
            )
        );

        $penalty = max(
            0.0,
            $this->roundMoney($penalty)
        );

        $interest = max(
            0.0,
            $this->roundMoney($interest)
        );

        $total = max(
            0.0,
            $this->roundMoney(
                $amount
                - $discount
                + $penalty
                + $interest
            )
        );

        Log::info('Updating invoice item amounts.', [
            'invoice_id' => $invoiceItem->invoice_id,
            'invoice_item_id' => $invoiceItem->id,
            'amount' => $amount,
            'discount_amount' => $discount,
            'penalty_amount' => $penalty,
            'interest_amount' => $interest,
            'calculated_total_amount' => $total,
        ]);

        $invoiceItem->update([
            'penalty_amount' => $penalty,
            'interest_amount' => $interest,
            'total_amount' => $total,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Keep the in-memory model synchronized.
        |--------------------------------------------------------------------------
        */

        $invoiceItem->penalty_amount = $penalty;
        $invoiceItem->interest_amount = $interest;
        $invoiceItem->total_amount = $total;

        Log::info('Invoice item amounts updated.', [
            'invoice_id' => $invoiceItem->invoice_id,
            'invoice_item_id' => $invoiceItem->id,
            'penalty_amount' => $penalty,
            'interest_amount' => $interest,
            'total_amount' => $total,
        ]);
    }

    /**
     * ================================================================
     * AGGREGATE INVOICE TOTALS
     * ================================================================
     */
    protected function aggregateInvoiceTotals(
        string $invoiceId
    ): object {
        Log::info('Aggregating invoice item totals.', [
            'invoice_id' => $invoiceId,
        ]);

        $totals = InvoiceItem::query()
            ->where('invoice_id', $invoiceId)
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

        Log::info('Invoice totals aggregated.', [
            'invoice_id' => $invoiceId,
            'subtotal' => $totals->subtotal,
            'discount_amount' => $totals->discount_amount,
            'penalty_amount' => $totals->penalty_amount,
            'interest_amount' => $totals->interest_amount,
            'total_amount' => $totals->total_amount,
        ]);

        return $totals;
    }

    /**
     * ================================================================
     * RESOLVE PAID AMOUNT
     * ================================================================
     */
    protected function resolvePaidAmount(
        Invoice $invoice
    ): float {
        $paidAmount = max(
            0.0,
            $this->roundMoney(
                (float) ($invoice->paid_amount ?? 0)
            )
        );

        Log::info('Invoice paid amount resolved.', [
            'invoice_id' => $invoice->id,
            'paid_amount' => $paidAmount,
        ]);

        return $paidAmount;
    }

    /**
     * ================================================================
     * UPDATE INVOICE FINANCIAL VALUES
     * ================================================================
     */
    protected function updateInvoiceFinancialValues(
        Invoice $invoice,
        object $totals,
        float $totalAmount,
        float $paidAmount,
        float $balanceDue,
        string $status
    ): void {

        $subtotal = $this->roundMoney(
            (float) $totals->subtotal
        );

        $discountAmount = $this->roundMoney(
            (float) $totals->discount_amount
        );

        $penaltyAmount = $this->roundMoney(
            (float) $totals->penalty_amount
        );

        $interestAmount = $this->roundMoney(
            (float) $totals->interest_amount
        );

        Log::info('Updating invoice financial values.', [
            'invoice_id' => $invoice->id,
            'subtotal' => $subtotal,
            'discount_amount' => $discountAmount,
            'penalty_amount' => $penaltyAmount,
            'interest_amount' => $interestAmount,
            'total_amount' => $totalAmount,
            'paid_amount' => $paidAmount,
            'balance_due' => $balanceDue,
            'status' => $status,
        ]);

        $invoice->update([
            'subtotal' => $subtotal,
            'discount_amount' => $discountAmount,
            'penalty_amount' => $penaltyAmount,
            'interest_amount' => $interestAmount,
            'total_amount' => $totalAmount,
            'paid_amount' => $paidAmount,
            'balance_due' => $balanceDue,
            'status' => $status,
            'paid_at' => $status === 'PAID'
                ? ($invoice->paid_at ?? now())
                : null,
        ]);

        Log::info('Invoice financial values updated.', [
            'invoice_id' => $invoice->id,
            'subtotal' => $subtotal,
            'discount_amount' => $discountAmount,
            'penalty_amount' => $penaltyAmount,
            'interest_amount' => $interestAmount,
            'total_amount' => $totalAmount,
            'paid_amount' => $paidAmount,
            'balance_due' => $balanceDue,
            'status' => $status,
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
        | PAID
        |--------------------------------------------------------------------------
        */

        if ($balanceDue <= 0) {

            Log::info('Invoice status resolved as PAID.', [
                'invoice_id' => $invoice->id,
                'balance_due' => $balanceDue,
            ]);

            return 'PAID';
        }

        /*
        |--------------------------------------------------------------------------
        | PARTIALLY PAID
        |--------------------------------------------------------------------------
        */

        if ($paidAmount > 0) {

            Log::info(
                'Invoice status resolved as PARTIALLY_PAID.',
                [
                    'invoice_id' => $invoice->id,
                    'paid_amount' => $paidAmount,
                    'balance_due' => $balanceDue,
                ]
            );

            return 'PARTIALLY_PAID';
        }

        /*
        |--------------------------------------------------------------------------
        | OVERDUE
        |--------------------------------------------------------------------------
        */

        if ($invoice->due_date) {

            $invoiceDueDate = Carbon::parse(
                $invoice->due_date
            )->startOfDay();

            if ($asOfDate->gt($invoiceDueDate)) {

                Log::info('Invoice status resolved as OVERDUE.', [
                    'invoice_id' => $invoice->id,
                    'due_date' => $invoiceDueDate->toDateString(),
                    'as_of_date' => $asOfDate->toDateString(),
                    'balance_due' => $balanceDue,
                ]);

                return 'OVERDUE';
            }
        }

        /*
        |--------------------------------------------------------------------------
        | ISSUED
        |--------------------------------------------------------------------------
        */

        Log::info('Invoice status resolved as ISSUED.', [
            'invoice_id' => $invoice->id,
            'due_date' => $invoice->due_date?->toDateString(),
            'as_of_date' => $asOfDate->toDateString(),
            'balance_due' => $balanceDue,
        ]);

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
        return round($amount, 2);
    }
}