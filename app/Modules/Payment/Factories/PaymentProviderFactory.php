<?php

namespace App\Modules\Payment\Factories;

use App\Modules\Payment\Contracts\PaymentProviderInterface;
use App\Enums\PaymentProvider;
use App\Modules\Payment\Providers\Bank\BankPaymentProvider;
use App\Modules\Payment\Providers\Cash\CashPaymentProvider;
use App\Modules\Payment\Providers\Chapa\ChapaPaymentProvider;
use App\Modules\Payment\Providers\Telebirr\TelebirrPaymentProvider;
use InvalidArgumentException;

class PaymentProviderFactory
{
    /**
     * Resolve a payment provider implementation.
     *
     * The factory is responsible only for provider resolution.
     * Business logic belongs in PaymentService.
     */
    public function make(
        PaymentProvider|string $provider
    ): PaymentProviderInterface {
        $provider = $this->normalizeProvider($provider);

        return match ($provider) {
            PaymentProvider::CHAPA =>
                app(ChapaPaymentProvider::class),

            PaymentProvider::TELEBIRR =>
                app(TelebirrPaymentProvider::class),

            PaymentProvider::BANK =>
                app(BankPaymentProvider::class),

            PaymentProvider::CASH =>
                app(CashPaymentProvider::class),

            default => throw new InvalidArgumentException(
                "Unsupported payment provider: {$provider->value}"
            ),
        };
    }

    /**
     * Convert string input into the strongly typed enum.
     */
    private function normalizeProvider(
        PaymentProvider|string $provider
    ): PaymentProvider {
        if ($provider instanceof PaymentProvider) {
            return $provider;
        }

        $normalized = strtoupper(trim($provider));

        return PaymentProvider::tryFrom($normalized)
            ?? throw new InvalidArgumentException(
                "Invalid payment provider: {$provider}"
            );
    }
}