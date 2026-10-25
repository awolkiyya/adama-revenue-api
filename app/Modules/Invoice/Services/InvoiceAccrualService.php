<?php

namespace App\Modules\Invoice\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PaymentSchedule;
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
     * Invoices that must never be accrued.
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
     * Recalculate penalty and interest for every invoice item.
     *
     * Supported item types:
     *
     * 1. Direct collection:
     *    assessment_service_id = null
     *    payment_schedule_id = null
     *
     * 2. Scheduled assessment:
     *    assessment_service_id != null
     *    payment_schedule_id != null
     *
     * 3. One-time assessment:
     *    assessment_service_id != null
     *    payment_schedule_id = null
     *
     * Existing charges are replaced rather than incremented.
     */
    public function accrue(
        Invoice|string $invoice,
        CarbonInterface|string|null $asOfDate = null
    ): Invoice {
        $invoiceId = $invoice instanceof Invoice
            ? (string) $invoice->getKey()
            : trim($invoice);

        if ($invoiceId === '') {
            throw ValidationException::withMessages([
                'invoice' => [
                    'A valid invoice ID is required.',
                ],
            ]);
        }

        $asOfDate = $this->normalizeDate($asOfDate);

        Log::info('Invoice accrual started.', [
            'invoice_id' => $invoiceId,
            'as_of_date' => $asOfDate->toDateString(),
        ]);

        try {
            return DB::transaction(function () use (
                $invoiceId,
                $asOfDate
            ): Invoice {
                /*
                 * Serialize accrual operations for this invoice.
                 */
                $invoice = Invoice::query()
                    ->lockForUpdate()
                    ->findOrFail($invoiceId);

                $this->validateInvoiceStatus($invoice);

                /*
                 * Lock the invoice items before recalculating.
                 */
                $itemIds = InvoiceItem::query()
                    ->where('invoice_id', $invoice->getKey())
                    ->orderBy('line_number')
                    ->lockForUpdate()
                    ->pluck('id');

                if ($itemIds->isEmpty()) {
                    throw ValidationException::withMessages([
                        'invoice' => [
                            'An invoice must contain at least one item before accrual.',
                        ],
                    ]);
                }

                /*
                 * Load assessment services, their calculation rules,
                 * and the schedules referenced by invoice items.
                 */
                $items = InvoiceItem::query()
                    ->where('invoice_id', $invoice->getKey())
                    ->whereIn('id', $itemIds)
                    ->with([
                        'assessmentService.penaltyRule',
                        'assessmentService.interestRule',
                        'paymentSchedule',
                    ])
                    ->orderBy('line_number')
                    ->get();

                foreach ($items as $item) {
                    $this->accrueItem(
                        invoiceItem: $item,
                        asOfDate: $asOfDate,
                    );
                }

                /*
                 * Aggregate the recalculated, persisted item values.
                 */
                $totals = $this->aggregateInvoiceTotals(
                    (string) $invoice->getKey()
                );

                $paidAmount = $this->resolvePaidAmount($invoice);

                $totalAmount = $this->roundMoney(
                    (float) $totals->total_amount
                );

                $balanceDue = $this->roundMoney(
                    max(0.0, $totalAmount - $paidAmount)
                );

                $status = $this->resolveStatus(
                    invoice: $invoice,
                    paidAmount: $paidAmount,
                    balanceDue: $balanceDue,
                    asOfDate: $asOfDate,
                );

                $this->updateInvoiceFinancialValues(
                    invoice: $invoice,
                    totals: $totals,
                    totalAmount: $totalAmount,
                    paidAmount: $paidAmount,
                    balanceDue: $balanceDue,
                    status: $status,
                );

                Log::info('Invoice accrual completed.', [
                    'invoice_id' => $invoice->getKey(),
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
                ]);

                return $invoice->fresh([
                    'items',
                    'items.service',
                    'items.paymentSchedule',
                    'assessment',
                    'citizen',
                ]);
            });
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
     * Validate that the invoice can be accrued.
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
            throw ValidationException::withMessages([
                'invoice' => [
                    'Penalty and interest cannot be accrued for this invoice status.',
                ],
            ]);
        }
    }

    /**
     * Classify an invoice item and dispatch it to the correct path.
     */
    protected function accrueItem(
        InvoiceItem $invoiceItem,
        CarbonInterface $asOfDate
    ): void {
        $assessmentServiceId = $invoiceItem->assessment_service_id;
        $paymentScheduleId = $invoiceItem->payment_schedule_id;

        $hasAssessmentServiceId = $assessmentServiceId !== null;
        $hasPaymentScheduleId = $paymentScheduleId !== null;

        $assessmentService = $invoiceItem->assessmentService;
        $paymentSchedule = $invoiceItem->paymentSchedule;

        /*
         * DIRECT COLLECTION
         *
         * Both relationship IDs must be null.
         */
        if (
            ! $hasAssessmentServiceId
            && ! $hasPaymentScheduleId
        ) {
            if (! $invoiceItem->isDirectCollectionItem()) {
                throw ValidationException::withMessages([
                    'invoice' => [
                        "Invoice item {$invoiceItem->getKey()} has inconsistent direct-collection relationships.",
                    ],
                ]);
            }

            $this->updateItemAmounts(
                invoiceItem: $invoiceItem,
                penalty: 0.0,
                interest: 0.0,
            );

            Log::info('Direct-collection invoice item accrual skipped.', [
                'invoice_id' => $invoiceItem->invoice_id,
                'invoice_item_id' => $invoiceItem->getKey(),
            ]);

            return;
        }

        /*
         * A schedule without an assessment service is invalid.
         */
        if (
            $hasPaymentScheduleId
            && ! $hasAssessmentServiceId
        ) {
            throw ValidationException::withMessages([
                'invoice' => [
                    "Invoice item {$invoiceItem->getKey()} references a payment schedule without an assessment service.",
                ],
            ]);
        }

        /*
         * A service ID exists, but the referenced record could not
         * be loaded. Do not silently classify this as direct collection.
         */
        if (
            $hasAssessmentServiceId
            && ! $assessmentService
        ) {
            throw ValidationException::withMessages([
                'invoice' => [
                    "Invoice item {$invoiceItem->getKey()} references a missing assessment service.",
                ],
            ]);
        }

        /*
         * A schedule ID exists, but the referenced record could not
         * be loaded. Do not treat this as a one-time assessment.
         */
        if (
            $hasPaymentScheduleId
            && ! $paymentSchedule
        ) {
            throw ValidationException::withMessages([
                'invoice' => [
                    "Invoice item {$invoiceItem->getKey()} references a missing payment schedule.",
                ],
            ]);
        }

        /*
         * SCHEDULED ASSESSMENT
         */
        if ($hasPaymentScheduleId) {
            $this->accrueScheduledItem(
                invoiceItem: $invoiceItem,
                assessmentService: $assessmentService,
                paymentSchedule: $paymentSchedule,
                asOfDate: $asOfDate,
            );

            return;
        }

        /*
         * ONE-TIME ASSESSMENT
         */
        $this->accrueOneTimeAssessmentItem(
            invoiceItem: $invoiceItem,
            assessmentService: $assessmentService,
            asOfDate: $asOfDate,
        );
    }

    /**
     * Recalculate charges for a scheduled assessment invoice item.
     *
     * The schedule supplies the installment principal and due date.
     * The assessment service supplies the configured calculation rules
     * and agreement-related information.
     */
    protected function accrueScheduledItem(
        InvoiceItem $invoiceItem,
        $assessmentService,
        PaymentSchedule $paymentSchedule,
        CarbonInterface $asOfDate
    ): void {
        /*
         * The schedule must belong to this assessment service.
         */
        if (
            (string) $paymentSchedule->assessment_service_id
            !== (string) $assessmentService->getKey()
        ) {
            throw ValidationException::withMessages([
                'invoice' => [
                    "Payment schedule {$paymentSchedule->getKey()} does not belong to the assessment service referenced by invoice item {$invoiceItem->getKey()}.",
                ],
            ]);
        }

        if (! $paymentSchedule->due_date) {
            throw ValidationException::withMessages([
                'invoice' => [
                    "Payment schedule {$paymentSchedule->getKey()} has no due date. Accrual was stopped.",
                ],
            ]);
        }

        if (
            $paymentSchedule->amount_due === null
            || ! is_numeric($paymentSchedule->amount_due)
        ) {
            throw ValidationException::withMessages([
                'invoice' => [
                    "Payment schedule {$paymentSchedule->getKey()} has no valid installment principal.",
                ],
            ]);
        }

        $invoicePrincipal = $this->roundMoney(
            max(0.0, (float) $invoiceItem->amount)
        );

        $schedulePrincipal = (float) $paymentSchedule->amount_due;
        $schedulePaid = (float) ($paymentSchedule->amount_paid ?? 0);

        if (
            ! is_finite($schedulePrincipal)
            || ! is_finite($schedulePaid)
            || $schedulePrincipal < 0
            || $schedulePaid < 0
            || $schedulePaid > $schedulePrincipal
        ) {
            throw ValidationException::withMessages([
                'invoice' => [
                    "Payment schedule {$paymentSchedule->getKey()} contains invalid principal or payment values.",
                ],
            ]);
        }

        /*
         * The invoice item must not exceed the original scheduled
         * principal. It may differ from the current schedule balance
         * because payments may have occurred after invoice creation.
         */
        if (
            $invoicePrincipal - $this->roundMoney($schedulePrincipal)
            > 0.009
        ) {
            throw ValidationException::withMessages([
                'invoice' => [
                    "Invoice item {$invoiceItem->getKey()} exceeds the principal amount of its linked payment schedule.",
                ],
            ]);
        }

        $dueDate = Carbon::parse(
            $paymentSchedule->due_date
        )->startOfDay();

        /*
         * Charges do not apply before or on the schedule due date.
         */
        if (
            $asOfDate->lte($dueDate)
            || $invoicePrincipal <= 0
        ) {
            $this->updateItemAmounts(
                invoiceItem: $invoiceItem,
                penalty: 0.0,
                interest: 0.0,
            );

            return;
        }

        /*
         * Calculators accept AssessmentService.
         *
         * Use an in-memory clone to supply schedule-specific values.
         * Do not save this clone; the source assessment service must
         * remain unchanged.
         *
         * PRINCIPAL basis uses schedule amount_due.
         * OUTSTANDING basis uses amount_due - amount_paid.
         *
         * The calculators must interpret paid_principal_amount
         * consistently with these values.
         */
        $calculationService = clone $assessmentService;

        $calculationService->setAttribute(
            'computed_amount',
            $schedulePrincipal
        );

        $calculationService->setAttribute(
            'paid_principal_amount',
            $schedulePaid
        );

        $calculationService->setAttribute(
            'due_date',
            $paymentSchedule->due_date
        );

        $penalty = $this->penaltyCalculator->calculate(
            $calculationService,
            $asOfDate,
        );

        $interest = $this->interestCalculator->calculate(
            $calculationService,
            $asOfDate,
        );

        $this->validateCalculatedAmounts(
            penalty: $penalty,
            interest: $interest,
        );

        $penalty = $this->roundMoney(
            max(0.0, (float) $penalty)
        );

        $interest = $this->roundMoney(
            max(0.0, (float) $interest)
        );

        $this->updateItemAmounts(
            invoiceItem: $invoiceItem,
            penalty: $penalty,
            interest: $interest,
        );

        Log::info('Scheduled invoice item accrual recalculated.', [
            'invoice_id' => $invoiceItem->invoice_id,
            'invoice_item_id' => $invoiceItem->getKey(),
            'assessment_service_id' => $assessmentService->getKey(),
            'payment_schedule_id' => $paymentSchedule->getKey(),
            'installment_number' => $paymentSchedule->installment_number,
            'due_date' => $dueDate->toDateString(),
            'as_of_date' => $asOfDate->toDateString(),
            'invoice_principal' => $invoicePrincipal,
            'schedule_principal' => $schedulePrincipal,
            'schedule_amount_paid' => $schedulePaid,
            'penalty_amount' => $penalty,
            'interest_amount' => $interest,
        ]);
    }

    /**
     * Recalculate charges for a one-time assessment invoice item.
     *
     * A one-time invoice item must represent the complete service
     * principal under the current invoice creation policy.
     */
    protected function accrueOneTimeAssessmentItem(
        InvoiceItem $invoiceItem,
        $assessmentService,
        CarbonInterface $asOfDate
    ): void {
        if (! $assessmentService->due_date) {
            throw ValidationException::withMessages([
                'invoice' => [
                    "Assessment service {$assessmentService->getKey()} has no due date. Accrual was stopped.",
                ],
            ]);
        }

        if (
            $assessmentService->computed_amount === null
            || ! is_numeric($assessmentService->computed_amount)
        ) {
            throw ValidationException::withMessages([
                'invoice' => [
                    "Assessment service {$assessmentService->getKey()} has no valid computed principal amount.",
                ],
            ]);
        }

        $invoicePrincipal = $this->roundMoney(
            max(0.0, (float) $invoiceItem->amount)
        );

        $servicePrincipal = $this->roundMoney(
            max(0.0, (float) $assessmentService->computed_amount)
        );

        if (
            abs($invoicePrincipal - $servicePrincipal) > 0.009
        ) {
            Log::warning(
                'One-time assessment accrual rejected because invoice principal differs from assessment service principal.',
                [
                    'invoice_id' => $invoiceItem->invoice_id,
                    'invoice_item_id' => $invoiceItem->getKey(),
                    'assessment_service_id' => $assessmentService->getKey(),
                    'invoice_principal' => $invoicePrincipal,
                    'service_principal' => $servicePrincipal,
                    'as_of_date' => $asOfDate->toDateString(),
                ]
            );

            throw ValidationException::withMessages([
                'invoice' => [
                    "Invoice item {$invoiceItem->getKey()} does not represent the complete assessment service amount.",
                ],
            ]);
        }

        $dueDate = Carbon::parse(
            $assessmentService->due_date
        )->startOfDay();

        if (
            $asOfDate->lte($dueDate)
            || $invoicePrincipal <= 0
        ) {
            $this->updateItemAmounts(
                invoiceItem: $invoiceItem,
                penalty: 0.0,
                interest: 0.0,
            );

            return;
        }

        $penalty = $this->penaltyCalculator->calculate(
            $assessmentService,
            $asOfDate,
        );

        $interest = $this->interestCalculator->calculate(
            $assessmentService,
            $asOfDate,
        );

        $this->validateCalculatedAmounts(
            penalty: $penalty,
            interest: $interest,
        );

        $penalty = $this->roundMoney(
            max(0.0, (float) $penalty)
        );

        $interest = $this->roundMoney(
            max(0.0, (float) $interest)
        );

        $this->updateItemAmounts(
            invoiceItem: $invoiceItem,
            penalty: $penalty,
            interest: $interest,
        );

        Log::info('One-time assessment invoice item accrual recalculated.', [
            'invoice_id' => $invoiceItem->invoice_id,
            'invoice_item_id' => $invoiceItem->getKey(),
            'assessment_service_id' => $assessmentService->getKey(),
            'due_date' => $dueDate->toDateString(),
            'as_of_date' => $asOfDate->toDateString(),
            'principal' => $invoicePrincipal,
            'penalty_amount' => $penalty,
            'interest_amount' => $interest,
        ]);
    }

    /**
     * Reject invalid calculator results.
     */
    protected function validateCalculatedAmounts(
        mixed $penalty,
        mixed $interest
    ): void {
        if (
            ! is_numeric($penalty)
            || ! is_numeric($interest)
            || ! is_finite((float) $penalty)
            || ! is_finite((float) $interest)
        ) {
            throw ValidationException::withMessages([
                'invoice' => [
                    'The penalty or interest calculator returned an invalid amount.',
                ],
            ]);
        }
    }

    /**
     * Replace item charges and recalculate its total.
     *
     * Principal and discount are preserved. Only penalty, interest,
     * and total_amount are updated.
     */
    protected function updateItemAmounts(
        InvoiceItem $invoiceItem,
        float $penalty,
        float $interest
    ): void {
        $amount = (float) $invoiceItem->amount;
        $discount = (float) ($invoiceItem->discount_amount ?? 0);

        if (
            ! is_finite($amount)
            || ! is_finite($discount)
            || $amount < 0
            || $discount < 0
        ) {
            throw ValidationException::withMessages([
                'invoice' => [
                    "Invoice item {$invoiceItem->getKey()} contains an invalid principal or discount amount.",
                ],
            ]);
        }

        if ($discount - $amount > 0.0001) {
            throw ValidationException::withMessages([
                'invoice' => [
                    "Discount exceeds the principal amount for invoice item {$invoiceItem->getKey()}.",
                ],
            ]);
        }

        if (
            ! is_finite($penalty)
            || ! is_finite($interest)
            || $penalty < 0
            || $interest < 0
        ) {
            throw ValidationException::withMessages([
                'invoice' => [
                    "Invoice item {$invoiceItem->getKey()} has invalid penalty or interest amounts.",
                ],
            ]);
        }

        $penalty = $this->roundMoney($penalty);
        $interest = $this->roundMoney($interest);

        $total = $this->roundMoney(
            $amount - $discount + $penalty + $interest
        );

        $invoiceItem->update([
            'penalty_amount' => $penalty,
            'interest_amount' => $interest,
            'total_amount' => $total,
        ]);
    }

    /**
     * Aggregate persisted invoice item values.
     */
    protected function aggregateInvoiceTotals(
        string $invoiceId
    ): object {
        return InvoiceItem::query()
            ->where('invoice_id', $invoiceId)
            ->selectRaw(
                'COALESCE(SUM(amount), 0) AS subtotal'
            )
            ->selectRaw(
                'COALESCE(SUM(discount_amount), 0) AS discount_amount'
            )
            ->selectRaw(
                'COALESCE(SUM(penalty_amount), 0) AS penalty_amount'
            )
            ->selectRaw(
                'COALESCE(SUM(interest_amount), 0) AS interest_amount'
            )
            ->selectRaw(
                'COALESCE(SUM(total_amount), 0) AS total_amount'
            )
            ->firstOrFail();
    }

    /**
     * Resolve paid amount from the invoice record.
     *
     * Payment processing remains responsible for maintaining
     * this field consistently with confirmed payments.
     */
    protected function resolvePaidAmount(
        Invoice $invoice
    ): float {
        $paidAmount = (float) ($invoice->paid_amount ?? 0);

        if (! is_finite($paidAmount) || $paidAmount < 0) {
            throw ValidationException::withMessages([
                'invoice' => [
                    'The invoice contains an invalid paid amount.',
                ],
            ]);
        }

        return $this->roundMoney($paidAmount);
    }

    /**
     * Persist aggregated invoice totals.
     */
    protected function updateInvoiceFinancialValues(
        Invoice $invoice,
        object $totals,
        float $totalAmount,
        float $paidAmount,
        float $balanceDue,
        string $status
    ): void {
        $invoice->update([
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
            'paid_at' => $status === 'PAID'
                ? ($invoice->paid_at ?? now())
                : null,
        ]);
    }

    /**
     * Resolve invoice status using invoice-level due date.
     *
     * A separate policy is needed if invoices containing multiple
     * schedules should become overdue based on installment dates.
     */
    protected function resolveStatus(
        Invoice $invoice,
        float $paidAmount,
        float $balanceDue,
        CarbonInterface $asOfDate
    ): string {
        if ($balanceDue <= 0) {
            return 'PAID';
        }

        if ($paidAmount > 0) {
            return 'PARTIALLY_PAID';
        }

        if ($invoice->due_date) {
            $invoiceDueDate = Carbon::parse(
                $invoice->due_date
            )->startOfDay();

            if ($asOfDate->gt($invoiceDueDate)) {
                return 'OVERDUE';
            }
        }

        return 'ISSUED';
    }

    /**
     * Normalize the calculation date to the start of its day.
     */
    protected function normalizeDate(
        CarbonInterface|string|null $date
    ): CarbonInterface {
        if ($date instanceof CarbonInterface) {
            return $date->copy()->startOfDay();
        }

        return $date !== null
            ? Carbon::parse($date)->startOfDay()
            : now()->startOfDay();
    }

    /**
     * Round currency totals to two decimal places.
     */
    protected function roundMoney(float $amount): float
    {
        return round($amount, 2);
    }
}