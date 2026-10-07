<?php

namespace App\Modules\Payment\Services;

use App\Models\Payment;
use App\Models\User;
use App\Modules\Payment\Contracts\PaymentProviderInterface;
use App\Modules\Payment\DTOs\PaymentVerificationResult;
use App\Modules\Payment\Factories\PaymentProviderFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class PaymentVerificationService
{
    /**
     * Number of decimal places used for municipal money calculations.
     */
    protected const MONEY_SCALE = 4;

    /**
     * Invoice statuses that can receive a payment.
     */
    protected const PAYABLE_INVOICE_STATUSES = [
        'ISSUED',
        'PARTIALLY_PAID',
        'OVERDUE',
    ];

    public function __construct(
        protected PaymentProviderFactory $providerFactory,
        protected PaymentReceiptService $receiptService,
    ) {
    }

    /**
     * Verify a payment with its configured provider and,
     * when successful, finalize the complete financial transaction.
     *
     * Flow:
     *
     * 1. Check current local payment state.
     * 2. Resolve the configured payment provider.
     * 3. Ask the provider to independently verify the transaction.
     * 4. Lock the payment.
     * 5. Lock the invoice.
     * 6. Validate the remaining invoice balance.
     * 7. Mark payment COMPLETED when provider verification succeeds.
     * 8. Create the official municipal receipt.
     * 9. Apply the completed payment to the invoice.
     * 10. Update invoice financial state.
     *
     * IMPORTANT:
     * The callback/webhook status is NOT trusted directly.
     * The provider implementation must independently verify
     * the transaction with the payment provider.
     */
    public function verify(
        Payment $payment
    ): PaymentVerificationResult {
        /*
         * Fast idempotency check.
         *
         * If another webhook/callback already completed this payment,
         * do not call the provider again unnecessarily.
         */
        if ($payment->isSuccessful()) {
            return PaymentVerificationResult::success(
                payment: $payment,
                message: 'Payment has already been verified successfully.',
            );
        }

        /*
         * A failed payment should not automatically be retried
         * through this service.
         *
         * A new payment attempt should create a new Payment record.
         */
        if ($payment->isFailed()) {
            return PaymentVerificationResult::failed(
                payment: $payment,
                message: 'Payment has already been marked as failed.',
            );
        }

        try {
            /**
             * Resolve the provider configured for this payment.
             */
            /** @var PaymentProviderInterface $provider */
            $provider = $this->providerFactory->make(
                $payment->provider
            );

            /*
             * IMPORTANT:
             *
             * This is the authoritative provider-side verification.
             *
             * For Chapa, this should call Chapa's transaction
             * verification endpoint.
             *
             * The callback/webhook status must never be trusted
             * as the source of truth.
             */
            $result = $provider->verify($payment);

            /*
             * Do not hold database locks while making the external
             * provider API request.
             *
             * Provider verification happens before this transaction.
             */
            return DB::transaction(
                function () use ($payment, $result) {
                    /*
                     * Re-fetch and lock the payment.
                     *
                     * This protects against simultaneous:
                     *
                     * - Chapa webhook
                     * - frontend verification
                     * - retry
                     * - another server process
                     *
                     * requests trying to finalize the same payment.
                     */
                    $lockedPayment = Payment::query()
                        ->whereKey($payment->getKey())
                        ->lockForUpdate()
                        ->first();

                    if (!$lockedPayment) {
                        throw new RuntimeException(
                            'Payment could not be found during verification.'
                        );
                    }

                    /*
                     * If another request completed the payment while
                     * provider verification was running, stop here.
                     *
                     * The other transaction already created the receipt
                     * and updated the invoice.
                     */
                    if ($lockedPayment->isSuccessful()) {
                        Log::info(
                            'Payment was already completed before verification finalization.',
                            [
                                'payment_id' => $lockedPayment->id,
                                'payment_uuid' =>
                                    $lockedPayment->uuid ?? null,
                                'provider_reference' =>
                                    $lockedPayment->provider_reference,
                                'transaction_reference' =>
                                    $lockedPayment->transaction_reference,
                            ]
                        );

                        return PaymentVerificationResult::success(
                            payment: $lockedPayment->refresh(),
                            message:
                                'Payment has already been verified successfully.',
                        );
                    }

                    /*
                     * Do not downgrade a payment that has already been
                     * failed by another process.
                     */
                    if ($lockedPayment->isFailed()) {
                        return PaymentVerificationResult::failed(
                            payment: $lockedPayment->refresh(),
                            message:
                                'Payment has already been marked as failed.',
                        );
                    }

                    /*
                     * Apply the provider result.
                     */
                    return $this->applyVerificationResult(
                        $lockedPayment,
                        $result
                    );
                }
            );
        } catch (Throwable $exception) {
            Log::error(
                'Payment verification failed.',
                [
                    'payment_id' => $payment->id,
                    'payment_uuid' =>
                        $payment->uuid ?? null,
                    'provider' =>
                        $payment->provider?->value
                        ?? $payment->provider
                        ?? null,
                    'provider_reference' =>
                        $payment->provider_reference
                        ?? null,
                    'transaction_reference' =>
                        $payment->transaction_reference
                        ?? null,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]
            );

            throw $exception;
        }
    }

    /**
     * Apply the normalized provider verification result.
     */
    protected function applyVerificationResult(
        Payment $payment,
        PaymentVerificationResult $result
    ): PaymentVerificationResult {
        if ($result->isSuccessful()) {
            return $this->markSuccessful(
                $payment,
                $result
            );
        }

        if ($result->isFailed()) {
            $this->markFailed(
                $payment,
                $result
            );

            return $result;
        }

        $this->markPending(
            $payment,
            $result
        );

        return $result;
    }

    /**
     * Mark payment as successfully paid and finalize
     * the related municipal financial records.
     *
     * This is the online-payment equivalent of:
     *
     * BankTransferService::verify()
     */
    protected function markSuccessful(
        Payment $payment,
        PaymentVerificationResult $result
    ): PaymentVerificationResult {
        /*
         * Additional idempotency protection.
         */
        if ($payment->isSuccessful()) {
            return PaymentVerificationResult::success(
                payment: $payment->refresh(),
                message:
                    'Payment has already been verified successfully.',
            );
        }

        /*
         * Lock the invoice before checking or modifying
         * its financial state.
         *
         * This is important because multiple payments may attempt
         * to settle the same invoice concurrently.
         */
        $invoice = $payment->invoice()
            ->lockForUpdate()
            ->first();

        if (!$invoice) {
            throw new RuntimeException(
                'The invoice associated with this payment could not be found.'
            );
        }

        /*
         * Validate invoice status.
         */
        $invoiceStatus = $invoice->status?->value
            ?? $invoice->status
            ?? null;

        if ($invoiceStatus !== null) {
            $normalizedInvoiceStatus = strtoupper(
                (string) $invoiceStatus
            );

            if ($normalizedInvoiceStatus === 'PAID') {
                throw new RuntimeException(
                    'The invoice has already been fully paid.'
                );
            }

            if (
                !in_array(
                    $normalizedInvoiceStatus,
                    self::PAYABLE_INVOICE_STATUSES,
                    true
                )
            ) {
                throw new RuntimeException(
                    'The invoice is not in a payable state.'
                );
            }
        }

        /*
         * Calculate the remaining invoice balance using
         * COMPLETED payments only.
         *
         * The current payment is still pending at this point,
         * so it is not included in this calculation.
         */
        $outstandingAmount = $this->calculateOutstandingAmount(
            $invoice
        );

        $paymentAmount = $this->normalizeMoney(
            $payment->amount
        );

        /*
         * Prevent overpayment.
         */
        if (
            bccomp(
                $paymentAmount,
                $outstandingAmount,
                self::MONEY_SCALE
            ) === 1
        ) {
            throw new RuntimeException(
                'The verified payment amount exceeds the outstanding invoice balance.'
            );
        }

        /*
         * Update payment with authoritative provider result.
         */
        $payment->status = $result->status;

        /*
         * Save Chapa/provider reference when available.
         *
         * For Chapa this is normally the provider-side reference
         * such as ref_id.
         */
        if ($result->providerReference !== null) {
            $payment->provider_reference =
                $result->providerReference;
        }

        /*
         * Save the merchant/local transaction reference when
         * returned by the provider.
         */
        if ($result->transactionReference !== null) {
            $payment->transaction_reference =
                $result->transactionReference;
        }

        /*
         * Provider may return the exact payment time.
         *
         * If unavailable, use the current server time.
         */
        $payment->paid_at =
            $result->paidAt ?? now();

        /*
         * Save the complete provider response for audit,
         * reconciliation, and troubleshooting.
         */
        if ($result->metadata !== null) {
            $payment->provider_response =
                $result->metadata;
        }

        /*
         * A successful payment must not retain a failure reason.
         */
        $payment->failure_reason = null;

        $payment->save();

        /*
         * Refresh so receipt generation receives the persisted
         * successful payment state.
         */
        $payment->refresh();

        /*
         * Create the official municipal receipt.
         *
         * Chapa does not require a human verification officer.
         * Therefore we use the user who originally processed/
         * initiated the payment as the receipt issuer.
         */
        $this->createReceiptIfNecessary(
            $payment
        );

        /*
         * Recalculate invoice financial state using completed
         * payments only.
         *
         * The current payment is now COMPLETED, so it is included.
         */
        $this->applyPaymentToInvoice(
            $invoice
        );

        /*
         * Reload the final payment state.
         */
        $payment->refresh();

        /*
         * Reload invoice state after save.
         */
        $invoice->refresh();

        Log::info(
            'Online payment successfully verified and finalized.',
            [
                'payment_id' => $payment->id,
                'payment_uuid' =>
                    $payment->uuid ?? null,
                'invoice_id' => $invoice->id,
                'invoice_number' =>
                    $invoice->invoice_number ?? null,
                'provider' =>
                    $payment->provider?->value
                    ?? $payment->provider
                    ?? null,
                'provider_reference' =>
                    $payment->provider_reference,
                'transaction_reference' =>
                    $payment->transaction_reference,
                'amount' =>
                    $payment->amount,
                'status' =>
                    $payment->status?->value
                    ?? $payment->status,
                'invoice_status' =>
                    $invoice->status?->value
                    ?? $invoice->status,
                'invoice_paid_amount' =>
                    $invoice->paid_amount,
                'invoice_balance_due' =>
                    $invoice->balance_due,
            ]
        );

        return PaymentVerificationResult::success(
            payment: $payment,
            message:
                'Payment verified and financial transaction finalized successfully.',
        );
    }

    /**
     * Create the official receipt exactly once.
     *
     * PaymentReceiptService::create() requires a real User.
     *
     * For an online Chapa payment there is no human verifier,
     * therefore processedBy is used as the receipt issuer.
     */
    protected function createReceiptIfNecessary(
        Payment $payment
    ): void {
        /*
         * The Payment model is expected to define:
         *
         * public function receipt()
         *
         * as a hasOne relationship.
         */
        if ($payment->receipt()->exists()) {
            Log::info(
                'Receipt already exists for online payment.',
                [
                    'payment_id' =>
                        $payment->id,
                    'payment_uuid' =>
                        $payment->uuid ?? null,
                ]
            );

            return;
        }

        /*
         * The Payment model is expected to define:
         *
         * public function processedBy()
         *
         * as a belongsTo relationship to App\Models\User.
         */
        $paymentUser = $payment->processedBy;

        if (!$paymentUser instanceof User) {
            throw new RuntimeException(
                'A valid receipt issuer could not be determined for the online payment. The payment must have a processedBy user.'
            );
        }

        /*
         * PaymentReceiptService performs its own idempotency
         * check and creates the official receipt.
         */
        $receipt = $this->receiptService->create(
            payment: $payment,
            user: $paymentUser,
        );

        Log::info(
            'Official receipt created for online payment.',
            [
                'payment_id' =>
                    $payment->id,
                'payment_uuid' =>
                    $payment->uuid ?? null,
                'receipt_id' =>
                    $receipt->id,
                'receipt_number' =>
                    $receipt->receipt_number,
                'issued_by' =>
                    $receipt->issued_by,
            ]
        );
    }

    /**
     * Recalculate invoice paid amount and balance due
     * using completed payments only.
     */
    protected function applyPaymentToInvoice(
        $invoice
    ): void {
        $invoiceTotal = $this->normalizeMoney(
            $invoice->total_amount
        );

        /*
         * Only COMPLETED payments affect invoice financial state.
         */
        $completedPayments = Payment::query()
            ->where(
                'invoice_id',
                $invoice->id
            )
            ->where(
                'status',
                'COMPLETED'
            )
            ->get([
                'amount',
            ]);

        $paidAmount = '0.0000';

        foreach ($completedPayments as $completedPayment) {
            $paidAmount = bcadd(
                $paidAmount,
                $this->normalizeMoney(
                    $completedPayment->amount
                ),
                self::MONEY_SCALE
            );
        }

        /*
         * Prevent an invoice from becoming financially inconsistent.
         */
        if (
            bccomp(
                $paidAmount,
                $invoiceTotal,
                self::MONEY_SCALE
            ) === 1
        ) {
            throw new RuntimeException(
                'Completed payments exceed the invoice total.'
            );
        }

        $balanceDue = bcsub(
            $invoiceTotal,
            $paidAmount,
            self::MONEY_SCALE
        );

        /*
         * Avoid negative zero / precision artifacts.
         */
        if (
            bccomp(
                $balanceDue,
                '0',
                self::MONEY_SCALE
            ) <= 0
        ) {
            $balanceDue = '0.0000';
        }

        $invoice->paid_amount = $paidAmount;
        $invoice->balance_due = $balanceDue;

        /*
         * Determine invoice status.
         */
        if (
            bccomp(
                $balanceDue,
                '0',
                self::MONEY_SCALE
            ) === 0
        ) {
            $invoice->status = 'PAID';

            if (!$invoice->paid_at) {
                $invoice->paid_at = now();
            }
        } else {
            $invoice->status = 'PARTIALLY_PAID';
            $invoice->paid_at = null;
        }

        $invoice->save();
    }

    /**
     * Calculate invoice outstanding amount.
     *
     * Only COMPLETED payments are considered.
     */
    protected function calculateOutstandingAmount(
        $invoice
    ): string {
        $invoiceTotal = $this->normalizeMoney(
            $invoice->total_amount
        );

        $paidAmount = '0.0000';

        $completedPayments = Payment::query()
            ->where(
                'invoice_id',
                $invoice->id
            )
            ->where(
                'status',
                'COMPLETED'
            )
            ->get([
                'amount',
            ]);

        foreach ($completedPayments as $completedPayment) {
            $paidAmount = bcadd(
                $paidAmount,
                $this->normalizeMoney(
                    $completedPayment->amount
                ),
                self::MONEY_SCALE
            );
        }

        $outstanding = bcsub(
            $invoiceTotal,
            $paidAmount,
            self::MONEY_SCALE
        );

        /*
         * Never return a negative outstanding balance.
         */
        if (
            bccomp(
                $outstanding,
                '0',
                self::MONEY_SCALE
            ) <= 0
        ) {
            return '0.0000';
        }

        return $outstanding;
    }

    /**
     * Mark payment as failed.
     */
    protected function markFailed(
        Payment $payment,
        PaymentVerificationResult $result
    ): void {
        /*
         * Never downgrade a successful payment.
         */
        if ($payment->isSuccessful()) {
            Log::warning(
                'Attempted to mark successful payment as failed.',
                [
                    'payment_id' =>
                        $payment->id,
                    'provider_reference' =>
                        $payment->provider_reference,
                ]
            );

            return;
        }

        $payment->status = $result->status;

        if ($result->providerReference !== null) {
            $payment->provider_reference =
                $result->providerReference;
        }

        if ($result->transactionReference !== null) {
            $payment->transaction_reference =
                $result->transactionReference;
        }

        if ($result->metadata !== null) {
            $payment->provider_response =
                $result->metadata;
        }

        $payment->failure_reason =
            $result->message;

        $payment->save();

        Log::warning(
            'Online payment verification returned failed status.',
            [
                'payment_id' =>
                    $payment->id,
                'payment_uuid' =>
                    $payment->uuid ?? null,
                'provider' =>
                    $payment->provider?->value
                    ?? $payment->provider
                    ?? null,
                'provider_reference' =>
                    $payment->provider_reference,
                'transaction_reference' =>
                    $payment->transaction_reference,
                'status' =>
                    $payment->status?->value
                    ?? $payment->status,
                'failure_reason' =>
                    $payment->failure_reason,
            ]
        );
    }

    /**
     * Keep the payment pending when the provider
     * cannot yet determine the final state.
     */
    protected function markPending(
        Payment $payment,
        PaymentVerificationResult $result
    ): void {
        /*
         * Never downgrade a completed payment.
         */
        if ($payment->isSuccessful()) {
            return;
        }

        $payment->status = $result->status;

        if ($result->providerReference !== null) {
            $payment->provider_reference =
                $result->providerReference;
        }

        if ($result->transactionReference !== null) {
            $payment->transaction_reference =
                $result->transactionReference;
        }

        if ($result->metadata !== null) {
            $payment->provider_response =
                $result->metadata;
        }

        $payment->save();

        Log::info(
            'Online payment remains pending after provider verification.',
            [
                'payment_id' =>
                    $payment->id,
                'payment_uuid' =>
                    $payment->uuid ?? null,
                'provider' =>
                    $payment->provider?->value
                    ?? $payment->provider
                    ?? null,
                'provider_reference' =>
                    $payment->provider_reference,
                'transaction_reference' =>
                    $payment->transaction_reference,
                'status' =>
                    $payment->status?->value
                    ?? $payment->status,
            ]
        );
    }

    /**
     * Verify using a provider reference.
     *
     * This method expects the provider reference to already
     * be stored on payments.provider_reference.
     */
    public function verifyByReference(
        string $providerReference
    ): PaymentVerificationResult {
        $payment = Payment::query()
            ->where(
                'provider_reference',
                $providerReference
            )
            ->first();

        if (!$payment) {
            throw new RuntimeException(
                'Payment could not be found.'
            );
        }

        return $this->verify($payment);
    }

    /**
     * Normalize a monetary value to the configured precision.
     *
     * BCMath is used to avoid floating-point arithmetic.
     */
    protected function normalizeMoney(
        mixed $value
    ): string {
        if ($value === null || $value === '') {
            return '0.0000';
        }

        $value = trim((string) $value);

        /*
         * Accept normal decimal monetary values only.
         *
         * Examples:
         *
         * 100
         * 100.5
         * 100.50
         * 100.5000
         *
         * Reject:
         *
         * 1e3
         * 1E3
         * 1,000.00
         * invalid text
         * more than four decimal places
         */
        if (!preg_match(
            '/^-?\d+(?:\.\d{1,4})?$/',
            $value
        )) {
            throw new RuntimeException(
                'Invalid monetary value.'
            );
        }

        /*
         * Normalize through BCMath.
         */
        return bcadd(
            $value,
            '0',
            self::MONEY_SCALE
        );
    }
}
