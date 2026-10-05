<?php

namespace App\Modules\Payment\Services;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\CashPaymentDetail;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\DocumentSequenceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CashPaymentService
{
    /**
     * Monetary precision used throughout the payment domain.
     */
    protected const MONEY_SCALE = 4;

    /**
     * Invoices that are allowed to receive payments.
     *
     * DRAFT invoices must first be officially issued.
     *
     * PARTIALLY_PAID and OVERDUE invoices may continue receiving
     * partial payments.
     */
    protected const PAYABLE_INVOICE_STATUSES = [
        'ISSUED',
        'PARTIALLY_PAID',
        'OVERDUE',
    ];

    public function __construct(
        protected DocumentSequenceService $documentSequenceService,
        protected PaymentReceiptService $receiptService,
    ) {
    }

    /**
     * Record a new cash payment.
     *
     * Lifecycle:
     *
     *     Invoice
     *         ↓
     *     Payment::PENDING
     *         ↓
     *     CashPaymentDetail
     *
     * No invoice financial balance is changed here.
     *
     * No receipt is created while the payment is PENDING.
     */
    public function record(
        User $user,
        array $data,
        ?string $requestId = null,
    ): Payment {
        return DB::transaction(function () use (
            $user,
            $data,
            $requestId
        ): Payment {
            /*
             * ---------------------------------------------------------
             * 1. Lock and load invoice
             * ---------------------------------------------------------
             */
            $invoice = Invoice::query()
                ->with('citizen')
                ->whereKey($data['invoice_id'] ?? null)
                ->lockForUpdate()
                ->first();

            if (!$invoice) {
                throw ValidationException::withMessages([
                    'invoice_id' => [
                        'The selected invoice does not exist.',
                    ],
                ]);
            }

            /*
             * ---------------------------------------------------------
             * 2. Validate invoice
             * ---------------------------------------------------------
             */
            $this->validateInvoiceForPayment($invoice);

            /*
             * ---------------------------------------------------------
             * 3. Prevent duplicate pending cash payment
             * ---------------------------------------------------------
             *
             * Multiple COMPLETED cash payments are allowed because
             * partial payments are supported.
             *
             * Only one PENDING cash payment is allowed per invoice.
             */
            $pendingPayment = Payment::query()
                ->where('invoice_id', $invoice->id)
                ->where(
                    'payment_method',
                    PaymentMethod::CASH->value
                )
                ->where(
                    'status',
                    PaymentStatus::PENDING->value
                )
                ->lockForUpdate()
                ->first();

            if ($pendingPayment) {
                throw ValidationException::withMessages([
                    'invoice_id' => [
                        'This invoice already has a pending cash payment.',
                    ],
                ]);
            }

            /*
             * ---------------------------------------------------------
             * 4. Normalize payment amount
             * ---------------------------------------------------------
             */
            $amount = $this->normalizeAmount(
                $data['amount'] ?? null
            );

            /*
             * ---------------------------------------------------------
             * 5. Calculate authoritative outstanding balance
             * ---------------------------------------------------------
             */
            $outstanding = $this->calculateOutstandingAmount(
                $invoice
            );

            if (
                bccomp(
                    $amount,
                    $outstanding,
                    self::MONEY_SCALE
                ) > 0
            ) {
                throw ValidationException::withMessages([
                    'amount' => [
                        'The payment amount cannot exceed the outstanding invoice balance.',
                    ],
                ]);
            }

            /*
             * ---------------------------------------------------------
             * 6. Generate payment identifiers
             * ---------------------------------------------------------
             */
            $paymentNumber = $this->documentSequenceService->generate(
                sequenceType: 'payment',
            );

            $transactionReference =
                $this->generateTransactionReference();

            /*
             * ---------------------------------------------------------
             * 7. Create common payment
             * ---------------------------------------------------------
             */
            $payment = new Payment();

            $payment->payment_number =
                $paymentNumber;

            $payment->invoice_id =
                $invoice->id;

            /*
             * Never trust citizen_id supplied by the frontend.
             */
            $payment->citizen_id =
                $invoice->citizen_id;

            /*
             * Payment method is controlled by this service.
             */
            $payment->payment_method =
                PaymentMethod::CASH;

            /*
             * Payment source is controlled by the backend.
             */
            $payment->payment_source =
                'OFFICE_RECORDED';

            /*
             * All newly recorded cash payments start as PENDING.
             */
            $payment->status =
                PaymentStatus::PENDING;

            $payment->transaction_reference =
                $transactionReference;

            $payment->amount =
                $amount;

            $payment->currency =
                $invoice->currency ?? 'ETB';

            /*
             * User who records/processes the payment.
             */
            $payment->processed_by =
                $user->id;

            /*
             * Payer information is derived from the invoice citizen.
             */
            $payment->payer_name =
                $invoice->citizen?->name;

            $payment->payer_email =
                $invoice->citizen?->email;

            $payment->payer_phone =
                $invoice->citizen?->phone;

            /*
             * New payment has no failure reason.
             */
            $payment->failure_reason =
                null;

            /*
             * Metadata may contain controlled operational data.
             */
            $payment->metadata =
                $data['metadata'] ?? null;

            $payment->save();

            /*
             * ---------------------------------------------------------
             * 8. Create cash-specific details
             * ---------------------------------------------------------
             */
            $cashDetails = new CashPaymentDetail();

            $cashDetails->payment_id =
                $payment->id;

            /*
             * The authenticated user receiving/recording the cash
             * is stored as received_by.
             */
            $cashDetails->received_by =
                $user->id;

            /*
             * Cashier session remains nullable until the
             * cashier-session domain is implemented.
             */
            $cashDetails->cashier_session_id =
                null;

            /*
             * Physical cash receipt time.
             */
            $cashDetails->cash_received_at =
                now();

            $cashDetails->notes =
                $data['notes'] ?? null;

            $cashDetails->save();

            /*
             * ---------------------------------------------------------
             * 9. Audit log
             * ---------------------------------------------------------
             */
            Log::info(
                'Cash payment recorded.',
                [
                    'request_id' =>
                        $requestId,

                    'payment_id' =>
                        $payment->id,

                    'payment_number' =>
                        $payment->payment_number,

                    'transaction_reference' =>
                        $payment->transaction_reference,

                    'invoice_id' =>
                        $invoice->id,

                    'amount' =>
                        $payment->amount,

                    'currency' =>
                        $payment->currency,

                    'payment_method' =>
                        PaymentMethod::CASH->value,

                    'payment_source' =>
                        $payment->payment_source,

                    'status' =>
                        PaymentStatus::PENDING->value,

                    'processed_by' =>
                        $user->id,

                    'received_by' =>
                        $user->id,
                ]
            );

            /*
             * ---------------------------------------------------------
             * 10. Return fully loaded payment
             * ---------------------------------------------------------
             */
            return $payment->fresh([
                'invoice',
                'citizen',
                'cashDetails.receivedBy',
                'processedBy',
                'verifiedBy',
                'receipt.issuedBy',
                'files',
            ]);
        });
    }

