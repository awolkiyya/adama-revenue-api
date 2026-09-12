<?php

namespace App\Modules\PenaltyDiscount\Services;

use App\Models\Invoice;
use App\Models\PenaltyDiscountRequest;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PenaltyDiscountRequestService
{
    /**
     * Create a new penalty discount request.
     *
     * The Revenue Compliance Officer creates the request.
     *
     * The request is always created as DRAFT.
     */
    public function create(
        Invoice|string $invoice,
        float $requestedAmount,
        string $reason,
        string $createdBy,
    ): PenaltyDiscountRequest {
        return DB::transaction(function () use (
            $invoice,
            $requestedAmount,
            $reason,
            $createdBy,
        ) {
            $requestedAmount = $this->normalizeAmount(
                $requestedAmount
            );

            $reason = trim($reason);

            if ($requestedAmount <= 0) {
                throw ValidationException::withMessages([
                    'requested_amount' =>
                        'Requested discount amount must be greater than zero.',
                ]);
            }

            if ($reason === '') {
                throw ValidationException::withMessages([
                    'reason' =>
                        'A reason is required for a penalty discount request.',
                ]);
            }

            $invoiceModel = $this->resolveInvoice($invoice);

            $invoiceModel = Invoice::query()
                ->whereKey($invoiceModel->id)
                ->lockForUpdate()
                ->first();

            if (! $invoiceModel) {
                throw ValidationException::withMessages([
                    'invoice' => 'Invoice was not found.',
                ]);
            }

            $this->ensureInvoiceEligible($invoiceModel);

            $penalty = $this->currentPenalty(
                $invoiceModel
            );

            $remainingPenalty = $this->remainingPenalty(
                $invoiceModel
            );

            if ($penalty <= 0) {
                throw ValidationException::withMessages([
                    'requested_amount' =>
                        'This invoice has no penalty available for discount.',
                ]);
            }

            if ($remainingPenalty <= 0) {
                throw ValidationException::withMessages([
                    'requested_amount' =>
                        'The invoice penalty has already been fully discounted.',
                ]);
            }

            if ($requestedAmount > $remainingPenalty) {
                throw ValidationException::withMessages([
                    'requested_amount' => sprintf(
                        'Requested discount cannot exceed the remaining penalty of %.2f ETB.',
                        $remainingPenalty
                    ),
                ]);
            }

            $this->ensureNoActiveRequest(
                $invoiceModel
            );

            return PenaltyDiscountRequest::query()->create([
                'invoice_id' => $invoiceModel->id,
                'citizen_id' => $invoiceModel->citizen_id,

                'requested_amount' => $requestedAmount,
                'reason' => $reason,

                'status' =>
                    PenaltyDiscountRequest::STATUS_DRAFT,

                'created_by' => $createdBy,

                'submitted_at' => null,

                'decision' => null,
                'approved_amount' => null,
                'decision_reason' => null,
                'decided_by' => null,
                'decided_at' => null,

                'applied_to_invoice' => false,
                'applied_at' => null,
            ]);
        });
    }

    /**
     * Submit a DRAFT request for administrative decision.
     */
    public function submit(
        PenaltyDiscountRequest|string $request,
    ): PenaltyDiscountRequest {
        return DB::transaction(function () use ($request) {
            $requestModel = $this->lockRequest($request);

            if (
                $requestModel->status !==
                PenaltyDiscountRequest::STATUS_DRAFT
            ) {
                throw ValidationException::withMessages([
                    'status' => sprintf(
                        'Only DRAFT requests can be submitted. Current status is "%s".',
                        $requestModel->status
                    ),
                ]);
            }

            $this->ensureRequestAmountStillValid(
                $requestModel
            );

            $requestModel->update([
                'status' =>
                    PenaltyDiscountRequest::STATUS_SUBMITTED,

                'submitted_at' => now(),
            ]);

            return $requestModel->fresh([
                'invoice',
                'citizen',
                'creator',
                'decider',
            ]);
        });
    }

    /**
     * Make an administrative decision.
     *
     * Only a submitted request can be decided.
     *
     * APPROVED:
     *     approved_amount is required.
     *
     * REJECTED:
     *     approved_amount must be null/zero.
     */
    public function decide(
        PenaltyDiscountRequest|string $request,
        string $decision,
        ?float $approvedAmount,
        ?string $decisionReason,
        string $decidedBy,
    ): PenaltyDiscountRequest {
        return DB::transaction(function () use (
            $request,
            $decision,
            $approvedAmount,
            $decisionReason,
            $decidedBy,
        ) {
            $requestModel = $this->lockRequest(
                $request,
                [
                    'invoice',
                ]
            );

            if (
                $requestModel->status !==
                PenaltyDiscountRequest::STATUS_SUBMITTED
            ) {
                throw ValidationException::withMessages([
                    'status' => sprintf(
                        'Only SUBMITTED requests can be decided. Current status is "%s".',
                        $requestModel->status
                    ),
                ]);
            }

            $decision = strtoupper(
                trim($decision)
            );

            if (! in_array(
                $decision,
                [
                    PenaltyDiscountRequest::DECISION_APPROVED,
                    PenaltyDiscountRequest::DECISION_REJECTED,
                ],
                true
            )) {
                throw ValidationException::withMessages([
                    'decision' =>
                        'Decision must be APPROVED or REJECTED.',
                ]);
            }

            $decisionReason = $decisionReason !== null
                ? trim($decisionReason)
                : null;

            if ($decision === PenaltyDiscountRequest::DECISION_REJECTED) {
                if (
                    $decisionReason === null
                    || $decisionReason === ''
                ) {
                    throw ValidationException::withMessages([
                        'decision_reason' =>
                            'A decision reason is required when rejecting a request.',
                    ]);
                }

                $requestModel->update([
                    'status' =>
                        PenaltyDiscountRequest::STATUS_DECIDED,

                    'decision' =>
                        PenaltyDiscountRequest::DECISION_REJECTED,

                    'approved_amount' => null,

                    'decision_reason' =>
                        $decisionReason,

                    'decided_by' =>
                        $decidedBy,

                    'decided_at' =>
                        now(),

                    'applied_to_invoice' =>
                        false,

                    'applied_at' =>
                        null,
                ]);

                return $requestModel->fresh([
                    'invoice',
                    'citizen',
                    'creator',
                    'decider',
                ]);
            }

            $approvedAmount = $this->normalizeAmount(
                $approvedAmount ?? 0
            );

            if ($approvedAmount <= 0) {
                throw ValidationException::withMessages([
                    'approved_amount' =>
                        'Approved discount amount must be greater than zero.',
                ]);
            }

            $invoice = Invoice::query()
                ->whereKey($requestModel->invoice_id)
                ->lockForUpdate()
                ->first();

            if (! $invoice) {
                throw ValidationException::withMessages([
                    'invoice' =>
                        'The invoice associated with this request was not found.',
                ]);
            }

            $this->ensureInvoiceEligible(
                $invoice
            );

            $remainingPenalty = $this->remainingPenalty(
                $invoice
            );

            if ($remainingPenalty <= 0) {
                throw ValidationException::withMessages([
                    'approved_amount' =>
                        'There is no remaining penalty available for discount.',
                ]);
            }

            if ($approvedAmount > $remainingPenalty) {
                throw ValidationException::withMessages([
                    'approved_amount' => sprintf(
                        'Approved discount cannot exceed the remaining penalty of %.2f ETB.',
                        $remainingPenalty
                    ),
                ]);
            }

            /*
             * Prevent a second discount request from being approved
             * while another approved request has already consumed
             * part of the penalty.
             */
            $this->ensureNoOtherAppliedDiscount(
                $requestModel
            );

            $currentDiscount = $this->currentPenaltyDiscount(
                $invoice
            );

            $newPenaltyDiscount = round(
                $currentDiscount + $approvedAmount,
                2
            );

            $penalty = $this->currentPenalty(
                $invoice
            );

            $interest = $this->currentInterest(
                $invoice
            );

            $subtotal = $this->currentSubtotal(
                $invoice
            );

            $totalAmount = round(
                $subtotal
                + $penalty
                - $newPenaltyDiscount
                + $interest,
                2
            );

            $totalAmount = max(
                0,
                $totalAmount
            );

            $paidAmount = round(
                max(
                    0,
                    (float) $invoice->paid_amount
                ),
                2
            );

            $this->ensureNoOverpayment(
                $invoice,
                $totalAmount
            );

            $balanceDue = round(
                max(
                    0,
                    $totalAmount - $paidAmount
                ),
                2
            );

            $status = $this->resolveInvoiceStatus(
                $invoice,
                $paidAmount,
                $balanceDue
            );

            $paidAt = $balanceDue <= 0
                ? ($invoice->paid_at ?? now())
                : null;

            /*
             * Apply the approved discount to the invoice
             * atomically with the administrative decision.
             */
            $invoice->update([
                'penalty_discount_amount' =>
                    $newPenaltyDiscount,

                'total_amount' =>
                    $totalAmount,

                'balance_due' =>
                    $balanceDue,

                'status' =>
                    $status,

                'paid_at' =>
                    $paidAt,
            ]);

            $requestModel->update([
                'status' =>
                    PenaltyDiscountRequest::STATUS_DECIDED,

                'decision' =>
                    PenaltyDiscountRequest::DECISION_APPROVED,

                'approved_amount' =>
                    $approvedAmount,

                'decision_reason' =>
                    $decisionReason,

                'decided_by' =>
                    $decidedBy,

                'decided_at' =>
                    now(),

                'applied_to_invoice' =>
                    true,

                'applied_at' =>
                    now(),
            ]);

            return $requestModel->fresh([
                'invoice',
                'citizen',
                'creator',
                'decider',
            ]);
        });
    }

    /**
     * Cancel a DRAFT or SUBMITTED request.
     */
    public function cancel(
        PenaltyDiscountRequest|string $request,
    ): PenaltyDiscountRequest {
        return DB::transaction(function () use ($request) {
            $requestModel = $this->lockRequest($request);

            if (! in_array(
                $requestModel->status,
                [
                    PenaltyDiscountRequest::STATUS_DRAFT,
                    PenaltyDiscountRequest::STATUS_SUBMITTED,
                ],
                true
            )) {
                throw ValidationException::withMessages([
                    'status' => sprintf(
                        'Only DRAFT or SUBMITTED requests can be cancelled. Current status is "%s".',
                        $requestModel->status
                    ),
                ]);
            }

            $requestModel->update([
                'status' =>
                    PenaltyDiscountRequest::STATUS_CANCELLED,
            ]);

            return $requestModel->fresh([
                'invoice',
                'citizen',
                'creator',
                'decider',
            ]);
        });
    }

    /**
     * Resolve an invoice from model or UUID.
     */
    protected function resolveInvoice(
        Invoice|string $invoice
    ): Invoice {
        if ($invoice instanceof Invoice) {
            return $invoice;
        }

        $model = Invoice::query()->find($invoice);

        if (! $model) {
            throw ValidationException::withMessages([
                'invoice' => 'Invoice was not found.',
            ]);
        }

        return $model;
    }

    /**
     * Lock the request for a state-changing operation.
     */
    protected function lockRequest(
        PenaltyDiscountRequest|string $request,
        array $with = []
    ): PenaltyDiscountRequest {
        $id = $request instanceof PenaltyDiscountRequest
            ? $request->id
            : $request;

        $query = PenaltyDiscountRequest::query()
            ->whereKey($id)
            ->lockForUpdate();

        if ($with !== []) {
            $query->with($with);
        }

        $model = $query->first();

        if (! $model) {
            throw ValidationException::withMessages([
                'request' =>
                    'Penalty discount request was not found.',
            ]);
        }

        return $model;
    }

    /**
     * Ensure invoice is eligible for a penalty discount request.
     */
    protected function ensureInvoiceEligible(
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
                    'Penalty discount cannot be requested for an invoice with status "%s".',
                    $invoice->status
                ),
            ]);
        }
    }

    /**
     * Ensure there is no active request for the invoice.
     */
    protected function ensureNoActiveRequest(
        Invoice $invoice
    ): void {
        $exists = PenaltyDiscountRequest::query()
            ->where('invoice_id', $invoice->id)
            ->whereIn('status', [
                PenaltyDiscountRequest::STATUS_DRAFT,
                PenaltyDiscountRequest::STATUS_SUBMITTED,
            ])
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'invoice' =>
                    'This invoice already has an active penalty discount request.',
            ]);
        }
    }

    /**
     * Ensure another request has not already applied
     * a discount while this request was waiting for decision.
     */
    protected function ensureNoOtherAppliedDiscount(
        PenaltyDiscountRequest $request
    ): void {
        $exists = PenaltyDiscountRequest::query()
            ->where('invoice_id', $request->invoice_id)
            ->whereKeyNot($request->id)
            ->where('status', PenaltyDiscountRequest::STATUS_DECIDED)
            ->where('decision', PenaltyDiscountRequest::DECISION_APPROVED)
            ->where('applied_to_invoice', true)
            ->exists();

        /*
         * Multiple approved requests can technically be supported
         * if the remaining penalty is still available. Therefore,
         * this method intentionally does not reject such requests.
         *
         * The actual remaining-penalty check is authoritative.
         */
        unset($exists);
    }

    /**
     * Revalidate requested amount before submission.
     */
    protected function ensureRequestAmountStillValid(
        PenaltyDiscountRequest $request
    ): void {
        $invoice = Invoice::query()
            ->whereKey($request->invoice_id)
            ->lockForUpdate()
            ->first();

        if (! $invoice) {
            throw ValidationException::withMessages([
                'invoice' =>
                    'The invoice associated with this request was not found.',
            ]);
        }

        $this->ensureInvoiceEligible($invoice);

        $remainingPenalty = $this->remainingPenalty(
            $invoice
        );

        if (
            (float) $request->requested_amount
            > $remainingPenalty
        ) {
            throw ValidationException::withMessages([
                'requested_amount' => sprintf(
                    'Requested discount cannot exceed the current remaining penalty of %.2f ETB.',
                    $remainingPenalty
                ),
            ]);
        }
    }

    protected function currentSubtotal(
        Invoice $invoice
    ): float {
        return round(
            max(
                0,
                (float) $invoice->subtotal
            ),
            2
        );
    }

    protected function currentPenalty(
        Invoice $invoice
    ): float {
        return round(
            max(
                0,
                (float) $invoice->penalty_amount
            ),
            2
        );
    }

    protected function currentPenaltyDiscount(
        Invoice $invoice
    ): float {
        return round(
            max(
                0,
                (float) $invoice->penalty_discount_amount
            ),
            2
        );
    }

    protected function currentInterest(
        Invoice $invoice
    ): float {
        return round(
            max(
                0,
                (float) $invoice->interest_amount
            ),
            2
        );
    }

    protected function remainingPenalty(
        Invoice $invoice
    ): float {
        return round(
            max(
                0,
                $this->currentPenalty($invoice)
                - $this->currentPenaltyDiscount($invoice)
            ),
            2
        );
    }

    /**
     * Ensure an approved discount does not create an
     * overpayment when overpayment is disabled.
     */
    protected function ensureNoOverpayment(
        Invoice $invoice,
        float $totalAmount
    ): void {
        $allowOverpayment = (bool) (
            \App\Models\RevenueSetting::query()
                ->where('is_active', true)
                ->value('invoice_allow_overpayment')
            ?? false
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
                'approved_amount' => sprintf(
                    'The approved discount would reduce the invoice total below the amount already paid. Paid amount is %.2f ETB and the resulting invoice total would be %.2f ETB.',
                    $paidAmount,
                    $totalAmount
                ),
            ]);
        }
    }

    protected function resolveInvoiceStatus(
        Invoice $invoice,
        float $paidAmount,
        float $balanceDue
    ): string {
        if ($balanceDue <= 0) {
            return 'PAID';
        }

        if ($paidAmount > 0) {
            return 'PARTIALLY_PAID';
        }

        if (
            $invoice->due_date
            && Carbon::parse($invoice->due_date)
                ->startOfDay()
                ->lt(now()->startOfDay())
        ) {
            return 'OVERDUE';
        }

        return 'ISSUED';
    }

    protected function normalizeAmount(
        float $amount
    ): float {
        return round($amount, 2);
    }
}

