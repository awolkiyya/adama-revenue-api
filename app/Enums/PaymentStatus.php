<?php

namespace App\Enums;

enum PaymentStatus: string
{
    /**
     * Payment record has been created but
     * payment processing has not started.
     */
    case PENDING = 'PENDING';

    /**
     * Payment has been submitted to an external
     * provider and is currently being processed.
     */
    case PROCESSING = 'PROCESSING';

    /**
     * Payment has been successfully completed.
     */
    case PAID = 'PAID';

    /**
     * Payment attempt failed.
     */
    case FAILED = 'FAILED';

    /**
     * Payment was cancelled before completion.
     */
    case CANCELLED = 'CANCELLED';

    /**
     * Payment expired before completion.
     */
    case EXPIRED = 'EXPIRED';

    /**
     * A previously successful payment was refunded
     * completely.
     */
    case REFUNDED = 'REFUNDED';

    /**
     * A payment was partially refunded.
     */
    case PARTIALLY_REFUNDED = 'PARTIALLY_REFUNDED';

    /**
     * Payment is waiting for manual verification.
     *
     * Typical examples:
     * - Bank transfer
     * - Cash payment
     */
    case AWAITING_VERIFICATION = 'AWAITING_VERIFICATION';

    /**
     * Payment was rejected during manual verification.
     */
    case REJECTED = 'REJECTED';

    /**
     * Human-readable payment status.
     */
    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::PROCESSING => 'Processing',
            self::PAID => 'Paid',
            self::FAILED => 'Failed',
            self::CANCELLED => 'Cancelled',
            self::EXPIRED => 'Expired',
            self::REFUNDED => 'Refunded',
            self::PARTIALLY_REFUNDED => 'Partially Refunded',
            self::AWAITING_VERIFICATION => 'Awaiting Verification',
            self::REJECTED => 'Rejected',
        };
    }

    /**
     * Determine whether the payment is considered
     * financially successful.
     */
    public function isSuccessful(): bool
    {
        return $this === self::PAID;
    }

    /**
     * Determine whether the payment can still
     * transition into another state.
     */
    public function isFinal(): bool
    {
        return match ($this) {
            self::PAID,
            self::FAILED,
            self::CANCELLED,
            self::EXPIRED,
            self::REFUNDED,
            self::PARTIALLY_REFUNDED,
            self::REJECTED => true,

            self::PENDING,
            self::PROCESSING,
            self::AWAITING_VERIFICATION => false,
        };
    }

    /**
     * Determine whether payment processing
     * is currently in progress.
     */
    public function isProcessing(): bool
    {
        return match ($this) {
            self::PENDING,
            self::PROCESSING,
            self::AWAITING_VERIFICATION => true,

            default => false,
        };
    }

    /**
     * Determine whether the payment failed
     * or was rejected.
     */
    public function isFailed(): bool
    {
        return match ($this) {
            self::FAILED,
            self::CANCELLED,
            self::EXPIRED,
            self::REJECTED => true,

            default => false,
        };
    }

    /**
     * Determine whether the payment can be refunded.
     */
    public function isRefundable(): bool
    {
        return match ($this) {
            self::PAID,
            self::PARTIALLY_REFUNDED => true,

            default => false,
        };
    }

    /**
     * Determine whether the payment is waiting
     * for an officer/manual verification.
     */
    public function requiresVerification(): bool
    {
        return $this === self::AWAITING_VERIFICATION;
    }

    /**
     * Return all enum values.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $status): string => $status->value,
            self::cases()
        );
    }

    /**
     * Return API/UI-friendly status options.
     *
     * @return array<int, array{
     *     value: string,
     *     label: string
     * }>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $status): array => [
                'value' => $status->value,
                'label' => $status->label(),
            ],
            self::cases()
        );
    }
}