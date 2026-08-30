<?php

namespace App\Modules\Payment\DTOs;

use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;

final readonly class PaymentResult
{
    public function __construct(
        /**
         * Whether the provider operation was accepted
         * successfully.
         *
         * This does NOT necessarily mean the payment itself
         * has been completed.
         */
        public bool $success,

        /**
         * Normalized payment status.
         */
        public PaymentStatus $status,

        /**
         * Provider used for the transaction.
         */
        public PaymentProvider $provider,

        /**
         * Your internal payment reference.
         */
        public string $paymentReference,

        /**
         * Amount associated with this payment operation.
         */
        public ?float $amount = null,

        /**
         * Currency associated with this payment.
         */
        public ?string $currency = null,

        /**
         * Provider-generated transaction reference.
         *
         * Example:
         * - Chapa tx_ref
         * - Telebirr transaction ID
         * - Bank reference
         */
        public ?string $providerReference = null,

        /**
         * Provider checkout/payment URL.
         *
         * Usually used by online payment providers.
         */
        public ?string $checkoutUrl = null,

        /**
         * Provider-specific external transaction ID.
         */
        public ?string $providerTransactionId = null,

        /**
         * Human-readable message.
         */
        public ?string $message = null,

        /**
         * Additional normalized provider data.
         *
         * Do not store secrets here.
         *
         * @var array<string, mixed>
         */
        public array $metadata = [],
    ) {
    }

    /**
     * Create a successful initialization result.
     *
     * IMPORTANT:
     * This means the provider accepted the initialization request.
     * It does NOT mean the customer has paid yet.
     */
    public static function success(
        PaymentProvider $provider,
        string $paymentReference,
        ?float $amount = null,
        ?string $currency = null,
        ?string $providerReference = null,
        ?string $checkoutUrl = null,
        ?string $providerTransactionId = null,
        ?string $message = null,
        array $metadata = [],
    ): self {
        return new self(
            success: true,

            status: PaymentStatus::PROCESSING,

            provider: $provider,

            paymentReference: $paymentReference,

            amount: $amount,

            currency: $currency,

            providerReference: $providerReference,

            checkoutUrl: $checkoutUrl,

            providerTransactionId: $providerTransactionId,

            message: $message,

            metadata: $metadata,
        );
    }

    /**
     * Create a result for a payment that is waiting
     * for manual verification.
     *
     * Used mainly by Bank Transfer and Cash.
     */
    public static function awaitingVerification(
        PaymentProvider $provider,
        string $paymentReference,
        ?float $amount = null,
        ?string $currency = null,
        ?string $providerReference = null,
        ?string $message = null,
        array $metadata = [],
    ): self {
        return new self(
            success: true,

            status: PaymentStatus::AWAITING_VERIFICATION,

            provider: $provider,

            paymentReference: $paymentReference,

            amount: $amount,

            currency: $currency,

            providerReference: $providerReference,

            message: $message,

            metadata: $metadata,
        );
    }

    /**
     * Create a completed payment result.
     *
     * This should only be used after the payment
     * has actually been verified.
     */
    public static function paid(
        PaymentProvider $provider,
        string $paymentReference,
        ?float $amount = null,
        ?string $currency = null,
        ?string $providerReference = null,
        ?string $providerTransactionId = null,
        ?string $message = null,
        array $metadata = [],
    ): self {
        return new self(
            success: true,

            status: PaymentStatus::PAID,

            provider: $provider,

            paymentReference: $paymentReference,

            amount: $amount,

            currency: $currency,

            providerReference: $providerReference,

            providerTransactionId: $providerTransactionId,

            message: $message,

            metadata: $metadata,
        );
    }

    /**
     * Create a failed payment result.
     */
    public static function failed(
        PaymentProvider $provider,
        string $paymentReference,
        ?string $message = null,
        ?float $amount = null,
        ?string $currency = null,
        ?string $providerReference = null,
        array $metadata = [],
    ): self {
        return new self(
            success: false,

            status: PaymentStatus::FAILED,

            provider: $provider,

            paymentReference: $paymentReference,

            amount: $amount,

            currency: $currency,

            providerReference: $providerReference,

            message: $message,

            metadata: $metadata,
        );
    }

    /**
     * Convert result to array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success' =>
                $this->success,

            'status' =>
                $this->status->value,

            'provider' =>
                $this->provider->value,

            'payment_reference' =>
                $this->paymentReference,

            'amount' =>
                $this->amount,

            'currency' =>
                $this->currency,

            'provider_reference' =>
                $this->providerReference,

            'checkout_url' =>
                $this->checkoutUrl,

            'provider_transaction_id' =>
                $this->providerTransactionId,

            'message' =>
                $this->message,

            'metadata' =>
                $this->metadata,
        ];
    }

    /**
     * Determine whether a checkout URL is available.
     */
    public function hasCheckoutUrl(): bool
    {
        return filled(
            $this->checkoutUrl
        );
    }

    /**
     * Determine whether the operation
     * represents a paid payment.
     */
    public function isPaid(): bool
    {
        return $this->status === PaymentStatus::PAID;
    }

    /**
     * Determine whether the payment requires
     * additional verification.
     */
    public function requiresVerification(): bool
    {
        return $this->status === PaymentStatus::AWAITING_VERIFICATION;
    }

    /**
     * Determine whether the provider operation
     * was successful.
     *
     * This is useful for PaymentService.
     */
    public function isSuccessful(): bool
    {
        return $this->success;
    }

    /**
     * Determine whether the payment is still
     * being processed.
     */
    public function isProcessing(): bool
    {
        return $this->status === PaymentStatus::PROCESSING;
    }

    /**
     * Determine whether the payment failed.
     */
    public function isFailed(): bool
    {
        return $this->status === PaymentStatus::FAILED;
    }
}