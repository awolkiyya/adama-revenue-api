<?php

declare(strict_types=1);

namespace App\Modules\Payment\Providers\Telebirr\DTOs;

final readonly class TelebirrQueryResult
{
    public function __construct(
        public string $tradeStatus,
        public ?string $transactionId = null,
        public ?string $prepayId = null,
    ) {
    }

    /**
     * Determine whether Telebirr confirms successful payment.
     */
    public function isSuccessful(): bool
    {
        return in_array(
            strtoupper(trim($this->tradeStatus)),
            [
                'SUCCESS',
                'COMPLETED',
            ],
            true,
        );
    }

    /**
     * Determine whether Telebirr reports a failed payment.
     */
    public function isFailed(): bool
    {
        return in_array(
            strtoupper(trim($this->tradeStatus)),
            [
                'FAILED',
            ],
            true,
        );
    }

    /**
     * Determine whether payment is still pending or unresolved.
     */
    public function isPending(): bool
    {
        return ! $this->isSuccessful()
            && ! $this->isFailed();
    }
}
