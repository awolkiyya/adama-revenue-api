<?php

namespace App\Enums;

enum PaymentStatus: string
{
    /**
     * Payment record has been created but has not
     * been financially completed yet.
     *
     * Cash:
     * - Cash payment recorded
     * - Waiting for completion/verification
     *
     * Bank:
     * - Transfer recorded
     * - Waiting for verification
     */
    case PENDING = 'PENDING';

    /**
     * Payment is currently being processed.
     *
     * Mainly used for online payments while the
     * payment provider is processing the transaction.
     */
    case PROCESSING = 'PROCESSING';

    /**
     * Payment has been successfully completed
     * and is financially counted against the invoice.
     */
    case COMPLETED = 'COMPLETED';

    /**
     * Payment processing failed.
     */
    case FAILED = 'FAILED';

    /**
     * Payment was cancelled before completion.
     */
    case CANCELLED = 'CANCELLED';

    /**
     * Payment attempt expired before completion.
     *
     * Mainly applicable to online payments.
     */
    case EXPIRED = 'EXPIRED';

    /**
     * A previously completed payment was reversed.
     *
     * This is preferable to REFUNDED for the current
     * municipal revenue payment model because a reversal
     * represents cancellation of a previously recognized
     * payment transaction.
     */
    case REVERSED = 'REVERSED';

    /**
     * Human-readable payment status.
     */
    public function label(): string
    {
        return match ($this) {
            self::PENDING =>
                'Pending',

            self::PROCESSING =>
                'Processing',

            self::COMPLETED =>
                'Completed',

            self::FAILED =>
                'Failed',

            self::CANCELLED =>
                'Cancelled',

            self::EXPIRED =>
                'Expired',

            self::REVERSED =>
                'Reversed',
        };
    }

    /**
     * Determine whether the payment is financially
     * successful.
     *
     * Only COMPLETED payments are counted when
     * calculating the invoice paid amount.
     */
    public function isSuccessful(): bool
    {
        return $this === self::COMPLETED;
    }

    /**
     * Determine whether this status is final.
     *
     * Final payments cannot continue through the
     * normal payment-processing lifecycle.
     */
    public function isFinal(): bool
    {
        return match ($this) {
            self::COMPLETED,
            self::FAILED,
            self::CANCELLED,
            self::EXPIRED,
            self::REVERSED => true,

            self::PENDING,
            self::PROCESSING => false,
        };
    }

    /**
     * Determine whether payment is currently active
     * in the processing lifecycle.
     */
    public function isProcessing(): bool
    {
        return match ($this) {
            self::PENDING,
            self::PROCESSING => true,

            self::COMPLETED,
            self::FAILED,
            self::CANCELLED,
            self::EXPIRED,
            self::REVERSED => false,
        };
    }

    /**
     * Determine whether the payment failed or can no
     * longer be completed successfully.
     */
    public function isFailed(): bool
    {
        return match ($this) {
            self::FAILED,
            self::CANCELLED,
            self::EXPIRED,
            self::REVERSED => true,

            self::PENDING,
            self::PROCESSING,
            self::COMPLETED => false,
        };
    }

    /**
     * Determine whether the payment can be reversed.
     *
     * Only a completed payment can be reversed.
     */
    public function isReversible(): bool
    {
        return $this === self::COMPLETED;
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