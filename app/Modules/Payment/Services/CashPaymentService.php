<?php

declare(strict_types=1);

namespace App\Modules\Payment\Services;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\CashPaymentDetail;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Jobs\SendPaymentNotificationJob;
use App\Modules\Payment\Notifications\PaymentNotificationService;
use App\Services\DocumentSequenceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class CashPaymentService
{
    /**
     * Monetary precision used throughout the payment domain.
     */
    protected const MONEY_SCALE = 4;

    /**
     * Invoice statuses that may receive payments.
     */
    protected const PAYABLE_INVOICE_STATUSES = [
        'ISSUED',
        'PARTIALLY_PAID',
        'OVERDUE',
    ];

    /**
     * Payment source controlled by this service.
     */
    protected const PAYMENT_SOURCE = 'OFFICE_RECORDED';

    /**
     * ================================================================
     * RECORD
     * ================================================================
     *
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
     * Financial invoice values are NOT changed here.
     *
     * No receipt is created while the payment is PENDING.
     */
    public function record(
        User $user,
        array $data,
        ?string $requestId = null,
    ): Payment {
        return DB::transaction(
            function () use (
                $user,
                $data,
                $requestId
            ): Payment {
                /*
                 * =====================================================
                 * 1. LOCK AND LOAD INVOICE
                 * =====================================================
                 */
                $invoice = Invoice::query()
                    ->with('citizen')
                    ->whereKey(
                        $data['invoice_id'] ?? null
                    )
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
                 * =====================================================
                 * 2. VALIDATE INVOICE
                 * =====================================================
                 */
                $this->validateInvoiceForPayment(
                    $invoice
                );

                /*
                 * =====================================================
                 * 3. PREVENT DUPLICATE PENDING CASH PAYMENT
                 * =====================================================
                 *
                 * Multiple completed payments are allowed.
                 *
                 * Only one pending cash payment is allowed for
                 * the same invoice.
                 */
                $pendingPayment = Payment::query()
                    ->where(
                        'invoice_id',
                        $invoice->getKey()
                    )
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
                 * =====================================================
                 * 4. NORMALIZE PAYMENT AMOUNT
                 * =====================================================
                 */
                $amount = $this->normalizeAmount(
                    $data['amount'] ?? null
                );

                /*
                 * =====================================================
                 * 5. CALCULATE CURRENT OUTSTANDING BALANCE
                 * =====================================================
                 */
                $outstanding =
                    $this->calculateOutstandingAmount(
                        $invoice
                    );

                /*
                 * =====================================================
                 * 6. PREVENT OVERPAYMENT
                 * =====================================================
                 */
                if (
                    bccomp(
                        $amount,
                        $outstanding,
                        self::MONEY_SCALE
                    ) > 0
                ) {
                    Log::warning(
                        'Cash payment overpayment validation failed.',
                        [
                            'request_id' =>
                                $requestId,

                            'invoice_id' =>
                                $invoice->getKey(),

                            'payment_amount' =>
                                $amount,

                            'outstanding_amount' =>
                                $outstanding,

                            'money_scale' =>
                                self::MONEY_SCALE,
                        ]
                    );

                    throw ValidationException::withMessages([
                        'amount' => [
                            'The payment amount cannot exceed the outstanding invoice balance.',
                        ],
                    ]);
                }

                /*
                 * =====================================================
                 * 7. GENERATE PAYMENT NUMBER
                 * =====================================================
                 */
                $paymentNumber =
                    $this->documentSequenceService->generate(
                        sequenceType: 'payment',
                    );

                /*
                 * =====================================================
                 * 8. GENERATE MUNICIPAL TRANSACTION REFERENCE
                 * =====================================================
                 */
                $transactionReference =
                    $this->generateTransactionReference();

                /*
                 * =====================================================
                 * 9. CREATE COMMON PAYMENT
                 * =====================================================
                 */
                $payment = new Payment();

                $payment->payment_number =
                    $paymentNumber;

                $payment->invoice_id =
                    $invoice->getKey();

                /*
                 * Never trust citizen_id from the frontend.
                 *
                 * The invoice is the authoritative source.
                 */
                $payment->citizen_id =
                    $invoice->citizen_id;

                /*
                 * Controlled by this service.
                 */
                $payment->payment_method =
                    PaymentMethod::CASH;

                /*
                 * Controlled by the backend.
                 */
                $payment->payment_source =
                    self::PAYMENT_SOURCE;

                /*
                 * New cash payment starts as PENDING.
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
                 * User who recorded the cash payment.
                 */
                $payment->processed_by =
                    $user->id;

                /*
                 * Payer information comes from the invoice citizen.
                 */
                $payment->payer_name =
                    $invoice->citizen?->name;

                $payment->payer_email =
                    $invoice->citizen?->email;

                $payment->payer_phone =
                    $invoice->citizen?->phone;

                $payment->failure_reason =
                    null;

                /*
                 * Only controlled metadata should be accepted.
                 */
                $payment->metadata =
                    $data['metadata'] ?? null;

                $payment->save();

                /*
                 * =====================================================
                 * 10. CREATE CASH PAYMENT DETAILS
                 * =====================================================
                 */
                $cashDetails =
                    new CashPaymentDetail();

                $cashDetails->payment_id =
                    $payment->getKey();

                /*
                 * The authenticated user who physically received/
                 * recorded the cash.
                 */
                $cashDetails->received_by =
                    $user->id;

                /*
                 * Cashier session is currently not implemented.
                 */
                $cashDetails->cashier_session_id =
                    null;

                /*
                 * Physical cash receipt timestamp.
                 */
                $cashDetails->cash_received_at =
                    now();

                $cashDetails->notes =
                    $data['notes'] ?? null;

                $cashDetails->save();

                /*
                 * =====================================================
                 * 11. AUDIT LOG
                 * =====================================================
                 */
                Log::info(
                    'Cash payment recorded.',
                    [
                        'request_id' =>
                            $requestId,

                        'payment_id' =>
                            $payment->getKey(),

                        'payment_number' =>
                            $payment->payment_number,

                        'transaction_reference' =>
                            $payment->transaction_reference,

                        'invoice_id' =>
                            $invoice->getKey(),

                        'amount' =>
                            $amount,

                        'currency' =>
                            $payment->currency,

                        'payment_method' =>
                            PaymentMethod::CASH->value,

                        'payment_source' =>
                            self::PAYMENT_SOURCE,

                        'status' =>
                            PaymentStatus::PENDING->value,

                        'processed_by' =>
                            $user->id,

                        'received_by' =>
                            $user->id,
                    ]
                );

                /*
                 * =====================================================
                 * 12. RETURN FULLY LOADED PAYMENT
                 * =====================================================
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
            },
            3
        );
    }

    /**
     * ================================================================
     * COMPLETE
     * ================================================================
     *
     * Complete a pending cash payment.
     *
     * Lifecycle:
     *
     *     PENDING
     *         ↓
     *     COMPLETED
     *         ↓
     *     Receipt
     *         ↓
     *     Invoice recalculation
     *         ↓
     *     Transaction commit
     *         ↓
     *     SMS notification job
     *
     * Payment completion, receipt creation, and invoice recalculation
     * occur inside the same database transaction.
     *
     * SMS is dispatched only AFTER the transaction commits.
     */
    public function complete(
        string $paymentId,
        User $user,
        ?string $requestId = null,
    ): Payment {
        return DB::transaction(
            function () use (
                $paymentId,
                $user,
                $requestId
            ): Payment {
                /*
                 * =====================================================
                 * 1. FIND PAYMENT REFERENCE
                 * =====================================================
                 *
                 * We need invoice_id before obtaining the invoice lock.
                 *
                 * Do not lock the payment yet.
                 */
                $paymentReference =
                    Payment::query()
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
                 * =====================================================
                 * 2. VALIDATE PAYMENT METHOD
                 * =====================================================
                 */
                $this->validateCashPayment(
                    $paymentReference
                );

                /*
                 * =====================================================
                 * 3. LOCK INVOICE FIRST
                 * =====================================================
                 *
                 * record():
                 *
                 *     Invoice → Payment
                 *
                 * complete():
                 *
                 *     Invoice → Payment
                 *
                 * Keeping the same lock order prevents avoidable
                 * deadlocks between concurrent payment operations.
                 */
                $invoice =
                    Invoice::query()
                        ->with('citizen')
                        ->whereKey(
                            $paymentReference->invoice_id
                        )
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
                 * =====================================================
                 * 4. LOCK PAYMENT SECOND
                 * =====================================================
                 */
                $payment =
                    Payment::query()
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
                 * =====================================================
                 * 5. VALIDATE PAYMENT METHOD AGAIN
                 * =====================================================
                 */
                $this->validateCashPayment(
                    $payment
                );

                /*
                 * =====================================================
                 * 6. IDEMPOTENT COMPLETION
                 * =====================================================
                 *
                 * If another request already completed this payment,
                 * do not:
                 *
                 * - create another receipt
                 * - recalculate it again
                 * - dispatch another SMS
                 */
                if ($this->isCompleted($payment)) {
                    Log::info(
                        'Cash payment completion skipped because payment is already completed.',
                        [
                            'request_id' =>
                                $requestId,

                            'payment_id' =>
                                $payment->getKey(),

                            'payment_number' =>
                                $payment->payment_number,
                        ]
                    );

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
                 * =====================================================
                 * 7. ONLY PENDING PAYMENTS CAN BE COMPLETED
                 * =====================================================
                 */
                if (!$this->isPending($payment)) {
                    throw ValidationException::withMessages([
                        'payment' => [
                            'Only pending cash payments can be completed.',
                        ],
                    ]);
                }

                /*
                 * =====================================================
                 * 8. AUTHORIZE COMPLETION
                 * =====================================================
                 */
                $this->authorizeCompletion(
                    $user
                );

                /*
                 * =====================================================
                 * 9. VALIDATE INVOICE
                 * =====================================================
                 */
                $this->validateInvoiceForPayment(
                    $invoice
                );

                /*
                 * =====================================================
                 * 10. CASH DETAILS MUST EXIST
                 * =====================================================
                 */
                $cashDetails =
                    $payment->cashDetails;

                if (!$cashDetails) {
                    throw ValidationException::withMessages([
                        'payment' => [
                            'Cash payment details were not found.',
                        ],
                    ]);
                }

                /*
                 * =====================================================
                 * 11. NORMALIZE PAYMENT AMOUNT
                 * =====================================================
                 */
                $paymentAmount =
                    $this->normalizeAmount(
                        $payment->amount
                    );

                /*
                 * =====================================================
                 * 12. RECHECK OUTSTANDING BALANCE
                 * =====================================================
                 *
                 * The invoice is locked, so this calculation is made
                 * against a consistent invoice/payment state.
                 */
                $outstanding =
                    $this->calculateOutstandingAmount(
                        $invoice
                    );

                if (
                    bccomp(
                        $paymentAmount,
                        $outstanding,
                        self::MONEY_SCALE
                    ) > 0
                ) {
                    Log::warning(
                        'Cash payment completion overpayment validation failed.',
                        [
                            'request_id' =>
                                $requestId,

                            'payment_id' =>
                                $payment->getKey(),

                            'payment_number' =>
                                $payment->payment_number,

                            'invoice_id' =>
                                $invoice->getKey(),

                            'payment_amount' =>
                                $paymentAmount,

                            'outstanding_amount' =>
                                $outstanding,

                            'money_scale' =>
                                self::MONEY_SCALE,
                        ]
                    );

                    throw ValidationException::withMessages([
                        'payment' => [
                            'The payment amount now exceeds the outstanding invoice balance.',
                        ],
                    ]);
                }

                /*
                 * =====================================================
                 * 13. COMPLETE PAYMENT
                 * =====================================================
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
                 * =====================================================
                 * 14. ENSURE CASH RECEIVED TIMESTAMP
                 * =====================================================
                 */
                if (
                    $cashDetails->cash_received_at === null
                ) {
                    $cashDetails->cash_received_at =
                        now();

                    $cashDetails->save();
                }

                /*
                 * =====================================================
                 * 15. CREATE OFFICIAL RECEIPT
                 * =====================================================
                 *
                 * PaymentReceiptService::create() receives the
                 * authenticated municipal user as the issuer.
                 */
                $receipt =
                    $this->receiptService->create(
                        payment: $payment,
                        user: $user,
                    );

                /*
                 * =====================================================
                 * 16. APPLY PAYMENT TO INVOICE
                 * =====================================================
                 *
                 * This recalculates:
                 *
                 *     paid_amount
                 *     balance_due
                 *     status
                 *     paid_at
                 */
                $this->applyPaymentToInvoice(
                    $invoice
                );

                /*
                 * =====================================================
                 * 17. DETERMINE SUCCESS SMS TYPE
                 * =====================================================
                 *
                 * Exactly ONE success notification is dispatched.
                 *
                 * Remaining balance > 0:
                 *     PAYMENT_PARTIALLY_PAID
                 *
                 * Remaining balance = 0:
                 *     PAYMENT_FULLY_PAID
                 *
                 * PAYMENT_RECEIVED is intentionally not dispatched
                 * here to avoid sending two SMS messages for one
                 * successful cash payment.
                 */
                $notificationType =
                    bccomp(
                        $this->normalizeMoney(
                            $invoice->balance_due
                        ),
                        '0.0000',
                        self::MONEY_SCALE
                    ) === 0
                        ? PaymentNotificationService::PAYMENT_FULLY_PAID
                        : PaymentNotificationService::PAYMENT_PARTIALLY_PAID;

                /*
                 * =====================================================
                 * 18. DISPATCH SMS AFTER COMMIT
                 * =====================================================
                 *
                 * The job will not run until this database transaction
                 * has successfully committed.
                 *
                 * Therefore the SMS cannot be sent for a payment that
                 * later rolls back.
                 */
                $this->dispatchPaymentNotificationAfterCommit(
                    payment: $payment,
                    notificationType: $notificationType,
                );

                /*
                 * =====================================================
                 * 19. AUDIT LOG
                 * =====================================================
                 */
                Log::info(
                    'Cash payment completed.',
                    [
                        'request_id' =>
                            $requestId,

                        'payment_id' =>
                            $payment->getKey(),

                        'payment_number' =>
                            $payment->payment_number,

                        'transaction_reference' =>
                            $payment->transaction_reference,

                        'receipt_id' =>
                            $receipt->getKey(),

                        'receipt_number' =>
                            $receipt->receipt_number,

                        'invoice_id' =>
                            $invoice->getKey(),

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

                        'notification_type' =>
                            $notificationType,
                    ]
                );

                /*
                 * =====================================================
                 * 20. RETURN FULLY LOADED PAYMENT
                 * =====================================================
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
            },
            3
        );
    }

    /**
     * ================================================================
     * FIND
     * ================================================================
     *
     * Find a cash payment.
     */
    public function find(
        string $paymentId,
        User $user,
    ): Payment {
        $payment =
            Payment::query()
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
     * ================================================================
     * VALIDATE INVOICE
     * ================================================================
     *
     * Validate invoice payment eligibility.
     */
    protected function validateInvoiceForPayment(
        Invoice $invoice,
    ): void {
        $status =
            $this->invoiceStatus(
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
         * Do not blindly trust invoice.balance_due.
         *
         * Calculate the authoritative outstanding amount from:
         *
         *     invoice.total_amount
         *     -
         *     COMPLETED payments
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
     * ================================================================
     * VALIDATE PAYMENT METHOD
     * ================================================================
     */
    protected function validateCashPayment(
        Payment $payment,
    ): void {
        $method =
            strtoupper(
                (string) (
                    $payment->payment_method?->value
                    ?? $payment->payment_method
                    ?? ''
                )
            );

        if (
            $method !==
            PaymentMethod::CASH->value
        ) {
            throw ValidationException::withMessages([
                'payment' => [
                    'The selected payment is not a cash payment.',
                ],
            ]);
        }
    }

    /**
     * ================================================================
     * AUTHORIZE COMPLETION
     * ================================================================
     *
     * The user completing a cash payment must have the dedicated
     * payment-completion permission.
     */
    protected function authorizeCompletion(
        User $user,
    ): void {
        if (
            !$user->can(
                'CASH_PAYMENTS_COMPLETE'
            )
        ) {
            throw new AuthorizationException(
                'You are not authorized to complete cash payments.'
            );
        }
    }

    /**
     * ================================================================
     * AUTHORIZE VIEW
     * ================================================================
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
     * ================================================================
     * CALCULATE OUTSTANDING
     * ================================================================
     *
     * Authoritative outstanding balance.
     *
     * Only COMPLETED payments reduce the balance.
     */
    protected function calculateOutstandingAmount(
        Invoice $invoice,
    ): string {
        /*
         * ------------------------------------------------------------
         * 1. Invoice total
         * ------------------------------------------------------------
         */
        $invoiceTotal =
            $this->invoiceTotal(
                $invoice
            );

        /*
         * ------------------------------------------------------------
         * 2. Sum COMPLETED payments
         * ------------------------------------------------------------
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

        $completedAmount =
            $this->normalizeMoney(
                $completedAmount
            );

        /*
         * ------------------------------------------------------------
         * 3. Protect against historical overpayment/corruption
         * ------------------------------------------------------------
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

        /*
         * ------------------------------------------------------------
         * 4. Calculate outstanding
         * ------------------------------------------------------------
         */
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
     * ================================================================
     * GET INVOICE TOTAL
     * ================================================================
     */
    protected function invoiceTotal(
        Invoice $invoice,
    ): string {
        if (
            $invoice->total_amount === null
        ) {
            throw new RuntimeException(
                'Unable to determine the invoice total amount.'
            );
        }

        return $this->normalizeMoney(
            $invoice->total_amount
        );
    }

    /**
     * ================================================================
     * APPLY PAYMENT TO INVOICE
     * ================================================================
     *
     * Recalculate:
     *
     *     paid_amount
     *     balance_due
     *     status
     *     paid_at
     *
     * Only COMPLETED payments are included.
     */
    protected function applyPaymentToInvoice(
        Invoice $invoice,
    ): void {
        /*
         * ------------------------------------------------------------
         * 1. Invoice total
         * ------------------------------------------------------------
         */
        $invoiceTotal =
            $this->invoiceTotal(
                $invoice
            );

        /*
         * ------------------------------------------------------------
         * 2. Sum completed payments
         * ------------------------------------------------------------
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
         * ------------------------------------------------------------
         * 3. Overpayment protection
         * ------------------------------------------------------------
         */
        if (
            bccomp(
                $paidAmount,
                $invoiceTotal,
                self::MONEY_SCALE
            ) > 0
        ) {
            Log::critical(
                'Invoice overpayment detected while applying cash payment.',
                [
                    'invoice_id' =>
                        $invoice->getKey(),

                    'invoice_total' =>
                        $invoiceTotal,

                    'completed_payment_amount' =>
                        $paidAmount,

                    'money_scale' =>
                        self::MONEY_SCALE,
                ]
            );

            throw ValidationException::withMessages([
                'payment' => [
                    'Completed payments exceed the invoice total.',
                ],
            ]);
        }

        /*
         * ------------------------------------------------------------
         * 4. Calculate balance
         * ------------------------------------------------------------
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
            $balanceDue =
                '0.0000';
        }

        /*
         * ------------------------------------------------------------
         * 5. Update financial values
         * ------------------------------------------------------------
         */
        $invoice->paid_amount =
            $paidAmount;

        $invoice->balance_due =
            $balanceDue;

        /*
         * ------------------------------------------------------------
         * 6. Determine invoice status
         * ------------------------------------------------------------
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

            if (
                $invoice->paid_at === null
            ) {
                $invoice->paid_at =
                    now();
            }
        } else {
            $invoice->status =
                'PARTIALLY_PAID';

            /*
             * The invoice is not fully paid.
             */
            $invoice->paid_at =
                null;
        }

        /*
         * ------------------------------------------------------------
         * 7. Persist invoice
         * ------------------------------------------------------------
         */
        $invoice->save();
    }

    /**
     * ================================================================
     * DISPATCH PAYMENT NOTIFICATION
     * ================================================================
     *
     * Queue the SMS only after the current DB transaction commits.
     *
     * This prevents:
     *
     *     DB rollback
     *         +
     *     already-sent SMS
     *
     * Example:
     *
     *     Payment completed
     *          ↓
     *     Invoice updated
     *          ↓
     *     Receipt created
     *          ↓
     *     COMMIT
     *          ↓
     *     Queue SMS
     */
    protected function dispatchPaymentNotificationAfterCommit(
        Payment $payment,
        string $notificationType,
    ): void {
        SendPaymentNotificationJob::dispatch(
            paymentId: (string) $payment->getKey(),
            notificationType: $notificationType,
        )->afterCommit();

        Log::info(
            'Payment notification job dispatched after commit.',
            [
                'payment_id' =>
                    $payment->getKey(),

                'payment_number' =>
                    $payment->payment_number,

                'notification_type' =>
                    $notificationType,
            ]
        );
    }

    /**
     * ================================================================
     * PAYMENT STATUS HELPERS
     * ================================================================
     */

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
     * ================================================================
     * TRANSACTION REFERENCE
     * ================================================================
     *
     * Generate internal municipal transaction reference.
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
     * ================================================================
     * NORMALIZE PAYMENT AMOUNT
     * ================================================================
     *
     * Normalize a positive payment amount.
     *
     * BCMath is used to avoid binary floating-point calculations.
     */
    protected function normalizeAmount(
        mixed $amount,
    ): string {
        if (
            $amount === null
            || $amount === ''
        ) {
            throw ValidationException::withMessages([
                'amount' => [
                    'A payment amount is required.',
                ],
            ]);
        }

        /*
         * Do not silently convert arrays/objects/booleans into
         * strings.
         */
        if (
            is_array($amount)
            || is_object($amount)
            || is_bool($amount)
        ) {
            throw ValidationException::withMessages([
                'amount' => [
                    'The payment amount must be a valid decimal number.',
                ],
            ]);
        }

        $value =
            trim(
                (string) $amount
            );

        /*
         * Reject:
         *
         *     scientific notation
         *     negative values
         *     malformed decimals
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

        /*
         * Maximum four decimal places.
         */
        $decimalPosition =
            strpos(
                $value,
                '.'
            );

        if (
            $decimalPosition !== false
            && (
                strlen($value)
                - $decimalPosition
                - 1
            ) > self::MONEY_SCALE
        ) {
            throw ValidationException::withMessages([
                'amount' => [
                    'The payment amount may have up to four decimal places.',
                ],
            ]);
        }

        /*
         * Must be greater than zero.
         */
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
     * ================================================================
     * NORMALIZE MONEY
     * ================================================================
     *
     * Normalize a monetary value where zero is allowed.
     */
    protected function normalizeMoney(
        mixed $amount,
    ): string {
        if (
            $amount === null
            || $amount === ''
        ) {
            return '0.0000';
        }

        if (
            is_array($amount)
            || is_object($amount)
            || is_bool($amount)
        ) {
            throw new RuntimeException(
                'Invalid monetary value.'
            );
        }

        $value =
            trim(
                (string) $amount
            );

        /*
         * Monetary values must be ordinary decimal strings.
         *
         * Scientific notation is deliberately rejected.
         */
        if (
            !preg_match(
                '/^-?\d+(?:\.\d+)?$/',
                $value
            )
        ) {
            throw new RuntimeException(
                'Invalid monetary value.'
            );
        }

        /*
         * Do not silently accept more precision than the financial
         * domain supports.
         */
        $decimalPosition =
            strpos(
                $value,
                '.'
            );

        if (
            $decimalPosition !== false
            && (
                strlen($value)
                - $decimalPosition
                - 1
            ) > self::MONEY_SCALE
        ) {
            throw new RuntimeException(
                'Invalid monetary value precision.'
            );
        }

        return bcadd(
            $value,
            '0',
            self::MONEY_SCALE
        );
    }
}