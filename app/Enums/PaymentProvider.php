<?php

namespace App\Enums;

enum PaymentProvider: string
{
    /*
    |--------------------------------------------------------------------------
    | Online Payment Providers
    |--------------------------------------------------------------------------
    */

    case CHAPA = 'CHAPA';

    case TELEBIRR = 'TELEBIRR';


    /*
    |--------------------------------------------------------------------------
    | Offline Payment Providers
    |--------------------------------------------------------------------------
    */

    case BANK = 'BANK';

    case CASH = 'CASH';


    /*
    |--------------------------------------------------------------------------
    | Human-readable label
    |--------------------------------------------------------------------------
    */

    public function label(): string
    {
        return match ($this) {
            self::CHAPA => 'Chapa',

            self::TELEBIRR => 'Telebirr',

            self::BANK => 'Bank Transfer',

            self::CASH => 'Cash',
        };
    }


    /*
    |--------------------------------------------------------------------------
    | Online provider
    |--------------------------------------------------------------------------
    */

    public function isOnline(): bool
    {
        return match ($this) {
            self::CHAPA,
            self::TELEBIRR => true,

            self::BANK,
            self::CASH => false,
        };
    }


    /*
    |--------------------------------------------------------------------------
    | Webhook support
    |--------------------------------------------------------------------------
    */

    public function supportsWebhook(): bool
    {
        return match ($this) {
            self::CHAPA,
            self::TELEBIRR => true,

            self::BANK,
            self::CASH => false,
        };
    }


    /*
    |--------------------------------------------------------------------------
    | Manual verification
    |--------------------------------------------------------------------------
    */

    public function requiresManualVerification(): bool
    {
        return match ($this) {
            self::BANK,
            self::CASH => true,

            self::CHAPA,
            self::TELEBIRR => false,
        };
    }


    /*
    |--------------------------------------------------------------------------
    | Refund support
    |--------------------------------------------------------------------------
    |
    | Do not assume every provider supports automated refunds.
    | This will also be enforced by the provider implementation.
    |
    */

    public function supportsRefund(): bool
    {
        return match ($this) {
            self::CHAPA => true,

            self::TELEBIRR => false,

            self::BANK,
            self::CASH => false,
        };
    }


    /*
    |--------------------------------------------------------------------------
    | Values
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $provider): string => $provider->value,
            self::cases()
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Options
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<int, array{
     *     value: string,
     *     label: string,
     *     online: bool,
     *     supports_webhook: bool,
     *     requires_manual_verification: bool,
     *     supports_refund: bool
     * }>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $provider): array => [
                'value' => $provider->value,
                'label' => $provider->label(),
                'online' => $provider->isOnline(),
                'supports_webhook' => $provider->supportsWebhook(),
                'requires_manual_verification' => $provider->requiresManualVerification(),
                'supports_refund' => $provider->supportsRefund(),
            ],
            self::cases()
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Resolve provider from payment method
    |--------------------------------------------------------------------------
    */

    public static function fromPaymentMethod(
        PaymentMethod $method
    ): self {
        return match ($method) {
            PaymentMethod::CHAPA => self::CHAPA,

            PaymentMethod::TELEBIRR => self::TELEBIRR,

            PaymentMethod::BANK_TRANSFER => self::BANK,

            PaymentMethod::CASH => self::CASH,
        };
    }
}