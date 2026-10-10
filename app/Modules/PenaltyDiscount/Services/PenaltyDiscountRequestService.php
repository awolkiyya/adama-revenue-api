<?php

namespace App\Modules\PenaltyDiscount\Services;

use App\Models\File;
use App\Models\Invoice;
use App\Models\PenaltyDiscountRequest;
use App\Models\RevenueSetting;
use App\Services\Storage\StorageService;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class PenaltyDiscountRequestService
{
    /**
     * Supporting-document storage folder.
     */
    private const SUPPORTING_DOCUMENT_FOLDER = 'penalty-discount-requests';

    /**
     * File category used by StorageService.
     */
    private const SUPPORTING_DOCUMENT_CATEGORY =
        'PENALTY_DISCOUNT_SUPPORTING_DOCUMENT';

    public function __construct(
        private readonly StorageService $storageService,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | SUMMARY
    |--------------------------------------------------------------------------
    */

    /**
     * Get penalty discount request summary statistics.
     *
     * The approved amount includes both APPROVED and APPLIED requests.
     */
    public function summary(): array
    {
        $statusCounts = PenaltyDiscountRequest::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $approvedAmount = PenaltyDiscountRequest::query()
            ->whereIn('status', [
                PenaltyDiscountRequest::STATUS_APPROVED,
                PenaltyDiscountRequest::STATUS_APPLIED,
            ])
            ->sum('approved_amount');

        return [
            'total_requests' => (int) $statusCounts->sum(),

            'pending_decision' => (int) (
                $statusCounts[
                    PenaltyDiscountRequest::STATUS_SUBMITTED
                ] ?? 0
            ),

            'approved_requests' => (int) (
                $statusCounts[
                    PenaltyDiscountRequest::STATUS_APPROVED
                ] ?? 0
            ),

            'applied_requests' => (int) (
                $statusCounts[
                    PenaltyDiscountRequest::STATUS_APPLIED
                ] ?? 0
            ),

            'approved_amount' => round(
                (float) $approvedAmount,
                2
            ),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | CREATE
    |--------------------------------------------------------------------------
    */

    /**
     * Create a DRAFT penalty discount request.
     *
     * The supporting document is optional.
     */
    public function create(
        Invoice|string $invoice,
        float $requestedAmount,
        string $reason,
        string $createdBy,
        ?UploadedFile $supportingFile = null,
    ): PenaltyDiscountRequest {
        return DB::transaction(function () use (
            $invoice,
            $requestedAmount,
            $reason,
            $createdBy,
            $supportingFile,
        ) {
            $invoiceId = $invoice instanceof Invoice
                ? $invoice->id
                : $invoice;

            $invoiceModel = Invoice::query()
                ->whereKey($invoiceId)
                ->lockForUpdate()
                ->first();

            if (! $invoiceModel) {
                $this->validationError(
                    'invoice_id',
                    'The selected invoice was not found.'
                );
            }

            $requestedAmount = $this->normalizeAmount(
                $requestedAmount
            );

            $reason = trim($reason);

            $this->validatePositiveAmount(
                $requestedAmount,
                'requested_amount',
                'Requested discount amount'
            );

            $this->validateReason($reason, 'reason');

            $this->ensureInvoiceEligible($invoiceModel);

            $this->ensureAmountWithinRemainingPenalty(
                $invoiceModel,
                $requestedAmount,
                'requested_amount'
            );

            $this->ensureNoActiveRequest($invoiceModel);

            $requestModel = PenaltyDiscountRequest::query()->create([
                'invoice_id' => $invoiceModel->id,
                'citizen_id' => $invoiceModel->citizen_id,
                'requested_amount' => $requestedAmount,
                'reason' => $reason,

                'status' => PenaltyDiscountRequest::STATUS_DRAFT,

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

            if ($supportingFile !== null) {
                $this->attachSupportingFile(
                    $requestModel,
                    $supportingFile,
                    $createdBy
                );
            }

            return $this->freshRequest($requestModel);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | UPDATE
    |--------------------------------------------------------------------------
    */

    /**
     * Update a DRAFT request.
     *
     * If a new supporting document is supplied, it is attached to
     * the request. If no document is supplied, existing files remain.
     */
    public function update(
        PenaltyDiscountRequest|string $request,
        Invoice|string $invoice,
        float $requestedAmount,
        string $reason,
        ?UploadedFile $supportingFile = null,
        ?string $updatedBy = null,
    ): PenaltyDiscountRequest {
        return DB::transaction(function () use (
            $request,
            $invoice,
            $requestedAmount,
            $reason,
            $supportingFile,
            $updatedBy,
        ) {
            $requestModel = $this->lockRequest($request);

            if (
                $requestModel->status
                !== PenaltyDiscountRequest::STATUS_DRAFT
            ) {
                $this->validationError(
                    'status',
                    'Only DRAFT requests can be updated.'
                );
            }

            if ($requestModel->isApplied()) {
                $this->validationError(
                    'status',
                    'An applied discount request cannot be updated.'
                );
            }

            $invoiceId = $invoice instanceof Invoice
                ? $invoice->id
                : $invoice;

            $invoiceModel = Invoice::query()
                ->whereKey($invoiceId)
                ->lockForUpdate()
                ->first();

            if (! $invoiceModel) {
                $this->validationError(
                    'invoice_id',
                    'The selected invoice was not found.'
                );
            }

            $requestedAmount = $this->normalizeAmount(
                $requestedAmount
            );

            $reason = trim($reason);

            $this->validatePositiveAmount(
                $requestedAmount,
                'requested_amount',
                'Requested discount amount'
            );

            $this->validateReason($reason, 'reason');

            $this->ensureInvoiceEligible($invoiceModel);

            $this->ensureAmountWithinRemainingPenalty(
                $invoiceModel,
                $requestedAmount,
                'requested_amount'
            );

            $this->ensureNoActiveRequest(
                $invoiceModel,
                $requestModel->id
            );

            $requestModel->update([
                'invoice_id' => $invoiceModel->id,
                'citizen_id' => $invoiceModel->citizen_id,
                'requested_amount' => $requestedAmount,
                'reason' => $reason,
            ]);

            if ($supportingFile !== null) {
                $this->attachSupportingFile(
                    $requestModel,
                    $supportingFile,
                    $updatedBy
                        ?? (string) $requestModel->created_by
                );
            }

            return $this->freshRequest($requestModel);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | SUPPORTING DOCUMENTS
    |--------------------------------------------------------------------------
    */

    /**
     * Store and associate a supporting document.
     *
     * StorageService owns physical storage and File registry creation.
     * This service associates the resulting File record with the request.
     *
     * Existing documents are retained when another file is uploaded.
     */
    protected function attachSupportingFile(
        PenaltyDiscountRequest $request,
        UploadedFile $uploadedFile,
        string $uploadedBy,
    ): File {
        $file = null;

        try {
            $file = $this->storageService->upload(
                $uploadedFile,
                self::SUPPORTING_DOCUMENT_FOLDER,
                $uploadedBy,
                self::SUPPORTING_DOCUMENT_CATEGORY,
                'private',
            );

            $file = $this->storageService->attachToModel(
                $file,
                $request
            );

            /*
             * The File model must have a `collection` column
             * for supportingFiles() to filter by this value.
             */
            $file->collection =
                PenaltyDiscountRequest::FILE_COLLECTION_SUPPORTING_DOCUMENTS;

            $file->save();

            return $file->refresh();
        } catch (Throwable $exception) {
            /*
             * If attachment fails, remove the newly created file
             * where possible. Do not remove existing attachments.
             *
             * Storage cleanup and database transactions are separate
             * resources; cleanup failures are reported independently.
             */
            if ($file !== null) {
                try {
                    $this->storageService->delete($file);
                } catch (Throwable $cleanupException) {
                    report($cleanupException);
                }
            }

            throw $exception;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | SUBMIT
    |--------------------------------------------------------------------------
    */

    /**
     * Submit a DRAFT request for review.
     */
    public function submit(
        PenaltyDiscountRequest|string $request,
    ): PenaltyDiscountRequest {
        return DB::transaction(function () use ($request) {
            $requestModel = $this->lockRequest($request);

            if (
                $requestModel->status
                !== PenaltyDiscountRequest::STATUS_DRAFT
            ) {
                $this->validationError(
                    'status',
                    'Only DRAFT requests can be submitted.'
                );
            }

            $invoice = Invoice::query()
                ->whereKey($requestModel->invoice_id)
                ->lockForUpdate()
                ->first();

            if (! $invoice) {
                $this->validationError(
                    'invoice',
                    'The invoice associated with this request was not found.'
                );
            }

            $this->ensureInvoiceEligible($invoice);

            $this->ensureAmountWithinRemainingPenalty(
                $invoice,
                (float) $requestModel->requested_amount,
                'requested_amount'
            );

            $this->ensureNoActiveRequest($invoice, $requestModel->id);

            $requestModel->update([
                'status' => PenaltyDiscountRequest::STATUS_SUBMITTED,
                'submitted_at' => now(),
            ]);

            return $this->freshRequest($requestModel);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | DECIDE
    |--------------------------------------------------------------------------
    */

    /**
     * Approve or reject a submitted request.
     *
     * This records the decision only. The invoice changes only in apply().
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
            $requestModel = $this->lockRequest($request);

            if (
                $requestModel->status
                !== PenaltyDiscountRequest::STATUS_SUBMITTED
            ) {
                $this->validationError(
                    'status',
                    'Only SUBMITTED requests can be decided.'
                );
            }

            if ($requestModel->isApplied()) {
                $this->validationError(
                    'status',
                    'An applied discount request cannot be decided again.'
                );
            }

            $decision = strtoupper(trim($decision));

            if (! in_array($decision, [
                PenaltyDiscountRequest::DECISION_APPROVED,
                PenaltyDiscountRequest::DECISION_REJECTED,
            ], true)) {
                $this->validationError(
                    'decision',
                    'Decision must be APPROVED or REJECTED.'
                );
            }

            $decisionReason = $decisionReason !== null
                ? trim($decisionReason)
                : null;

            if (
                $decision === PenaltyDiscountRequest::DECISION_REJECTED
            ) {
                $this->validateReason(
                    $decisionReason ?? '',
                    'decision_reason'
                );

                $requestModel->update([
                    'status' => PenaltyDiscountRequest::STATUS_REJECTED,
                    'decision' => PenaltyDiscountRequest::DECISION_REJECTED,
                    'approved_amount' => null,
                    'decision_reason' => $decisionReason,
                    'decided_by' => $decidedBy,
                    'decided_at' => now(),
                    'applied_to_invoice' => false,
                    'applied_at' => null,
                ]);

                return $this->freshRequest($requestModel);
            }

            $approvedAmount = $this->normalizeAmount(
                $approvedAmount ?? 0
            );

            $this->validatePositiveAmount(
                $approvedAmount,
                'approved_amount',
                'Approved discount amount'
            );

            if (
                $approvedAmount
                > (float) $requestModel->requested_amount
            ) {
                $this->validationError(
                    'approved_amount',
                    'The approved discount cannot exceed the requested discount amount.'
                );
            }

            $invoice = Invoice::query()
                ->whereKey($requestModel->invoice_id)
                ->lockForUpdate()
                ->first();

            if (! $invoice) {
                $this->validationError(
                    'invoice',
                    'The invoice associated with this request was not found.'
                );
            }

            $this->ensureInvoiceEligible($invoice);

            $this->ensureAmountWithinRemainingPenalty(
                $invoice,
                $approvedAmount,
                'approved_amount'
            );

            $requestModel->update([
                'status' => PenaltyDiscountRequest::STATUS_APPROVED,
                'decision' => PenaltyDiscountRequest::DECISION_APPROVED,
                'approved_amount' => $approvedAmount,
                'decision_reason' => $decisionReason,
                'decided_by' => $decidedBy,
                'decided_at' => now(),
                'applied_to_invoice' => false,
                'applied_at' => null,
            ]);

            return $this->freshRequest($requestModel);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | APPLY
    |--------------------------------------------------------------------------
    */

    /**
     * Apply an approved discount to the invoice.
     */
    public function apply(
        PenaltyDiscountRequest|string $request,
        string $appliedBy,
    ): PenaltyDiscountRequest {
        return DB::transaction(function () use ($request, $appliedBy) {
            $requestModel = $this->lockRequest($request);

            if (
                $requestModel->status
                !== PenaltyDiscountRequest::STATUS_APPROVED
            ) {
                $this->validationError(
                    'status',
                    'Only APPROVED requests can be applied.'
                );
            }

            if ($requestModel->isApplied()) {
                $this->validationError(
                    'status',
                    'This discount request has already been applied.'
                );
            }

            $approvedAmount = $this->normalizeAmount(
                (float) $requestModel->approved_amount
            );

            $this->validatePositiveAmount(
                $approvedAmount,
                'approved_amount',
                'Approved discount amount'
            );

            $invoice = Invoice::query()
                ->whereKey($requestModel->invoice_id)
                ->lockForUpdate()
                ->first();

            if (! $invoice) {
                $this->validationError(
                    'invoice',
                    'The invoice associated with this request was not found.'
                );
            }

            $this->ensureInvoiceEligible($invoice);

            $this->ensureAmountWithinRemainingPenalty(
                $invoice,
                $approvedAmount,
                'approved_amount'
            );

            $currentDiscount = $this->currentPenaltyDiscount($invoice);

            $newPenaltyDiscount = round(
                $currentDiscount + $approvedAmount,
                2
            );

            $newTotalAmount = round(
                $this->currentSubtotal($invoice)
                + $this->currentPenalty($invoice)
                - $newPenaltyDiscount
                + $this->currentInterest($invoice),
                2
            );

            $newTotalAmount = max(0, $newTotalAmount);

            $paidAmount = round(
                max(0, (float) $invoice->paid_amount),
                2
            );

            $this->ensureNoOverpayment($invoice, $newTotalAmount);

            $balanceDue = round(
                max(0, $newTotalAmount - $paidAmount),
                2
            );

            $invoiceStatus = $this->resolveInvoiceStatus(
                $invoice,
                $paidAmount,
                $balanceDue
            );

            $invoice->update([
                'penalty_discount_amount' => $newPenaltyDiscount,
                'total_amount' => $newTotalAmount,
                'balance_due' => $balanceDue,
                'status' => $invoiceStatus,
                'paid_at' => $balanceDue <= 0
                    ? ($invoice->paid_at ?? now())
                    : null,
            ]);

            $requestModel->update([
                'status' => PenaltyDiscountRequest::STATUS_APPLIED,
                'applied_to_invoice' => true,
                'applied_at' => now(),
            ]);

            return $this->freshRequest($requestModel);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | CANCEL
    |--------------------------------------------------------------------------
    */

    /**
     * Cancel a DRAFT, SUBMITTED, or unapplied APPROVED request.
     */
    public function cancel(
        PenaltyDiscountRequest|string $request,
    ): PenaltyDiscountRequest {
        return DB::transaction(function () use ($request) {
            $requestModel = $this->lockRequest($request);

            if (! in_array($requestModel->status, [
                PenaltyDiscountRequest::STATUS_DRAFT,
                PenaltyDiscountRequest::STATUS_SUBMITTED,
                PenaltyDiscountRequest::STATUS_APPROVED,
            ], true)) {
                $this->validationError(
                    'status',
                    'Only DRAFT, SUBMITTED, or unapplied APPROVED requests can be cancelled.'
                );
            }

            if ($requestModel->isApplied()) {
                $this->validationError(
                    'status',
                    'An applied discount request cannot be cancelled.'
                );
            }

            $requestModel->update([
                'status' => PenaltyDiscountRequest::STATUS_CANCELLED,
            ]);

            return $this->freshRequest($requestModel);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | REQUEST RETRIEVAL
    |--------------------------------------------------------------------------
    */

    /**
     * Find and lock a request for safe state transitions.
     */
    protected function lockRequest(
        PenaltyDiscountRequest|string $request,
    ): PenaltyDiscountRequest {
        $id = $request instanceof PenaltyDiscountRequest
            ? $request->id
            : $request;

        $model = PenaltyDiscountRequest::query()
            ->whereKey($id)
            ->lockForUpdate()
            ->first();

        if (! $model) {
            $this->validationError(
                'request',
                'Penalty discount request was not found.'
            );
        }

        return $model;
    }

    /**
     * Refresh a request with its primary relationships and documents.
     */
    protected function freshRequest(
        PenaltyDiscountRequest $request,
    ): PenaltyDiscountRequest {
        return $request->fresh([
            'invoice',
            'citizen',
            'creator',
            'decider',
            'supportingFiles',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | INVOICE VALIDATION
    |--------------------------------------------------------------------------
    */

    protected function ensureInvoiceEligible(Invoice $invoice): void
    {
        if (! in_array($invoice->status, [
            'ISSUED',
            'PARTIALLY_PAID',
            'OVERDUE',
        ], true)) {
            $this->validationError(
                'invoice',
                sprintf(
                    'Penalty discounts are not allowed for an invoice with status "%s".',
                    $invoice->status
                )
            );
        }
    }

    /**
     * Prevent multiple active requests for the same invoice.
     */
    protected function ensureNoActiveRequest(
        Invoice $invoice,
        ?string $exceptRequestId = null,
    ): void {
        $query = PenaltyDiscountRequest::query()
            ->where('invoice_id', $invoice->id)
            ->whereIn('status', [
                PenaltyDiscountRequest::STATUS_DRAFT,
                PenaltyDiscountRequest::STATUS_SUBMITTED,
                PenaltyDiscountRequest::STATUS_APPROVED,
            ]);

        if ($exceptRequestId !== null) {
            $query->where('id', '!=', $exceptRequestId);
        }

        if ($query->exists()) {
            $this->validationError(
                'invoice',
                'This invoice already has an active penalty discount request.'
            );
        }
    }

    /**
     * Ensure a discount does not exceed the remaining penalty.
     */
    protected function ensureAmountWithinRemainingPenalty(
        Invoice $invoice,
        float $amount,
        string $field,
    ): void {
        $remainingPenalty = $this->remainingPenalty($invoice);

        if ($remainingPenalty <= 0) {
            $this->validationError(
                $field,
                'This invoice has no remaining penalty available for discount.'
            );
        }

        if ($amount > $remainingPenalty) {
            $this->validationError(
                $field,
                sprintf(
                    'The discount cannot exceed the remaining penalty of %.2f ETB.',
                    $remainingPenalty
                )
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | FIELD VALIDATION
    |--------------------------------------------------------------------------
    */

    protected function validatePositiveAmount(
        float $amount,
        string $field,
        string $label,
    ): void {
        if ($amount <= 0) {
            $this->validationError(
                $field,
                $label . ' must be greater than zero.'
            );
        }
    }

    protected function validateReason(
        string $reason,
        string $field,
    ): void {
        if (trim($reason) === '') {
            $this->validationError(
                $field,
                'A reason is required.'
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | INVOICE FINANCIAL VALUES
    |--------------------------------------------------------------------------
    */

    protected function currentSubtotal(Invoice $invoice): float
    {
        return round(max(0, (float) $invoice->subtotal), 2);
    }

    protected function currentPenalty(Invoice $invoice): float
    {
        return round(max(0, (float) $invoice->penalty_amount), 2);
    }

    protected function currentPenaltyDiscount(Invoice $invoice): float
    {
        return round(
            max(0, (float) $invoice->penalty_discount_amount),
            2
        );
    }

    protected function currentInterest(Invoice $invoice): float
    {
        return round(max(0, (float) $invoice->interest_amount), 2);
    }

    protected function remainingPenalty(Invoice $invoice): float
    {
        return round(
            max(
                0,
                $this->currentPenalty($invoice)
                - $this->currentPenaltyDiscount($invoice)
            ),
            2
        );
    }

    /*
    |--------------------------------------------------------------------------
    | OVERPAYMENT VALIDATION
    |--------------------------------------------------------------------------
    */

    protected function ensureNoOverpayment(
        Invoice $invoice,
        float $newTotalAmount,
    ): void {
        $allowOverpayment = (bool) (
            RevenueSetting::query()
                ->where('is_active', true)
                ->value('invoice_allow_overpayment')
            ?? false
        );

        if ($allowOverpayment) {
            return;
        }

        $paidAmount = round(
            max(0, (float) $invoice->paid_amount),
            2
        );

        if ($paidAmount > $newTotalAmount) {
            $this->validationError(
                'approved_amount',
                sprintf(
                    'The discount would reduce the invoice total below the amount already paid. Paid: %.2f ETB; resulting total: %.2f ETB.',
                    $paidAmount,
                    $newTotalAmount
                )
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | INVOICE STATUS
    |--------------------------------------------------------------------------
    */

    protected function resolveInvoiceStatus(
        Invoice $invoice,
        float $paidAmount,
        float $balanceDue,
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

    /*
    |--------------------------------------------------------------------------
    | AMOUNT NORMALIZATION
    |--------------------------------------------------------------------------
    */

    protected function normalizeAmount(float $amount): float
    {
        return round($amount, 2);
    }

    /*
    |--------------------------------------------------------------------------
    | VALIDATION EXCEPTION
    |--------------------------------------------------------------------------
    */

    protected function validationError(
        string $field,
        string $message,
    ): never {
        throw ValidationException::withMessages([
            $field => $message,
        ]);
    }
}