    /**
     * Complete a pending cash payment.
     *
     * Lifecycle:
     *
     *     PENDING
     *         ↓
     *     COMPLETED
     *         ↓
     *     Receipt created
     *         ↓
     *     Invoice recalculated
     *
     * Payment completion, receipt creation, and invoice update
     * happen inside the same database transaction.
     */
    public function complete(
        string $paymentId,
        User $user,
        ?string $requestId = null,
    ): Payment {
        return DB::transaction(function () use (
            $paymentId,
            $user,
            $requestId
        ): Payment {
            /*
             * ---------------------------------------------------------
             * 1. Find payment reference
             * ---------------------------------------------------------
             *
             * We need invoice_id before obtaining the invoice lock.
             *
             * Do not lock the payment yet.
             */
            $paymentReference = Payment::query()
                ->whereKey($paymentId)
                ->first();

            if (!$paymentReference) {
                throw (new ModelNotFoundException())
                    ->setModel(
                        Payment::class,
                        [$paymentId]
                    );
            }

            /*
             * ---------------------------------------------------------
             * 2. Validate payment method
             * ---------------------------------------------------------
             */
            $this->validateCashPayment(
                $paymentReference
            );

            /*
             * ---------------------------------------------------------
             * 3. Lock invoice FIRST
             * ---------------------------------------------------------
             *
             * record():
             *
             *     Invoice → Payment
             *
             * complete():
             *
             *     Invoice → Payment
             */
            $invoice = Invoice::query()
                ->whereKey($paymentReference->invoice_id)
                ->lockForUpdate()
                ->first();

            if (!$invoice) {
                throw ValidationException::withMessages([
                    'invoice' => [
                        'The invoice associated with this payment does not exist.',
                    ],
                ]);
            }

            /*
             * ---------------------------------------------------------
             * 4. Lock payment SECOND
             * ---------------------------------------------------------
             */
            $payment = Payment::query()
                ->with('cashDetails')
                ->whereKey($paymentId)
                ->lockForUpdate()
                ->first();

            if (!$payment) {
                throw (new ModelNotFoundException())
                    ->setModel(
                        Payment::class,
                        [$paymentId]
                    );
            }

            /*
             * ---------------------------------------------------------
             * 5. Validate payment method again
             * ---------------------------------------------------------
             */
            $this->validateCashPayment(
                $payment
            );

            /*
             * ---------------------------------------------------------
             * 6. Idempotent completion
             * ---------------------------------------------------------
             *
             * If the payment is already completed, do not:
             *
             * - change it again
             * - create another receipt
             * - apply the payment again
             */
            if ($this->isCompleted($payment)) {
                return $payment->fresh([
                    'invoice',
                    'citizen',
                    'cashDetails.receivedBy',
                    'processedBy',
                    'verifiedBy',
                    'receipt.issuedBy',
                    'files',
                ]);
            }

            /*
             * ---------------------------------------------------------
             * 7. Only PENDING payments can be completed
             * ---------------------------------------------------------
             */
            if (!$this->isPending($payment)) {
                throw ValidationException::withMessages([
                    'payment' => [
                        'Only pending cash payments can be completed.',
                    ],
                ]);
            }

            /*
             * ---------------------------------------------------------
             * 8. Validate invoice again
             * ---------------------------------------------------------
             */
            $this->validateInvoiceForPayment(
                $invoice
            );

            /*
             * ---------------------------------------------------------
             * 9. Cash details must exist
             * ---------------------------------------------------------
             */
            $cashDetails = $payment->cashDetails;

            if (!$cashDetails) {
                throw ValidationException::withMessages([
                    'payment' => [
                        'Cash payment details were not found.',
                    ],
                ]);
            }

            /*
             * ---------------------------------------------------------
             * 10. Normalize payment amount
             * ---------------------------------------------------------
             */
            $paymentAmount = $this->normalizeAmount(
                $payment->amount
            );

            /*
             * ---------------------------------------------------------
             * 11. Recalculate authoritative outstanding balance
             * ---------------------------------------------------------
             */
            $outstanding = $this->calculateOutstandingAmount(
                $invoice
            );

            if (
                bccomp(
                    $paymentAmount,
                    $outstanding,
                    self::MONEY_SCALE
                ) > 0
            ) {
                throw ValidationException::withMessages([
                    'payment' => [
                        'The payment amount now exceeds the outstanding invoice balance.',
                    ],
                ]);
            }

            /*
             * ---------------------------------------------------------
             * 12. Complete payment
             * ---------------------------------------------------------
             */
            $payment->status =
                PaymentStatus::COMPLETED;

            $payment->verified_by =
                $user->id;

            $payment->verified_at =
                now();

            $payment->failure_reason =
                null;

            $payment->save();

            /*
             * ---------------------------------------------------------
             * 13. Ensure physical cash timestamp exists
             * ---------------------------------------------------------
             */
            if (!$cashDetails->cash_received_at) {
                $cashDetails->cash_received_at =
                    now();

                $cashDetails->save();
            }

            /*
             * ---------------------------------------------------------
             * 14. Create official receipt
             * ---------------------------------------------------------
             *
             * IMPORTANT:
             *
             * PaymentReceiptService::create() requires both:
             *
             *     Payment
             *     User
             *
             * issued_by is populated using the authenticated user.
             */
            $receipt = $this->receiptService->create(
                payment: $payment,
                user: $user,
            );

            /*
             * ---------------------------------------------------------
             * 15. Recalculate invoice financial state
             * ---------------------------------------------------------
             */
            $this->applyPaymentToInvoice(
                invoice: $invoice,
            );

            /*
             * ---------------------------------------------------------
             * 16. Audit log
             * ---------------------------------------------------------
             */
            Log::info(
                'Cash payment completed.',
                [
                    'request_id' =>
                        $requestId,

                    'payment_id' =>
                        $payment->id,

                    'payment_number' =>
                        $payment->payment_number,

                    'transaction_reference' =>
                        $payment->transaction_reference,

                    'receipt_id' =>
                        $receipt->id,

                    'receipt_number' =>
                        $receipt->receipt_number,

                    'invoice_id' =>
                        $invoice->id,

                    'amount' =>
                        $paymentAmount,

                    'currency' =>
                        $payment->currency,

                    'payment_method' =>
                        PaymentMethod::CASH->value,

                    'payment_source' =>
                        $payment->payment_source,

                    'status' =>
                        PaymentStatus::COMPLETED->value,

                    'processed_by' =>
                        $payment->processed_by,

                    'verified_by' =>
                        $user->id,

                    'invoice_paid_amount' =>
                        $invoice->paid_amount,

                    'invoice_balance_due' =>
                        $invoice->balance_due,

                    'invoice_status' =>
                        $invoice->status?->value
                        ?? $invoice->status,
                ]
            );

            /*
             * ---------------------------------------------------------
             * 17. Return fully loaded payment
             * ---------------------------------------------------------
             */
            return $payment->fresh([
                'invoice',
                'citizen',
                'cashDetails.receivedBy',
                'processedBy',
                'verifiedBy',
                'receipt.issuedBy',
                'files',
            ]);
        });
    }

