<?php

namespace App\Modules\Payment\DTOs;

use App\Enums\PaymentStatus;

/**
 * Represents the normalized result of a payment verification
 * performed by any payment provider.
 *
 * Provider-specific responses must be converted into this DTO
 * before reaching the core payment domain.
 */
final readonly class PaymentVerificationResult
{
    public function __construct(
        /**
         * Whether the verification request itself was successful.
         *
         * This does NOT necessarily mean the payment succeeded.
         */
        public bool $verified,

        /**
         * Normalized payment status.
         */
        public PaymentStatus $status,

        /**
         * Provider transaction/reference ID.
         */
        public ?string $transactionId = null,

        /**
         * Provider's reference ID.
         */
        public ?string $providerReference = null,

        /**
         * Amount confirmed by the provider.
         */
        public ?float $amount = null,

        /**
         * Currency returned by the provider.
         */
        public ?string $currency = null,

        /**
         * Optional payment metadata returned by the provider.
         *
         * This may contain useful provider-specific information,
         * but the core payment logic must not depend on it.
         */
        public array $metadata = [],

        /**
         * Human-readable verification message.
         */
        public ?string $message = null,

        /**
         * Provider response code, when available.
         */
        public ?string $providerCode = null,
    ) {
    }

    /**
     * Create a successful verification result.
     */
    public static function success(
        PaymentStatus $status = PaymentStatus::PAID,
        ?string $transactionId = null,
        ?string $providerReference = null,
        ?float $amount = null,
        ?string $currency = null,
        array $metadata = [],
        ?string $message = null,
        ?string $providerCode = null,
    ): self {
        return new self(
            verified: true,
            status: $status,
            transactionId: $transactionId,
            providerReference: $providerReference,
            amount: $amount,
            currency: $currency,
            metadata: $metadata,
            message: $message,
            providerCode: $providerCode,
        );
    }

    /**
     * Create a failed verification result.
     *
     * This means the provider could not confirm the payment.
     */
    public static function failed(
        PaymentStatus $status = PaymentStatus::FAILED,
        ?string $message = null,
        ?string $providerCode = null,
        array $metadata = [],
    ): self {
        return new self(
            verified: false,
            status: $status,
            transactionId: null,
            providerReference: null,
            amount: null,
            currency: null,
            metadata: $metadata,
            message: $message,
            providerCode: $providerCode,
        );
    }

    /**
     * Determine whether the payment is definitively successful.
     */
    public function isSuccessful(): bool
    {
        return $this->verified
            && $this->status === PaymentStatus::PAID;
    }

    /**
     * Determine whether the payment is still pending.
     */
    public function isPending(): bool
    {
        return $this->status === PaymentStatus::PENDING;
    }

    /**
     * Determine whether the payment has failed.
     */
    public function isFailed(): bool
    {
        return in_array(
            $this->status,
            [
                PaymentStatus::FAILED,
                PaymentStatus::CANCELLED,
            ],
            true
        );
    }

    /**
     * Convert the DTO to an array.
     *
     * Useful for logging, auditing, and API responses.
     */
    public function toArray(): array
    {
        return [
            'verified' => $this->verified,
            'status' => $this->status->value,
            'transaction_id' => $this->transactionId,
            'provider_reference' => $this->providerReference,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'metadata' => $this->metadata,
            'message' => $this->message,
            'provider_code' => $this->providerCode,
        ];
    }
}