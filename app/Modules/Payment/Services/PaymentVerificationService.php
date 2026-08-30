<?php

namespace App\Modules\Payment\Services;

use App\Modules\Payment\Contracts\PaymentProviderInterface;
use App\Modules\Payment\DTOs\PaymentVerificationResult;
use App\Modules\Payment\Factories\PaymentProviderFactory;
use App\Modules\Payment\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class PaymentVerificationService
{
    public function __construct(
        protected PaymentProviderFactory $providerFactory,
    ) {
    }

    /**
     * Verify a payment with its configured provider.
     *
     * This method is intentionally provider-agnostic.
     *
     * The service:
     * 1. Loads the payment.
     * 2. Resolves the correct provider.
     * 3. Asks the provider to verify the transaction.
     * 4. Updates the local payment state.
     * 5. Returns a normalized verification result.
     */
    public function verify(
        Payment $payment
    ): PaymentVerificationResult {
        if ($payment->isSuccessful()) {
            return PaymentVerificationResult::success(
                payment: $payment,
                message: 'Payment has already been verified successfully.',
            );
        }

        if ($payment->isFailed()) {
            return PaymentVerificationResult::failed(
                payment: $payment,
                message: 'Payment has already been marked as failed.',
            );
        }

        try {
            /** @var PaymentProviderInterface $provider */
            $provider = $this->providerFactory->make(
                $payment->provider
            );

            $result = $provider->verify($payment);

            return DB::transaction(
                function () use ($payment, $result) {
                    $this->applyVerificationResult(
                        $payment,
                        $result
                    );

                    return $result;
                }
            );
        } catch (Throwable $exception) {
            Log::error('Payment verification failed.', [
                'payment_id' => $payment->id,
                'payment_uuid' => $payment->uuid ?? null,
                'provider' => $payment->provider?->value
                    ?? $payment->provider
                    ?? null,
                'reference' => $payment->provider_reference ?? null,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /**
     * Apply the normalized provider verification result
     * to the local payment record.
     */
    protected function applyVerificationResult(
        Payment $payment,
        PaymentVerificationResult $result
    ): void {
        if ($result->isSuccessful()) {
            $this->markSuccessful(
                $payment,
                $result
            );

            return;
        }

        if ($result->isFailed()) {
            $this->markFailed(
                $payment,
                $result
            );

            return;
        }

        $this->markPending(
            $payment,
            $result
        );
    }

    /**
     * Mark payment as successfully paid.
     */
    protected function markSuccessful(
        Payment $payment,
        PaymentVerificationResult $result
    ): void {
        /*
         * Never blindly overwrite an already successful payment.
         *
         * This protects the payment state from duplicate webhook
         * or verification requests.
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

        if ($result->paidAt !== null) {
            $payment->paid_at = $result->paidAt;
        }

        if ($result->metadata !== null) {
            $payment->provider_response = $result->metadata;
        }

        $payment->save();
    }

    /**
     * Mark payment as failed.
     */
    protected function markFailed(
        Payment $payment,
        PaymentVerificationResult $result
    ): void {
        /*
         * Do not downgrade a successful payment.
         */
        if ($payment->isSuccessful()) {
            Log::warning(
                'Attempted to mark successful payment as failed.',
                [
                    'payment_id' => $payment->id,
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
            $payment->provider_response = $result->metadata;
        }

        $payment->failure_reason =
            $result->message;

        $payment->save();
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
            $payment->provider_response = $result->metadata;
        }

        $payment->save();
    }

    /**
     * Verify using a provider reference.
     *
     * Useful when the frontend or webhook only has
     * the provider transaction reference.
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
}