    /**
     * Find a cash payment.
     */
    public function find(
        string $paymentId,
        User $user,
    ): Payment {
        $payment = Payment::query()
            ->with([
                'invoice',
                'citizen',
                'cashDetails.receivedBy',
                'processedBy',
                'verifiedBy',
                'receipt.issuedBy',
                'files',
            ])
            ->whereKey($paymentId)
            ->first();

        if (!$payment) {
            throw (new ModelNotFoundException())
                ->setModel(
                    Payment::class,
                    [$paymentId]
                );
        }

        $this->validateCashPayment(
            $payment
        );

        $this->authorizeView(
            payment: $payment,
            user: $user,
        );

        return $payment;
    }

    /**
     * Validate invoice payment eligibility.
     */
    protected function validateInvoiceForPayment(
        Invoice $invoice,
    ): void {
        $status = $this->invoiceStatus(
            $invoice
        );

        if (
            !in_array(
                $status,
                self::PAYABLE_INVOICE_STATUSES,
                true
            )
        ) {
            if ($status === 'DRAFT') {
                throw ValidationException::withMessages([
                    'invoice_id' => [
                        'This invoice has not been issued and cannot receive a payment.',
                    ],
                ]);
            }

            if ($status === 'PAID') {
                throw ValidationException::withMessages([
                    'invoice_id' => [
                        'This invoice has already been fully paid.',
                    ],
                ]);
            }

            if ($status === 'CANCELLED') {
                throw ValidationException::withMessages([
                    'invoice_id' => [
                        'This invoice has been cancelled and cannot receive a payment.',
                    ],
                ]);
            }

            if ($status === 'VOID') {
                throw ValidationException::withMessages([
                    'invoice_id' => [
                        'This invoice has been voided and cannot receive a payment.',
                    ],
                ]);
            }

            throw ValidationException::withMessages([
                'invoice_id' => [
                    'This invoice is not currently payable.',
                ],
            ]);
        }

        /*
         * Verify the actual financial balance rather than trusting
         * the stored balance_due value.
         */
        $outstanding =
            $this->calculateOutstandingAmount(
                $invoice
            );

        if (
            bccomp(
                $outstanding,
                '0.0000',
                self::MONEY_SCALE
            ) <= 0
        ) {
            throw ValidationException::withMessages([
                'invoice_id' => [
                    'This invoice has no outstanding balance.',
                ],
            ]);
        }
    }

