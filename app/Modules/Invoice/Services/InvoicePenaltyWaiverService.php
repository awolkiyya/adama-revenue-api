<?php

namespace App\Modules\Invoice\Services;

use App\Models\Invoice;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoicePenaltyWaiverService
{
    /**
     * ============================================================
     * APPLY PENALTY WAIVER
     * ============================================================
     *
     * Applies a penalty waiver to the TOTAL PENALTY of the invoice.
     *
     * The waiver does NOT belong to an invoice item.
     *
     * Financial formula:
     *
     * subtotal
     * + penalty
     * - penalty waiver
     * + interest
     * = total amount
     *
     * The operation:
     *
     * - locks the invoice
     * - validates the invoice status
     * - validates the waiver amount
     * - ensures the waiver does not exceed the penalty
     * - preserves existing waiver
     * - recalculates invoice totals
     * - recalculates balance
     */
    public function apply(
        Invoice|string $invoice,
        float $amount,
        ?string $reason = null,
    ): Invoice {
        return DB::transaction(function () use (
            $invoice,
            $amount,
            $reason,
        ) {
            /*
             * --------------------------------------------------------
             * VALIDATE AMOUNT
             * --------------------------------------------------------
             */

            $amount = round($amount, 2);

            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    'amount' =>
                        'Penalty waiver amount must be greater than zero.',
                ]);
            }

            /*
             * --------------------------------------------------------
             * RESOLVE INVOICE
             * --------------------------------------------------------
             */

            $invoiceModel = $this->resolveInvoice($invoice);

            /*
             * --------------------------------------------------------
             * LOCK INVOICE
             * --------------------------------------------------------
             *
             * Prevent concurrent:
             *
             * - penalty accrual
             * - payment
             * - waiver
             *
             * operations from modifying the same invoice
             * simultaneously.
             */

            $invoiceModel = Invoice::query()
                ->whereKey($invoiceModel->id)
                ->lockForUpdate()
                ->first();

            if (! $invoiceModel) {
                throw ValidationException::withMessages([
                    'invoice' =>
                        'Invoice was not found.',
                ]);
            }

            /*
             * --------------------------------------------------------
             * VALIDATE STATUS
             * --------------------------------------------------------
             *
             * A penalty waiver is a financial modification, so it
             * is allowed only while the invoice is financially active.
             */

            $this->ensureActiveInvoice($invoiceModel);

            /*
             * --------------------------------------------------------
             * CURRENT PENALTY
             * --------------------------------------------------------
             */

            $penaltyAmount = round(
                max(
                    0,
                    (float) $invoiceModel->penalty_amount
                ),
                2
            );

            /*
             * --------------------------------------------------------
             * CURRENT WAIVER
             * --------------------------------------------------------
             */

            $existingWaiver = round(
                max(
                    0,
                    (float) $invoiceModel->penalty_discount_amount
                ),
                2
            );

            /*
             * --------------------------------------------------------
             * AVAILABLE PENALTY
             * --------------------------------------------------------
             *
             * A waiver can never exceed the portion of the penalty
             * that has not already been waived.
             */

            $remainingPenalty = round(
                max(
                    0,
                    $penaltyAmount - $existingWaiver
                ),
                2
            );

            if ($remainingPenalty <= 0) {
                throw ValidationException::withMessages([
                    'amount' =>
                        'There is no remaining penalty available for waiver.',
                ]);
            }

            /*
             * --------------------------------------------------------
             * VALIDATE WAIVER LIMIT
             * --------------------------------------------------------
             */

            if ($amount > $remainingPenalty) {
                throw ValidationException::withMessages([
                    'amount' => sprintf(
                        'Penalty waiver cannot exceed the remaining penalty of %.2f ETB.',
                        $remainingPenalty
                    ),
                ]);
            }

            /*
             * --------------------------------------------------------
             * NEW WAIVER
             * --------------------------------------------------------
             */

            $newWaiverAmount = round(
                $existingWaiver + $amount,
                2
            );

            /*
             * --------------------------------------------------------
             * RECALCULATE TOTAL
             * --------------------------------------------------------
             */

            $totals = $this->calculateTotals(
                invoice: $invoiceModel,
                penaltyDiscountAmount: $newWaiverAmount,
            );

            /*
             * --------------------------------------------------------
             * VALIDATE OVERPAYMENT
             * --------------------------------------------------------
             *
             * If overpayment is disabled, a penalty waiver must not
             * reduce the invoice total below the amount already paid.
             */

            $this->ensureNoOverpayment(
                invoice: $invoiceModel,
                totalAmount: $totals['total_amount'],
            );

            /*
             * --------------------------------------------------------
             * UPDATE INVOICE
             * --------------------------------------------------------
             */

            $invoiceModel->update([
                'penalty_discount_amount' =>
                    $newWaiverAmount,

                'total_amount' =>
                    $totals['total_amount'],

                'balance_due' =>
                    $totals['balance_due'],

                'status' =>
                    $totals['status'],

                'paid_at' =>
                    $totals['paid_at'],
            ]);

            /*
             * --------------------------------------------------------
             * REASON
             * --------------------------------------------------------
             *
             * The reason is intentionally not stored in the invoice
             * itself here.
             *
             * It should be recorded through your audit/business-action
             * mechanism together with:
             *
             * - user
             * - amount
             * - reason
             * - invoice
             * - timestamp
             *
             * We normalize it here so the service contract accepts it
             * cleanly when the audit layer is connected.
             */

            if ($reason !== null) {
                $reason = trim($reason);
            }

            /*
             * --------------------------------------------------------
             * RETURN FRESH INVOICE
             * --------------------------------------------------------
             */

            return $invoiceModel->fresh([
                'items',
            ]);
        });
    }


    /**
     * ============================================================
     * REMOVE PENALTY WAIVER
     * ============================================================
     *
     * Removes part or all of an existing invoice-level penalty waiver.
     *
     * This does NOT recalculate the penalty.
     *
     * It only changes the authorized waiver amount.
     */
    public function remove(
        Invoice|string $invoice,
        float $amount,
        ?string $reason = null,
    ): Invoice {
        return DB::transaction(function () use (
            $invoice,
            $amount,
            $reason,
        ) {
            /*
             * --------------------------------------------------------
             * VALIDATE AMOUNT
             * --------------------------------------------------------
             */

            $amount = round($amount, 2);

            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    'amount' =>
                        'Penalty waiver removal amount must be greater than zero.',
                ]);
            }

            /*
             * --------------------------------------------------------
             * RESOLVE INVOICE
             * --------------------------------------------------------
             */

            $invoiceModel = $this->resolveInvoice($invoice);

            /*
             * --------------------------------------------------------
             * LOCK INVOICE
             * --------------------------------------------------------
             */

            $invoiceModel = Invoice::query()
                ->whereKey($invoiceModel->id)
                ->lockForUpdate()
                ->first();

            if (! $invoiceModel) {
                throw ValidationException::withMessages([
                    'invoice' =>
                        'Invoice was not found.',
                ]);
            }

            /*
             * --------------------------------------------------------
             * VALIDATE STATUS
             * --------------------------------------------------------
             */

            $this->ensureActiveInvoice($invoiceModel);

            /*
             * --------------------------------------------------------
             * CURRENT WAIVER
             * --------------------------------------------------------
             */

            $existingWaiver = round(
                max(
                    0,
                    (float) $invoiceModel->penalty_discount_amount
                ),
                2
            );

            if ($existingWaiver <= 0) {
                throw ValidationException::withMessages([
                    'amount' =>
                        'This invoice has no penalty waiver to remove.',
                ]);
            }

            /*
             * --------------------------------------------------------
             * VALIDATE REMOVAL AMOUNT
             * --------------------------------------------------------
             */

            if ($amount > $existingWaiver) {
                throw ValidationException::withMessages([
                    'amount' => sprintf(
                        'Waiver removal cannot exceed the existing waiver of %.2f ETB.',
                        $existingWaiver
                    ),
                ]);
            }

            /*
             * --------------------------------------------------------
             * NEW WAIVER
             * --------------------------------------------------------
             */

            $newWaiverAmount = round(
                $existingWaiver - $amount,
                2
            );

            /*
             * --------------------------------------------------------
             * RECALCULATE TOTAL
             * --------------------------------------------------------
             */

            $totals = $this->calculateTotals(
                invoice: $invoiceModel,
                penaltyDiscountAmount: $newWaiverAmount,
            );

            /*
             * --------------------------------------------------------
             * UPDATE INVOICE
             * --------------------------------------------------------
             */

            $invoiceModel->update([
                'penalty_discount_amount' =>
                    $newWaiverAmount,

                'total_amount' =>
                    $totals['total_amount'],

                'balance_due' =>
                    $totals['balance_due'],

                'status' =>
                    $totals['status'],

                'paid_at' =>
                    $totals['paid_at'],
            ]);

            /*
             * --------------------------------------------------------
             * NORMALIZE REASON
             * --------------------------------------------------------
             */

            if ($reason !== null) {
                $reason = trim($reason);
            }

            /*
             * --------------------------------------------------------
             * RETURN FRESH INVOICE
             * --------------------------------------------------------
             */

            return $invoiceModel->fresh([
                'items',
            ]);
        });
    }


    /**
     * ============================================================
     * WAIVE FULL PENALTY
     * ============================================================
     *
     * Applies a 100% waiver to the remaining invoice penalty.
     */
    public function waiveFullPenalty(
        Invoice|string $invoice,
        ?string $reason = null,
    ): Invoice {
        return DB::transaction(function () use (
            $invoice,
            $reason,
        ) {
            /*
             * --------------------------------------------------------
             * RESOLVE INVOICE
             * --------------------------------------------------------
             */

            $invoiceModel = $this->resolveInvoice($invoice);

            /*
             * --------------------------------------------------------
             * LOCK INVOICE
             * --------------------------------------------------------
             */

            $invoiceModel = Invoice::query()
                ->whereKey($invoiceModel->id)
                ->lockForUpdate()
                ->first();

            if (! $invoiceModel) {
                throw ValidationException::withMessages([
                    'invoice' =>
                        'Invoice was not found.',
                ]);
            }

            /*
             * --------------------------------------------------------
             * VALIDATE STATUS
             * --------------------------------------------------------
             */

            $this->ensureActiveInvoice($invoiceModel);

            /*
             * --------------------------------------------------------
             * CURRENT PENALTY
             * --------------------------------------------------------
             */

            $penaltyAmount = round(
                max(
                    0,
                    (float) $invoiceModel->penalty_amount
                ),
                2
            );

            /*
             * --------------------------------------------------------
             * CURRENT WAIVER
             * --------------------------------------------------------
             */

            $existingWaiver = round(
                max(
                    0,
                    (float) $invoiceModel->penalty_discount_amount
                ),
                2
            );

            /*
             * --------------------------------------------------------
             * REMAINING PENALTY
             * --------------------------------------------------------
             */

            $remainingPenalty = round(
                max(
                    0,
                    $penaltyAmount - $existingWaiver
                ),
                2
            );

            if ($remainingPenalty <= 0) {
                throw ValidationException::withMessages([
                    'amount' =>
                        'There is no remaining penalty available for waiver.',
                ]);
            }

            /*
             * --------------------------------------------------------
             * FULL WAIVER
             * --------------------------------------------------------
             */

            $newWaiverAmount = round(
                $existingWaiver + $remainingPenalty,
                2
            );

            /*
             * --------------------------------------------------------
             * RECALCULATE TOTAL
             * --------------------------------------------------------
             */

            $totals = $this->calculateTotals(
                invoice: $invoiceModel,
                penaltyDiscountAmount: $newWaiverAmount,
            );

            /*
             * --------------------------------------------------------
             * VALIDATE OVERPAYMENT
             * --------------------------------------------------------
             */

            $this->ensureNoOverpayment(
                invoice: $invoiceModel,
                totalAmount: $totals['total_amount'],
            );

            /*
             * --------------------------------------------------------
             * UPDATE INVOICE
             * --------------------------------------------------------
             */

            $invoiceModel->update([
                'penalty_discount_amount' =>
                    $newWaiverAmount,

                'total_amount' =>
                    $totals['total_amount'],

                'balance_due' =>
                    $totals['balance_due'],

                'status' =>
                    $totals['status'],

                'paid_at' =>
                    $totals['paid_at'],
            ]);

            /*
             * --------------------------------------------------------
             * NORMALIZE REASON
             * --------------------------------------------------------
             */

            if ($reason !== null) {
                $reason = trim($reason);
            }

            /*
             * --------------------------------------------------------
             * RETURN FRESH INVOICE
             * --------------------------------------------------------
             */

            return $invoiceModel->fresh([
                'items',
            ]);
        });
    }


    /**
     * ============================================================
     * RESOLVE INVOICE
     * ============================================================
     */
    protected function resolveInvoice(
        Invoice|string $invoice
    ): Invoice {
        if ($invoice instanceof Invoice) {
            return $invoice;
        }

        $model = Invoice::query()
            ->find($invoice);

        if (! $model) {
            throw ValidationException::withMessages([
                'invoice' =>
                    'Invoice was not found.',
            ]);
        }

        return $model;
    }


    /**
     * ============================================================
     * VALIDATE ACTIVE INVOICE
     * ============================================================
     */
    protected function ensureActiveInvoice(
        Invoice $invoice
    ): void {
        if (! in_array(
            $invoice->status,
            [
                'ISSUED',
                'PARTIALLY_PAID',
                'OVERDUE',
            ],
            true
        )) {
            throw ValidationException::withMessages([
                'invoice' => sprintf(
                    'Penalty waiver cannot be applied to an invoice with status "%s".',
                    $invoice->status
                ),
            ]);
        }
    }


    /**
     * ============================================================
     * CALCULATE INVOICE TOTALS
     * ============================================================
     *
     * Financial formula:
     *
     * subtotal
     * + penalty
     * - penalty discount
     * + interest
     * = total
     *
     * Important:
     *
     * penalty_discount_amount is an invoice-level value.
     */
    protected function calculateTotals(
        Invoice $invoice,
        float $penaltyDiscountAmount,
    ): array {
        $subtotal = round(
            max(
                0,
                (float) $invoice->subtotal
            ),
            2
        );

        $penalty = round(
            max(
                0,
                (float) $invoice->penalty_amount
            ),
            2
        );

        $interest = round(
            max(
                0,
                (float) $invoice->interest_amount
            ),
            2
        );

        $penaltyDiscountAmount = round(
            max(
                0,
                $penaltyDiscountAmount
            ),
            2
        );

        /*
         * Never allow the waiver to exceed the total invoice penalty.
         */
        $penaltyDiscountAmount = min(
            $penaltyDiscountAmount,
            $penalty
        );

        /*
         * --------------------------------------------------------
         * TOTAL
         * --------------------------------------------------------
         */

        $totalAmount = round(
            $subtotal
            + $penalty
            - $penaltyDiscountAmount
            + $interest,
            2
        );

        $totalAmount = max(
            0,
            $totalAmount
        );

        /*
         * --------------------------------------------------------
         * PAID
         * --------------------------------------------------------
         */

        $paidAmount = round(
            max(
                0,
                (float) $invoice->paid_amount
            ),
            2
        );

        /*
         * --------------------------------------------------------
         * BALANCE
         * --------------------------------------------------------
         */

        $balanceDue = round(
            max(
                0,
                $totalAmount - $paidAmount
            ),
            2
        );

        /*
         * --------------------------------------------------------
         * STATUS
         * --------------------------------------------------------
         */

        $status = $this->resolveStatus(
            invoice: $invoice,
            paidAmount: $paidAmount,
            balanceDue: $balanceDue,
        );

        /*
         * --------------------------------------------------------
         * PAID AT
         * --------------------------------------------------------
         */

        $paidAt = $balanceDue <= 0
            ? ($invoice->paid_at ?? now())
            : null;

        return [
            'subtotal' =>
                $subtotal,

            'penalty' =>
                $penalty,

            'penalty_discount_amount' =>
                $penaltyDiscountAmount,

            'interest' =>
                $interest,

            'total_amount' =>
                $totalAmount,

            'paid_amount' =>
                $paidAmount,

            'balance_due' =>
                $balanceDue,

            'status' =>
                $status,

            'paid_at' =>
                $paidAt,
        ];
    }


    /**
     * ============================================================
     * PREVENT OVERPAYMENT
     * ============================================================
     *
     * If invoice_allow_overpayment is disabled, a penalty waiver
     * cannot make the invoice total lower than the amount already
     * paid.
     */
    protected function ensureNoOverpayment(
        Invoice $invoice,
        float $totalAmount,
    ): void {
        $setting = \App\Models\RevenueSetting::query()
            ->where('is_active', true)
            ->first();

        /*
         * Default to the application's agreed business rule:
         * overpayment is not allowed.
         */
        $allowOverpayment = (bool) (
            $setting?->invoice_allow_overpayment ?? false
        );

        if ($allowOverpayment) {
            return;
        }

        $paidAmount = round(
            max(
                0,
                (float) $invoice->paid_amount
            ),
            2
        );

        if ($paidAmount > $totalAmount) {
            throw ValidationException::withMessages([
                'amount' => sprintf(
                    'This penalty waiver would reduce the invoice total below the amount already paid. Current paid amount is %.2f ETB and the resulting invoice total would be %.2f ETB.',
                    $paidAmount,
                    $totalAmount
                ),
            ]);
        }
    }


    /**
     * ============================================================
     * RESOLVE INVOICE STATUS
     * ============================================================
     */
    protected function resolveStatus(
        Invoice $invoice,
        float $paidAmount,
        float $balanceDue,
    ): string {
        /*
         * Fully paid.
         */
        if ($balanceDue <= 0) {
            return 'PAID';
        }

        /*
         * Some amount has already been paid.
         */
        if ($paidAmount > 0) {
            return 'PARTIALLY_PAID';
        }

        /*
         * Invoice has passed its due date.
         */
        if (
            $invoice->due_date
            && Carbon::parse($invoice->due_date)
                ->startOfDay()
                ->lt(now()->startOfDay())
        ) {
            return 'OVERDUE';
        }

        /*
         * Active invoice that is not yet overdue.
         */
        return 'ISSUED';
    }
}