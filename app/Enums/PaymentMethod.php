<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case ONLINE = 'ONLINE';

    case BANK_TRANSFER = 'BANK_TRANSFER';

    case CASH = 'CASH';

    /**
     * Human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::ONLINE => 'Online Payment',
            self::BANK_TRANSFER => 'Bank Transfer',
            self::CASH => 'Cash',
        };
    }

    /**
     * Determine whether the payment method
     * uses an external online payment provider.
     *
     * Examples:
     *
     * ONLINE
     *     → Telebirr
     *     → Chapa
     *     → CBE Birr
     *
     * BANK_TRANSFER
     *     → Bank account transfer
     *
     * CASH
     *     → Cashier / field collection
     */
    public function isOnline(): bool
    {
        return match ($this) {
            self::ONLINE => true,

            self::BANK_TRANSFER,
            self::CASH => false,
        };
    }

    /**
     * Determine whether the payment method
     * requires webhook/callback processing.
     *
     * ONLINE payments are confirmed asynchronously
     * by the external provider.
     */
    public function requiresWebhook(): bool
    {
        return match ($this) {
            self::ONLINE => true,

            self::BANK_TRANSFER,
            self::CASH => false,
        };
    }

    /**
     * Determine whether the payment requires
     * manual verification by an authorized officer.
     *
     * CASH:
     *     Verified/recorded through municipal cash
     *     collection processes.
     *
     * BANK_TRANSFER:
     *     Requires verification against bank evidence.
     *
     * ONLINE:
     *     Provider confirmation/webhook is used.
     */
    public function requiresManualVerification(): bool
    {
        return match ($this) {
            self::BANK_TRANSFER,
            self::CASH => true,

            self::ONLINE => false,
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
     * Return all methods with labels and behavior.
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
                'value' =>
                    $method->value,

                'label' =>
                    $method->label(),

                'online' =>
                    $method->isOnline(),

                'requires_webhook' =>
                    $method->requiresWebhook(),

                'requires_manual_verification' =>
                    $method->requiresManualVerification(),
            ],
            self::cases()
        );
    }
}