    /**
     * Validate that a payment is CASH.
     */
    protected function validateCashPayment(
        Payment $payment,
    ): void {
        $method = strtoupper(
            (string) (
                $payment->payment_method?->value
                ?? $payment->payment_method
                ?? ''
            )
        );

        if (
            $method !== PaymentMethod::CASH->value
        ) {
            throw ValidationException::withMessages([
                'payment' => [
                    'The selected payment is not a cash payment.',
                ],
            ]);
        }
    }

    /**
     * Calculate authoritative outstanding invoice balance.
     *
     * Only COMPLETED payments reduce the invoice balance.
     */
    protected function calculateOutstandingAmount(
        Invoice $invoice,
    ): string {
        $invoiceTotal =
            $this->invoiceTotal(
                $invoice
            );

        $completedAmount =
            Payment::query()
                ->where(
                    'invoice_id',
                    $invoice->getKey()
                )
                ->where(
                    'status',
                    PaymentStatus::COMPLETED->value
                )
                ->sum('amount');

        $completedAmount =
            $this->normalizeMoney(
                $completedAmount
            );

        /*
         * Protect against historical overpayment/corruption.
         */
        if (
            bccomp(
                $completedAmount,
                $invoiceTotal,
                self::MONEY_SCALE
            ) >= 0
        ) {
            return '0.0000';
        }

        $outstanding =
            bcsub(
                $invoiceTotal,
                $completedAmount,
                self::MONEY_SCALE
            );

        if (
            bccomp(
                $outstanding,
                '0.0000',
                self::MONEY_SCALE
            ) < 0
        ) {
            return '0.0000';
        }

        return $outstanding;
    }

