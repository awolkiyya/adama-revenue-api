<?php

namespace App\Modules\Payment\Contracts;

use App\Enums\PaymentProvider;
use App\Models\Payment;
use App\Modules\Payment\DTOs\InitializePaymentData;
use App\Modules\Payment\DTOs\PaymentResult;
use App\Modules\Payment\DTOs\PaymentVerificationResult;

interface PaymentProviderInterface
{
    /**
     * Return provider identifier.
     */
    public function provider(): PaymentProvider;

    /**
     * Initialize payment with provider.
     */
    public function initialize(
        InitializePaymentData $data
    ): PaymentResult;

    /**
     * Verify payment with provider.
     */
    public function verify(
        Payment $payment
    ): PaymentVerificationResult;

    /**
     * Process provider webhook.
     */
    public function handleWebhook(
        array $payload,
        ?string $signature = null
    ): PaymentVerificationResult;

    /**
     * Whether provider supports webhooks.
     */
    public function supportsWebhook(): bool;

    /**
     * Whether provider supports automated refunds.
     */
    public function supportsRefund(): bool;

    /**
     * Refund payment.
     *
     * @param float|null $amount
     */
    public function refund(
        Payment $payment,
        ?float $amount = null
    ): PaymentResult;

    /**
     * Human-readable provider name.
     */
    public function displayName(): string;
}