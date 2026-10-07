<?php

declare(strict_types=1);

namespace App\Modules\Payment\Services;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Modules\Payment\Contracts\PaymentProviderInterface;
use App\Modules\Payment\DTOs\PaymentVerificationResult;
use App\Modules\Payment\Factories\PaymentProviderFactory;
use App\Jobs\SendPaymentNotificationJob;
use App\Modules\Payment\Notifications\PaymentNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class PaymentVerificationService
{
    /**
     * Number of decimal places used for municipal
     * financial calculations.
     *
     * BCMath is used for all financial calculations.
     */
    protected const MONEY_SCALE = 4;

    /**
     * Invoice statuses that may receive a payment.
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
     * Verify a payment with its configured provider and finalize
     * the municipal financial transaction when successful.
     *
     * The provider is always queried independently.
     *
     * Browser callbacks/webhooks are only triggers.
     *
     * Flow:
     *
     * 1. Fast local idempotency check.
     * 2. Resolve provider.
     * 3. Verify with provider outside database transaction.
     * 4. Start database transaction.
     * 5. Lock payment.
     * 6. Re-check payment state.
     * 7. Validate provider result.
     * 8. Apply provider result.
     * 9. Commit.
     * 10. Notification job runs only after commit.
     */
    public function verify(
        Payment $payment
    ): PaymentVerificationResult {
        /*
         * ---------------------------------------------------------
         * 1. Fast idempotency check
         * ---------------------------------------------------------
         */

        if ($payment->isSuccessful()) {
            return $this->successfulResultFromPayment(
                $payment,
                'Payment has already been verified successfully.'
            );
        }

        /*
         * Do not downgrade a terminal payment.
         */
        if ($this->hasTerminalFailure($payment)) {
            return $this->failedResultFromPayment(
                $payment,
                'Payment has already reached a terminal failed state.'
            );
        }

        /*
         * ---------------------------------------------------------
         * Validate configured provider
         * ---------------------------------------------------------
         */

        $configuredProvider = $payment->payment_provider;

        if ($configuredProvider === null) {
            throw new RuntimeException(
                'The payment does not have a configured payment provider.'
            );
        }

        try {
            /*
             * -----------------------------------------------------
             * 2. Resolve provider
             * -----------------------------------------------------
             */

            /** @var PaymentProviderInterface $provider */
            $provider = $this->providerFactory->make(
                $configuredProvider
            );

            /*
             * -----------------------------------------------------
             * 3. Authoritative provider verification
             * -----------------------------------------------------
             *
             * IMPORTANT:
             *
             * Never hold a database lock while calling an
             * external provider.
             */
            $result = $provider->verify($payment);

            /*
             * -----------------------------------------------------
             * 4. Finalization transaction
             * -----------------------------------------------------
             *
             * Retry the transaction up to three times for
             * transient database deadlocks.
             */
            return DB::transaction(
                function () use (
                    $payment,
                    $result
                ): PaymentVerificationResult {
                    /*
                     * -------------------------------------------------
                     * 5. Lock payment
                     * -------------------------------------------------
                     */

                    $lockedPayment = Payment::query()
                        ->whereKey($payment->getKey())
                        ->lockForUpdate()
                        ->first();

                    if (!$lockedPayment) {
                        throw new RuntimeException(
                            'Payment could not be found during verification finalization.'
                        );
                    }

                    /*
                     * -------------------------------------------------
                     * 6. Re-check payment state
                     * -------------------------------------------------
                     *
                     * This protects against two webhook/callback
                     * requests verifying the same payment concurrently.
                     */

                    if ($lockedPayment->isSuccessful()) {
                        Log::info(
                            'Payment was already completed before verification finalization.',
                            $this->paymentLogContext($lockedPayment)
                        );

                        return $this->successfulResultFromPayment(
                            $lockedPayment->refresh(),
                            'Payment has already been verified successfully.'
                        );
                    }

                    /*
                     * Never downgrade a terminal payment.
                     */
                    if ($this->hasTerminalFailure($lockedPayment)) {
                        Log::warning(
                            'Payment was already in a terminal failed state before verification finalization.',
                            $this->paymentLogContext($lockedPayment)
                        );

                        return $this->failedResultFromPayment(
                            $lockedPayment->refresh(),
                            'Payment has already reached a terminal failed state.'
                        );
                    }

                    /*
                     * -------------------------------------------------
                     * Validate provider result
                     * -------------------------------------------------
                     */

                    $this->validateVerificationResult(
                        $lockedPayment,
                        $result
                    );

                    /*
                     * -------------------------------------------------
                     * 7. Apply provider result
                     * -------------------------------------------------
                     */

                    return $this->applyVerificationResult(
                        $lockedPayment,
                        $result
                    );
                },
                3
            );
        } catch (Throwable $exception) {
            Log::error(
                'Payment verification failed.',
                [
                    'payment_id' =>
                        $payment->getKey(),

                    'payment_number' =>
                        $payment->payment_number ?? null,

                    'provider' =>
                        $this->providerValue(
                            $payment->payment_provider
                        ),

                    'provider_reference' =>
                        $payment->provider_reference ?? null,

                    'transaction_reference' =>
                        $payment->transaction_reference ?? null,

                    'exception' =>
                        $exception::class,

                    'message' =>
                        $exception->getMessage(),
                ]
            );

            throw $exception;
        }
    }

    /**
     * Validate the normalized provider result against
     * the local payment.
     */
    protected function validateVerificationResult(
        Payment $payment,
        PaymentVerificationResult $result
    ): void {
        /*
         * ---------------------------------------------------------
         * Transaction reference validation
         * ---------------------------------------------------------
         */

        if (
            $result->transactionReference !== null
            && $payment->transaction_reference !== null
        ) {
            $localReference = trim(
                (string) $payment->transaction_reference
            );

            $providerReference = trim(
                (string) $result->transactionReference
            );

            if (
                $localReference !== ''
                && $providerReference !== ''
                && !hash_equals(
                    $localReference,
                    $providerReference
                )
            ) {
                throw new RuntimeException(
                    'The provider transaction reference does not match the local payment transaction reference.'
                );
            }
        }

        /*
         * ---------------------------------------------------------
         * Successful payment validation
         * ---------------------------------------------------------
         */

        if (!$result->isSuccessful()) {
            return;
        }

        /*
         * A successful result must contain an amount.
         */
        if ($result->amount === null) {
            throw new RuntimeException(
                'The successful provider verification result does not contain a payment amount.'
            );
        }

        /*
         * ---------------------------------------------------------
         * Validate positive amounts
         * ---------------------------------------------------------
         */

        $localAmount = $this->normalizeMoney(
            $payment->amount
        );

        $providerAmount = $this->normalizeMoney(
            $result->amount
        );

        if (
            bccomp(
                $localAmount,
                '0',
                self::MONEY_SCALE
            ) <= 0
        ) {
            throw new RuntimeException(
                'The local payment amount must be greater than zero.'
            );
        }

        if (
            bccomp(
                $providerAmount,
                '0',
                self::MONEY_SCALE
            ) <= 0
        ) {
            throw new RuntimeException(
                'The provider payment amount must be greater than zero.'
            );
        }

        /*
         * ---------------------------------------------------------
         * Amount validation
         * ---------------------------------------------------------
         */

        if (
            bccomp(
                $localAmount,
                $providerAmount,
                self::MONEY_SCALE
            ) !== 0
        ) {
            throw new RuntimeException(
                'The provider payment amount does not match the local payment amount.'
            );
        }

        /*
         * ---------------------------------------------------------
         * Currency validation
         * ---------------------------------------------------------
         */

        if ($result->currency !== null) {
            $localCurrency = strtoupper(
                trim(
                    (string) $payment->currency
                )
            );

            $providerCurrency = strtoupper(
                trim(
                    (string) $result->currency
                )
            );

            if (
                $localCurrency !== ''
                && $providerCurrency !== ''
                && $localCurrency !== $providerCurrency
            ) {
                throw new RuntimeException(
                    'The provider payment currency does not match the local payment currency.'
                );
            }
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
     * Mark payment as successfully completed and finalize
     * the associated municipal financial transaction.
     */
    protected function markSuccessful(
        Payment $payment,
        PaymentVerificationResult $result
    ): PaymentVerificationResult {
        /*
         * ---------------------------------------------------------
         * Idempotency protection
         * ---------------------------------------------------------
         */

        if ($payment->isSuccessful()) {
            return $this->successfulResultFromPayment(
                $payment->refresh(),
                'Payment has already been verified successfully.'
            );
        }

        /*
         * ---------------------------------------------------------
         * Lock invoice
         * ---------------------------------------------------------
         */

        $invoice = $payment->invoice()
            ->lockForUpdate()
            ->first();

        if (!$invoice instanceof Invoice) {
            throw new RuntimeException(
                'The invoice associated with this payment could not be found.'
            );
        }

        /*
         * ---------------------------------------------------------
         * Validate invoice state
         * ---------------------------------------------------------
         */

        $invoiceStatus = $this->enumOrStringValue(
            $invoice->status
        );

        if ($invoiceStatus !== null) {
            $normalizedInvoiceStatus = strtoupper(
                $invoiceStatus
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
         * ---------------------------------------------------------
         * Calculate authoritative outstanding balance
         * ---------------------------------------------------------
         */

        $outstandingAmount =
            $this->calculateOutstandingAmount(
                $invoice
            );

        $paymentAmount = $this->normalizeMoney(
            $result->amount
                ?? $payment->amount
        );

        /*
         * ---------------------------------------------------------
         * Prevent overpayment
         * ---------------------------------------------------------
         */

        if (
            bccomp(
                $paymentAmount,
                $outstandingAmount,
                self::MONEY_SCALE
            ) === 1
        ) {
            Log::warning(
                'Payment overpayment validation failed.',
                [
                    'payment_id' =>
                        $payment->id,

                    'payment_number' =>
                        $payment->payment_number ?? null,

                    'invoice_id' =>
                        $invoice->id,

                    'invoice_number' =>
                        $invoice->invoice_number ?? null,

                    'payment_amount' =>
                        $paymentAmount,

                    'outstanding_amount' =>
                        $outstandingAmount,

                    'money_scale' =>
                        self::MONEY_SCALE,
                ]
            );

            throw new RuntimeException(
                'The verified payment amount exceeds the outstanding invoice balance.'
            );
        }

        /*
         * ---------------------------------------------------------
         * Update common Payment record
         * ---------------------------------------------------------
         */

        $payment->status =
            PaymentStatus::COMPLETED;

        /*
         * Store provider reference when available.
         */
        if (
            $result->providerReference !== null
            && trim(
                (string) $result->providerReference
            ) !== ''
        ) {
            $payment->provider_reference =
                trim(
                    (string) $result->providerReference
                );
        }

        /*
         * Store canonical transaction reference.
         */
        if (
            $result->transactionReference !== null
            && trim(
                (string) $result->transactionReference
            ) !== ''
        ) {
            $payment->transaction_reference =
                trim(
                    (string) $result->transactionReference
                );
        }

        /*
         * Municipal verification/finalization timestamp.
         */
        $payment->verified_at = now();

        /*
         * Successful payment cannot have a failure reason.
         */
        $payment->failure_reason = null;

        $payment->save();

        /*
         * ---------------------------------------------------------
         * Update online payment details
         * ---------------------------------------------------------
         */

        $this->updateOnlinePaymentDetails(
            $payment,
            $result
        );

        /*
         * Refresh payment after persistence.
         */
        $payment->refresh();

        /*
         * ---------------------------------------------------------
         * Create official receipt
         * ---------------------------------------------------------
         */

        $this->createReceiptIfNecessary(
            $payment
        );

        /*
         * ---------------------------------------------------------
         * Recalculate invoice
         * ---------------------------------------------------------
         */

        $this->applyPaymentToInvoice(
            $invoice
        );

        /*
         * ---------------------------------------------------------
         * Reload final state
         * ---------------------------------------------------------
         */

        $payment->refresh();
        $invoice->refresh();

        /*
         * ---------------------------------------------------------
         * Determine taxpayer notification
         * ---------------------------------------------------------
         *
         * Exactly ONE successful-payment notification is sent.
         *
         * Example:
         *
         * Invoice total:       1,000
         * Previous payments:     600
         * Current payment:       200
         * Remaining balance:     200
         *
         * SMS:
         *
         *     PAYMENT_PARTIALLY_PAID
         *
         * If remaining balance becomes zero:
         *
         *     PAYMENT_FULLY_PAID
         *
         * We intentionally do NOT send:
         *
         *     PAYMENT_RECEIVED
         *
         * plus:
         *
         *     PAYMENT_PARTIALLY_PAID
         *
         * because that would create two SMS messages for one payment.
         */

        $notificationType =
            bccomp(
                $this->normalizeMoney(
                    $invoice->balance_due
                ),
                '0',
                self::MONEY_SCALE
            ) === 0
                ? PaymentNotificationService::PAYMENT_FULLY_PAID
                : PaymentNotificationService::PAYMENT_PARTIALLY_PAID;

        /*
         * IMPORTANT:
         *
         * The SMS job is queued with afterCommit().
         *
         * Therefore:
         *
         *     DB transaction succeeds
         *             ↓
         *     database commits
         *             ↓
         *     SMS job becomes available
         *
         * If the financial transaction rolls back, the SMS
         * notification is not dispatched.
         */
        $this->dispatchPaymentNotification(
            payment: $payment,
            notificationType: $notificationType,
        );

        /*
         * ---------------------------------------------------------
         * Logging
         * ---------------------------------------------------------
         */

        Log::info(
            'Payment successfully verified and finalized.',
            [
                'payment_id' =>
                    $payment->id,

                'payment_number' =>
                    $payment->payment_number ?? null,

                'invoice_id' =>
                    $invoice->id,

                'invoice_number' =>
                    $invoice->invoice_number ?? null,

                'provider' =>
                    $this->providerValue(
                        $payment->payment_provider
                    ),

                'provider_reference' =>
                    $payment->provider_reference,

                'transaction_reference' =>
                    $payment->transaction_reference,

                'amount' =>
                    $payment->amount,

                'currency' =>
                    $payment->currency,

                'status' =>
                    $this->enumOrStringValue(
                        $payment->status
                    ),

                'verified_at' =>
                    $payment->verified_at?->toISOString(),

                'invoice_status' =>
                    $this->enumOrStringValue(
                        $invoice->status
                    ),

                'invoice_paid_amount' =>
                    $invoice->paid_amount,

                'invoice_balance_due' =>
                    $invoice->balance_due,

                'notification_type' =>
                    $notificationType,
            ]
        );

        return $this->successfulResultFromPayment(
            $payment,
            'Payment verified and financial transaction finalized successfully.',
            $result
        );
    }

    /**
     * Dispatch taxpayer payment notification after
     * successful database commit.
     */
    protected function dispatchPaymentNotification(
        Payment $payment,
        string $notificationType,
    ): void {
        Log::info(
            'Queueing payment notification after database commit.',
            [
                'payment_id' =>
                    $payment->id,

                'payment_number' =>
                    $payment->payment_number ?? null,

                'notification_type' =>
                    $notificationType,
            ]
        );

        SendPaymentNotificationJob::dispatch(
            paymentId: (string) $payment->getKey(),
            notificationType: $notificationType,
        )->afterCommit();
    }

    /**
     * Update provider-specific online payment information.
     *
     * Provider-specific fields belong in online_payment_details.
     */
    protected function updateOnlinePaymentDetails(
        Payment $payment,
        PaymentVerificationResult $result
    ): void {
        if (!$this->isOnlinePayment($payment)) {
            return;
        }

        $onlineDetails = $payment->onlineDetails()
            ->lockForUpdate()
            ->first();

        if ($onlineDetails === null) {
            Log::error(
                'Online payment detail record is missing during successful payment finalization.',
                [
                    'payment_id' =>
                        $payment->id,

                    'payment_number' =>
                        $payment->payment_number ?? null,

                    'provider' =>
                        $this->providerValue(
                            $payment->payment_provider
                        ),

                    'transaction_reference' =>
                        $payment->transaction_reference,
                ]
            );

            return;
        }

        /*
         * Provider transaction ID.
         */
        if (
            $result->transactionId !== null
            && trim(
                (string) $result->transactionId
            ) !== ''
        ) {
            $onlineDetails->provider_transaction_id =
                trim(
                    (string) $result->transactionId
                );
        }

        /*
         * Provider status.
         */
        $providerStatus =
            $this->providerStatusFromResult(
                $result
            );

        if ($providerStatus !== null) {
            $onlineDetails->provider_status =
                $providerStatus;
        } else {
            $onlineDetails->provider_status =
                PaymentStatus::COMPLETED->value;
        }

        /*
         * IMPORTANT:
         *
         * Do not use now() as the provider paid_at timestamp.
         *
         * If the provider supplies a real paidAt timestamp,
         * persist it.
         *
         * If it does not, preserve an already existing timestamp
         * written by the webhook/callback layer.
         */
        if ($result->paidAt !== null) {
            $onlineDetails->paid_at =
                $result->paidAt;
        }

        /*
         * Provider-specific verification response.
         */
        $onlineDetails->provider_response =
            $this->mergeProviderResponse(
                $onlineDetails->provider_response,
                $result->metadata
            );

        /*
         * Preserve existing checkout reference.
         */
        if (
            empty(
                $onlineDetails->checkout_reference
            )
            && $result->providerReference !== null
            && trim(
                (string) $result->providerReference
            ) !== ''
        ) {
            $onlineDetails->checkout_reference =
                trim(
                    (string) $result->providerReference
                );
        }

        $onlineDetails->save();

        Log::info(
            'Online payment details updated after successful verification.',
            [
                'payment_id' =>
                    $payment->id,

                'payment_number' =>
                    $payment->payment_number ?? null,

                'online_payment_detail_id' =>
                    $onlineDetails->id,

                'provider' =>
                    $this->providerValue(
                        $payment->payment_provider
                    ),

                'provider_transaction_id' =>
                    $onlineDetails->provider_transaction_id,

                'provider_status' =>
                    $onlineDetails->provider_status,

                'paid_at' =>
                    $onlineDetails->paid_at?->toISOString(),
            ]
        );
    }

    /**
     * Create the official municipal receipt exactly once.
     */
    protected function createReceiptIfNecessary(
        Payment $payment
    ): void {
        /*
         * Idempotency check.
         */
        if ($payment->receipt()->exists()) {
            Log::info(
                'Receipt already exists for payment.',
                [
                    'payment_id' =>
                        $payment->id,

                    'payment_number' =>
                        $payment->payment_number ?? null,
                ]
            );

            return;
        }

        /*
         * Online payments use processedBy as the receipt issuer.
         */
        $paymentUser = $payment->processedBy;

        if (!$paymentUser instanceof User) {
            throw new RuntimeException(
                'A valid receipt issuer could not be determined for the payment. The payment must have a processedBy user.'
            );
        }

        $receipt = $this->receiptService->create(
            payment: $payment,
            user: $paymentUser,
        );

        Log::info(
            'Official receipt created for payment.',
            [
                'payment_id' =>
                    $payment->id,

                'payment_number' =>
                    $payment->payment_number ?? null,

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
     * Recalculate invoice financial state using COMPLETED
     * payments only.
     */
    protected function applyPaymentToInvoice(
        Invoice $invoice
    ): void {
        $invoiceTotal = $this->normalizeMoney(
            $invoice->total_amount
        );

        /*
         * Only completed payments affect invoice balances.
         */
        $completedPayments = Payment::query()
            ->where(
                'invoice_id',
                $invoice->id
            )
            ->where(
                'status',
                PaymentStatus::COMPLETED->value
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
         * Never allow completed payments to exceed
         * invoice total.
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

        /*
         * Calculate remaining balance.
         */
        $balanceDue = bcsub(
            $invoiceTotal,
            $paidAmount,
            self::MONEY_SCALE
        );

        /*
         * Prevent negative zero / precision artifacts.
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

        $invoice->paid_amount =
            $paidAmount;

        $invoice->balance_due =
            $balanceDue;

        /*
         * Invoice status.
         */
        if (
            bccomp(
                $balanceDue,
                '0',
                self::MONEY_SCALE
            ) === 0
        ) {
            $invoice->status =
                'PAID';

            if (!$invoice->paid_at) {
                $invoice->paid_at =
                    now();
            }
        } else {
            $invoice->status =
                'PARTIALLY_PAID';

            $invoice->paid_at =
                null;
        }

        $invoice->save();
    }

    /**
     * Calculate invoice outstanding balance using
     * COMPLETED payments only.
     */
    protected function calculateOutstandingAmount(
        Invoice $invoice
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
                PaymentStatus::COMPLETED->value
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

        /*
         * Protect against historical data corruption.
         */
        if (
            bccomp(
                $paidAmount,
                $invoiceTotal,
                self::MONEY_SCALE
            ) >= 0
        ) {
            return '0.0000';
        }

        $outstanding = bcsub(
            $invoiceTotal,
            $paidAmount,
            self::MONEY_SCALE
        );

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
     *
     * Failed payments do not affect invoice balances.
     */
    protected function markFailed(
        Payment $payment,
        PaymentVerificationResult $result
    ): void {
        /*
         * Never downgrade successful payment.
         */
        if ($payment->isSuccessful()) {
            Log::warning(
                'Attempted to mark successful payment as failed.',
                $this->paymentLogContext($payment)
            );

            return;
        }

        if (!$result->isFailed()) {
            throw new RuntimeException(
                'markFailed() received a non-failed verification result.'
            );
        }

        /*
         * Store terminal provider status.
         */
        $payment->status =
            $result->status;

        /*
         * Provider reference.
         */
        if (
            $result->providerReference !== null
            && trim(
                (string) $result->providerReference
            ) !== ''
        ) {
            $payment->provider_reference =
                trim(
                    (string) $result->providerReference
                );
        }

        /*
         * Transaction reference.
         */
        if (
            $result->transactionReference !== null
            && trim(
                (string) $result->transactionReference
            ) !== ''
        ) {
            $payment->transaction_reference =
                trim(
                    (string) $result->transactionReference
                );
        }

        /*
         * Failure reason.
         */
        $payment->failure_reason =
            $result->message;

        /*
         * Failed payment is not municipally verified.
         */
        $payment->verified_at = null;

        $payment->save();

        /*
         * Synchronize provider-specific state.
         */
        $this->updateOnlinePaymentDetailsForNonSuccessfulResult(
            $payment,
            $result
        );

        /*
         * ---------------------------------------------------------
         * Failure notification
         * ---------------------------------------------------------
         *
         * Queue only after the transaction commits.
         */
        $this->dispatchPaymentNotification(
            payment: $payment,
            notificationType:
                PaymentNotificationService::PAYMENT_FAILED,
        );

        Log::warning(
            'Payment verification returned failed status.',
            [
                'payment_id' =>
                    $payment->id,

                'payment_number' =>
                    $payment->payment_number ?? null,

                'provider' =>
                    $this->providerValue(
                        $payment->payment_provider
                    ),

                'provider_reference' =>
                    $payment->provider_reference,

                'transaction_reference' =>
                    $payment->transaction_reference,

                'status' =>
                    $this->enumOrStringValue(
                        $payment->status
                    ),

                'failure_reason' =>
                    $payment->failure_reason,

                'notification_type' =>
                    PaymentNotificationService::PAYMENT_FAILED,
            ]
        );
    }

    /**
     * Keep payment pending when the provider cannot yet
     * determine a final state.
     *
     * No notification is sent for pending.
     */
    protected function markPending(
        Payment $payment,
        PaymentVerificationResult $result
    ): void {
        /*
         * Never downgrade completed payment.
         */
        if ($payment->isSuccessful()) {
            Log::info(
                'Provider returned pending result for an already successful payment.',
                $this->paymentLogContext($payment)
            );

            return;
        }

        if (!$result->isPending()) {
            throw new RuntimeException(
                'markPending() received a non-pending verification result.'
            );
        }

        $payment->status =
            $result->status;

        /*
         * Provider reference.
         */
        if (
            $result->providerReference !== null
            && trim(
                (string) $result->providerReference
            ) !== ''
        ) {
            $payment->provider_reference =
                trim(
                    (string) $result->providerReference
                );
        }

        /*
         * Transaction reference.
         */
        if (
            $result->transactionReference !== null
            && trim(
                (string) $result->transactionReference
            ) !== ''
        ) {
            $payment->transaction_reference =
                trim(
                    (string) $result->transactionReference
                );
        }

        /*
         * Pending is not a failure.
         */
        $payment->failure_reason =
            null;

        /*
         * Pending is not municipally verified.
         */
        $payment->verified_at =
            null;

        $payment->save();

        /*
         * Synchronize provider-specific state.
         */
        $this->updateOnlinePaymentDetailsForNonSuccessfulResult(
            $payment,
            $result
        );

        /*
         * No SMS for PENDING.
         *
         * Providers can return PENDING repeatedly.
         */
        Log::info(
            'Payment remains pending after provider verification.',
            [
                'payment_id' =>
                    $payment->id,

                'payment_number' =>
                    $payment->payment_number ?? null,

                'provider' =>
                    $this->providerValue(
                        $payment->payment_provider
                    ),

                'provider_reference' =>
                    $payment->provider_reference,

                'transaction_reference' =>
                    $payment->transaction_reference,

                'status' =>
                    $this->enumOrStringValue(
                        $payment->status
                    ),
            ]
        );
    }

    /**
     * Update online payment details for failed/pending results.
     */
    protected function updateOnlinePaymentDetailsForNonSuccessfulResult(
        Payment $payment,
        PaymentVerificationResult $result
    ): void {
        if (!$this->isOnlinePayment($payment)) {
            return;
        }

        $onlineDetails = $payment->onlineDetails()
            ->lockForUpdate()
            ->first();

        if ($onlineDetails === null) {
            Log::error(
                'Online payment detail record is missing while synchronizing provider verification result.',
                [
                    'payment_id' =>
                        $payment->id,

                    'payment_number' =>
                        $payment->payment_number ?? null,

                    'provider' =>
                        $this->providerValue(
                            $payment->payment_provider
                        ),
                ]
            );

            return;
        }

        /*
         * Provider transaction ID.
         */
        if (
            $result->transactionId !== null
            && trim(
                (string) $result->transactionId
            ) !== ''
        ) {
            $onlineDetails->provider_transaction_id =
                trim(
                    (string) $result->transactionId
                );
        }

        /*
         * Provider status.
         */
        $providerStatus =
            $this->providerStatusFromResult(
                $result
            );

        if ($providerStatus !== null) {
            $onlineDetails->provider_status =
                $providerStatus;
        } else {
            $onlineDetails->provider_status =
                $result->status->value;
        }

        /*
         * Pending/failed transactions are not paid.
         */
        $onlineDetails->paid_at =
            null;

        /*
         * Provider response.
         */
        $onlineDetails->provider_response =
            $this->mergeProviderResponse(
                $onlineDetails->provider_response,
                $result->metadata
            );

        /*
         * Preserve provider reference as checkout reference
         * if currently empty.
         */
        if (
            empty(
                $onlineDetails->checkout_reference
            )
            && $result->providerReference !== null
            && trim(
                (string) $result->providerReference
            ) !== ''
        ) {
            $onlineDetails->checkout_reference =
                trim(
                    (string) $result->providerReference
                );
        }

        $onlineDetails->save();
    }

    /**
     * Verify a payment using its provider reference.
     */
    public function verifyByReference(
        string $providerReference
    ): PaymentVerificationResult {
        $providerReference = trim(
            $providerReference
        );

        if ($providerReference === '') {
            throw new RuntimeException(
                'A provider reference is required.'
            );
        }

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

        return $this->verify(
            $payment
        );
    }

    /**
     * Build successful DTO from an already-completed payment.
     */
    protected function successfulResultFromPayment(
        Payment $payment,
        string $message,
        ?PaymentVerificationResult $source = null
    ): PaymentVerificationResult {
        $onlineDetails = null;

        if ($this->isOnlinePayment($payment)) {
            $onlineDetails =
                $payment->relationLoaded('onlineDetails')
                    ? $payment->onlineDetails
                    : $payment->onlineDetails()->first();
        }

        $paidAt =
            $source?->paidAt
            ?? $onlineDetails?->paid_at;

        $transactionId =
            $source?->transactionId
            ?? $onlineDetails?->provider_transaction_id;

        $metadata =
            is_array(
                $onlineDetails?->provider_response
            )
                ? $onlineDetails->provider_response
                : [];

        return PaymentVerificationResult::success(
            status:
                PaymentStatus::COMPLETED,

            transactionReference:
                $payment->transaction_reference,

            transactionId:
                $transactionId,

            providerReference:
                $payment->provider_reference,

            amount:
                $payment->amount !== null
                    ? (float) $payment->amount
                    : null,

            currency:
                $payment->currency !== null
                    ? strtoupper(
                        trim(
                            (string) $payment->currency
                        )
                    )
                    : null,

            paidAt:
                $paidAt,

            metadata:
                $metadata,

            message:
                $message,

            providerCode:
                $source?->providerCode,
        );
    }

    /**
     * Create failed DTO from an already-terminal failed payment.
     */
    protected function failedResultFromPayment(
        Payment $payment,
        string $message
    ): PaymentVerificationResult {
        $onlineDetails = null;

        if ($this->isOnlinePayment($payment)) {
            $onlineDetails =
                $payment->relationLoaded('onlineDetails')
                    ? $payment->onlineDetails
                    : $payment->onlineDetails()->first();
        }

        return PaymentVerificationResult::failed(
            status:
                $payment->status,

            message:
                $message,

            transactionReference:
                $payment->transaction_reference,

            transactionId:
                $onlineDetails?->provider_transaction_id,

            providerReference:
                $payment->provider_reference,

            amount:
                $payment->amount !== null
                    ? (float) $payment->amount
                    : null,

            currency:
                $payment->currency !== null
                    ? strtoupper(
                        trim(
                            (string) $payment->currency
                        )
                    )
                    : null,

            metadata:
                is_array(
                    $onlineDetails?->provider_response
                )
                    ? $onlineDetails->provider_response
                    : [],
        );
    }

    /**
     * Determine whether the payment is online.
     */
    protected function isOnlinePayment(
        Payment $payment
    ): bool {
        $method = $payment->payment_method;

        if ($method instanceof PaymentMethod) {
            return $method === PaymentMethod::ONLINE;
        }

        return strtoupper(
            trim(
                (string) $method
            )
        ) === PaymentMethod::ONLINE->value;
    }

    /**
     * Determine whether a payment has reached
     * a terminal failure state.
     *
     * Explicitly handles all terminal non-success states.
     */
    protected function hasTerminalFailure(
        Payment $payment
    ): bool {
        $status = $payment->status;

        if ($status instanceof PaymentStatus) {
            return in_array(
                $status,
                [
                    PaymentStatus::FAILED,
                    PaymentStatus::CANCELLED,
                    PaymentStatus::EXPIRED,
                    PaymentStatus::REVERSED,
                ],
                true
            );
        }

        return in_array(
            strtoupper(
                trim(
                    (string) $status
                )
            ),
            [
                PaymentStatus::FAILED->value,
                PaymentStatus::CANCELLED->value,
                PaymentStatus::EXPIRED->value,
                PaymentStatus::REVERSED->value,
            ],
            true
        );
    }

    /**
     * Extract the provider's original/normalized status
     * from verification metadata.
     */
    protected function providerStatusFromResult(
        PaymentVerificationResult $result
    ): ?string {
        $status =
            $result->metadata['verification']['status']
            ?? null;

        if (
            is_string($status)
            || is_numeric($status)
        ) {
            $status = strtoupper(
                trim(
                    (string) $status
                )
            );

            return $status !== ''
                ? $status
                : null;
        }

        return null;
    }

    /**
     * Merge provider response metadata.
     */
    protected function mergeProviderResponse(
        mixed $existing,
        mixed $new
    ): array {
        $existingArray =
            is_array($existing)
                ? $existing
                : [];

        $newArray =
            is_array($new)
                ? $new
                : [];

        if ($existingArray === []) {
            return $newArray;
        }

        if ($newArray === []) {
            return $existingArray;
        }

        return array_replace_recursive(
            $existingArray,
            $newArray
        );
    }

    /**
     * Return safe provider value for logs.
     */
    protected function providerValue(
        mixed $provider
    ): ?string {
        if ($provider === null) {
            return null;
        }

        if ($provider instanceof \BackedEnum) {
            return (string) $provider->value;
        }

        $value = trim(
            (string) $provider
        );

        return $value !== ''
            ? $value
            : null;
    }

    /**
     * Normalize enum/string model attributes.
     */
    protected function enumOrStringValue(
        mixed $value
    ): ?string {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        $value = trim(
            (string) $value
        );

        return $value !== ''
            ? $value
            : null;
    }

    /**
     * Normalize monetary values using BCMath.
     */
    protected function normalizeMoney(
        mixed $value
    ): string {
        if (
            !function_exists('bcadd')
            || !function_exists('bccomp')
            || !function_exists('bcsub')
        ) {
            throw new RuntimeException(
                'BCMath extension is required for municipal payment calculations.'
            );
        }

        if ($value === null || $value === '') {
            return '0.0000';
        }

        if (
            is_array($value)
            || is_object($value)
            || is_bool($value)
        ) {
            throw new RuntimeException(
                'Invalid monetary value.'
            );
        }

        $value = trim(
            (string) $value
        );

        /*
         * Accepted:
         *
         * 100
         * 100.5
         * 100.50
         * 100.5000
         * -100
         *
         * Negative values are syntactically accepted here because
         * this is a generic monetary normalization helper.
         *
         * Payment-specific positive-value validation is performed
         * separately.
         */
        if (!preg_match(
            '/^-?\d+(?:\.\d{1,4})?$/',
            $value
        )) {
            throw new RuntimeException(
                'Invalid monetary value.'
            );
        }

        return bcadd(
            $value,
            '0',
            self::MONEY_SCALE
        );
    }

    /**
     * Build consistent and privacy-conscious payment
     * logging context.
     *
     * Never log:
     *
     * - payer email
     * - payer phone
     * - access tokens
     * - provider secrets
     * - full provider payloads
     */
    protected function paymentLogContext(
        Payment $payment
    ): array {
        return [
            'payment_id' =>
                $payment->id,

            'payment_number' =>
                $payment->payment_number ?? null,

            'provider' =>
                $this->providerValue(
                    $payment->payment_provider
                ),

            'provider_reference' =>
                $payment->provider_reference ?? null,

            'transaction_reference' =>
                $payment->transaction_reference ?? null,

            'status' =>
                $this->enumOrStringValue(
                    $payment->status
                ),

            'verified_at' =>
                $payment->verified_at?->toISOString(),
        ];
    }
}