    /**
     * Get invoice total.
     */
    protected function invoiceTotal(
        Invoice $invoice,
    ): string {
        if (
            $invoice->total_amount === null
        ) {
            throw new \RuntimeException(
                'Unable to determine the invoice total amount.'
            );
        }

        return $this->normalizeMoney(
            $invoice->total_amount
        );
    }

    /**
     * Recalculate the invoice financial state.
     *
     * Derives:
     *
     *     paid_amount
     *     balance_due
     *     status
     *     paid_at
     *
     * from the invoice total and all COMPLETED payments.
     */
    protected function applyPaymentToInvoice(
        Invoice $invoice,
    ): void {
        /*
         * -------------------------------------------------------------
         * 1. Invoice total
         * -------------------------------------------------------------
         */
        $invoiceTotal =
            $this->invoiceTotal(
                $invoice
            );

        /*
         * -------------------------------------------------------------
         * 2. Sum completed payments
         * -------------------------------------------------------------
         */
        $completedAmount =
            Payment::query()
                ->where(
                    'invoice_id',
                    $invoice->getKey()
                )
                ->where(
                    'status',
                    PaymentStatus::COMPLETED->value
                )
                ->sum('amount');

        $paidAmount =
            $this->normalizeMoney(
                $completedAmount
            );

        /*
         * -------------------------------------------------------------
         * 3. Protect against overpayment
         * -------------------------------------------------------------
         */
        if (
            bccomp(
                $paidAmount,
                $invoiceTotal,
                self::MONEY_SCALE
            ) > 0
        ) {
            throw ValidationException::withMessages([
                'payment' => [
                    'Completed payments exceed the invoice total.',
                ],
            ]);
        }

        /*
         * -------------------------------------------------------------
         * 4. Calculate balance
         * -------------------------------------------------------------
         */
        $balanceDue =
            bcsub(
                $invoiceTotal,
                $paidAmount,
                self::MONEY_SCALE
            );

        if (
            bccomp(
                $balanceDue,
                '0.0000',
                self::MONEY_SCALE
            ) < 0
        ) {
            $balanceDue = '0.0000';
        }

        /*
         * -------------------------------------------------------------
         * 5. Update financial values
         * -------------------------------------------------------------
         */
        $invoice->paid_amount =
            $paidAmount;

        $invoice->balance_due =
            $balanceDue;

        /*
         * -------------------------------------------------------------
         * 6. Determine invoice status
         * -------------------------------------------------------------
         */
        if (
            bccomp(
                $balanceDue,
                '0.0000',
                self::MONEY_SCALE
            ) === 0
        ) {
            $invoice->status =
                'PAID';

            if ($invoice->paid_at === null) {
                $invoice->paid_at =
                    now();
            }
        } else {
            $invoice->status =
                'PARTIALLY_PAID';

            $invoice->paid_at =
                null;
        }

        /*
         * -------------------------------------------------------------
         * 7. Persist invoice
         * -------------------------------------------------------------
         */
        $invoice->save();
    }

