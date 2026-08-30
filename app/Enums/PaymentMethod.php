<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case CHAPA = 'CHAPA';
    case TELEBIRR = 'TELEBIRR';
    case BANK_TRANSFER = 'BANK_TRANSFER';
    case CASH = 'CASH';

    /**
     * Human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::CHAPA => 'Chapa',
            self::TELEBIRR => 'Telebirr',
            self::BANK_TRANSFER => 'Bank Transfer',
            self::CASH => 'Cash',
        };
    }

    /**
     * Determine whether the payment method
     * requires an external payment provider.
     */
    public function isOnline(): bool
    {
        return match ($this) {
            self::CHAPA,
            self::TELEBIRR => true,

            self::BANK_TRANSFER,
            self::CASH => false,
        };
    }

    /**
     * Determine whether the payment method
     * requires webhook/callback processing.
     */
    public function requiresWebhook(): bool
    {
        return match ($this) {
            self::CHAPA,
            self::TELEBIRR => true,

            self::BANK_TRANSFER,
            self::CASH => false,
        };
    }

    /**
     * Determine whether the payment requires
     * manual verification by an authorized officer.
     */
    public function requiresManualVerification(): bool
    {
        return match ($this) {
            self::BANK_TRANSFER,
            self::CASH => true,

            self::CHAPA,
            self::TELEBIRR => false,
        };
    }

    /**
     * Return all supported payment methods.
     *
     * Useful for validation, dropdowns and API documentation.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $method): string => $method->value,
            self::cases()
        );
    }

    /**
     * Return all methods with labels.
     *
     * @return array<int, array{
     *     value: string,
     *     label: string,
     *     online: bool,
     *     requires_webhook: bool,
     *     requires_manual_verification: bool
     * }>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $method): array => [
                'value' => $method->value,
                'label' => $method->label(),
                'online' => $method->isOnline(),
                'requires_webhook' => $method->requiresWebhook(),
                'requires_manual_verification' => $method->requiresManualVerification(),
            ],
            self::cases()
        );
    }
}