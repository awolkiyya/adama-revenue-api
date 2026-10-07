<?php

declare(strict_types=1);

namespace App\Modules\Payment\DTOs;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use Carbon\Carbon;

/**
 * Represents the normalized result of a payment verification
 * performed by any payment provider.
 *
 * Provider-specific responses must be converted into this DTO
 * before reaching the core payment domain.
 *
 * Important:
 *
 * - payment = the local municipal payment record, when available.
 *
 * - transactionReference = municipality's canonical transaction
 *   reference, for example the Chapa tx_ref.
 *
 * - transactionId = provider-side transaction/transaction ID.
 *
 * - providerReference = provider-specific reference ID,
 *   for example Chapa ref_id.
 *
 * - verified = the provider verification request successfully
 *   produced a normalized result. It does NOT by itself mean
 *   that the payment is financially completed.
 */
final readonly class PaymentVerificationResult
{
    public function __construct(
        /**
         * Local municipal payment record.
         *
         * This is populated when the verification result is created
         * by the core PaymentVerificationService.
         */
        public ?Payment $payment = null,

        /**
         * Whether the provider verification request successfully
         * produced a valid verification result.
         *
         * IMPORTANT:
         *
         * true does not necessarily mean the payment is COMPLETED.
         * Always use status/isSuccessful() to determine financial
         * completion.
         */
        public bool $verified = false,

        /**
         * Normalized municipal payment status.
         */
        public PaymentStatus $status = PaymentStatus::PENDING,

        /**
         * Municipality's canonical transaction reference.
         *
         * Example:
         * PAY-01M4BCY27YB8G60YSYWD94R558
         */
        public ?string $transactionReference = null,

        /**
         * Provider-side transaction ID.
         */
        public ?string $transactionId = null,

        /**
         * Provider-specific reference ID.
         *
         * Example for Chapa:
         * APQdlYrrB17dq
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
         * Provider-confirmed payment completion time.
         */
        public ?Carbon $paidAt = null,

        /**
         * Optional payment metadata returned by the provider.
         *
         * Core payment logic must not depend on provider-specific
         * metadata.
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
     *
     * A successful payment in the municipal system is represented
     * by PaymentStatus::COMPLETED.
     */
    public static function success(
        ?Payment $payment = null,
        PaymentStatus $status = PaymentStatus::COMPLETED,
        ?string $transactionReference = null,
        ?string $transactionId = null,
        ?string $providerReference = null,
        ?float $amount = null,
        ?string $currency = null,
        ?Carbon $paidAt = null,
        array $metadata = [],
        ?string $message = null,
        ?string $providerCode = null,
    ): self {
        return new self(
            payment: $payment,
            verified: true,
            status: $status,
            transactionReference: $transactionReference,
            transactionId: $transactionId,
            providerReference: $providerReference,
            amount: $amount,
            currency: $currency,
            paidAt: $paidAt,
            metadata: $metadata,
            message: $message,
            providerCode: $providerCode,
        );
    }

    /**
     * Create a failed verification result.
     *
     * The provider verification completed, but the payment
     * could not be confirmed as successful.
     */
    public static function failed(
        ?Payment $payment = null,
        PaymentStatus $status = PaymentStatus::FAILED,
        ?string $message = null,
        ?string $transactionReference = null,
        ?string $transactionId = null,
        ?string $providerReference = null,
        ?float $amount = null,
        ?string $currency = null,
        array $metadata = [],
        ?string $providerCode = null,
    ): self {
        return new self(
            payment: $payment,
            verified: false,
            status: $status,
            transactionReference: $transactionReference,
            transactionId: $transactionId,
            providerReference: $providerReference,
            amount: $amount,
            currency: $currency,
            paidAt: null,
            metadata: $metadata,
            message: $message,
            providerCode: $providerCode,
        );
    }

    /**
     * Create a pending verification result.
     *
     * The provider verification request was successful, but the
     * provider has not yet confirmed financial completion.
     */
    public static function pending(
        ?Payment $payment = null,
        ?string $message = null,
        ?string $transactionReference = null,
        ?string $transactionId = null,
        ?string $providerReference = null,
        ?float $amount = null,
        ?string $currency = null,
        array $metadata = [],
        ?string $providerCode = null,
    ): self {
        return new self(
            payment: $payment,
            verified: true,
            status: PaymentStatus::PENDING,
            transactionReference: $transactionReference,
            transactionId: $transactionId,
            providerReference: $providerReference,
            amount: $amount,
            currency: $currency,
            paidAt: null,
            metadata: $metadata,
            message: $message,
            providerCode: $providerCode,
        );
    }

    /**
     * Determine whether the payment is definitively successful.
     *
     * In this municipal revenue system, only COMPLETED payments
     * are financially successful.
     */
    public function isSuccessful(): bool
    {
        return $this->verified
            && $this->status === PaymentStatus::COMPLETED;
    }

    /**
     * Determine whether the payment is still pending.
     */
    public function isPending(): bool
    {
        return $this->status === PaymentStatus::PENDING
            || $this->status === PaymentStatus::PROCESSING;
    }

    /**
     * Determine whether the payment has failed or can no longer
     * be completed normally.
     */
    public function isFailed(): bool
    {
        return in_array(
            $this->status,
            [
                PaymentStatus::FAILED,
                PaymentStatus::CANCELLED,
                PaymentStatus::EXPIRED,
                PaymentStatus::REVERSED,
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
            'payment_id' =>
                $this->payment?->getKey(),

            'verified' =>
                $this->verified,

            'status' =>
                $this->status->value,

            'transaction_reference' =>
                $this->transactionReference,

            'transaction_id' =>
                $this->transactionId,

            'provider_reference' =>
                $this->providerReference,

            'amount' =>
                $this->amount,

            'currency' =>
                $this->currency,

            'paid_at' =>
                $this->paidAt?->toISOString(),

            'metadata' =>
                $this->metadata,

            'message' =>
                $this->message,

            'provider_code' =>
                $this->providerCode,
        ];
    }
}