    /**
     * Authorize viewing a payment.
     */
    protected function authorizeView(
        Payment $payment,
        User $user,
    ): void {
        if (
            !$user->can(
                'view',
                $payment
            )
        ) {
            throw new AuthorizationException(
                'You are not authorized to view this payment.'
            );
        }
    }

    /**
     * Determine whether payment is PENDING.
     */
    protected function isPending(
        Payment $payment,
    ): bool {
        return $this->paymentStatus(
            $payment
        ) === PaymentStatus::PENDING->value;
    }

    /**
     * Determine whether payment is COMPLETED.
     */
    protected function isCompleted(
        Payment $payment,
    ): bool {
        return $this->paymentStatus(
            $payment
        ) === PaymentStatus::COMPLETED->value;
    }

    /**
     * Get normalized payment status.
     */
    protected function paymentStatus(
        Payment $payment,
    ): string {
        return strtoupper(
            (string) (
                $payment->status?->value
                ?? $payment->status
                ?? ''
            )
        );
    }

    /**
     * Get normalized invoice status.
     */
    protected function invoiceStatus(
        Invoice $invoice,
    ): string {
        return strtoupper(
            (string) (
                $invoice->status?->value
                ?? $invoice->status
                ?? ''
            )
        );
    }

    /**
     * Generate a unique transaction reference.
     *
     * Example:
     *
     *     TXN-01M44CA6FD18N4K3DG7VY47J4K
     */
    protected function generateTransactionReference(): string
    {
        return 'TXN-' .
            strtoupper(
                Str::ulid()->toBase32()
            );
    }

    /**
     * Normalize a positive payment amount.
     *
     * BCMath is used to avoid binary floating-point calculations.
     */
    protected function normalizeAmount(
        mixed $amount,
    ): string {
        if (
            $amount === null ||
            $amount === ''
        ) {
            throw ValidationException::withMessages([
                'amount' => [
                    'A payment amount is required.',
                ],
            ]);
        }

        $value =
            trim((string) $amount);

        if (!is_numeric($value)) {
            throw ValidationException::withMessages([
                'amount' => [
                    'The payment amount must be a valid number.',
                ],
            ]);
        }

        /*
         * Reject scientific notation and malformed decimal values.
         */
        if (
            !preg_match(
                '/^\d+(?:\.\d+)?$/',
                $value
            )
        ) {
            throw ValidationException::withMessages([
                'amount' => [
                    'The payment amount must be a valid decimal number.',
                ],
            ]);
        }

        if (
            bccomp(
                $value,
                '0',
                self::MONEY_SCALE
            ) <= 0
        ) {
            throw ValidationException::withMessages([
                'amount' => [
                    'The payment amount must be greater than zero.',
                ],
            ]);
        }

        return bcadd(
            $value,
            '0',
            self::MONEY_SCALE
        );
    }

    /**
     * Normalize a monetary value where zero is allowed.
     */
    protected function normalizeMoney(
        mixed $amount,
    ): string {
        if (
            $amount === null ||
            $amount === ''
        ) {
            return '0.0000';
        }

        $value =
            trim((string) $amount);

        if (!is_numeric($value)) {
            throw new \RuntimeException(
                'Invalid monetary value.'
            );
        }

        return bcadd(
            $value,
            '0',
            self::MONEY_SCALE
        );
    }
}