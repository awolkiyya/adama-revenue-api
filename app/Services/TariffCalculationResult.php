<?php

namespace App\Services;

final readonly class TariffCalculationResult
{
    public function __construct(
        public string $status,
        public float $amount,
        public ?string $currencyCode = null,
        public ?string $error = null,
        public array $metadata = [],
    ) {
    }

    public static function success(
        float $amount,
        ?string $currencyCode = null,
        array $metadata = [],
    ): self {
        return new self(
            status: 'SUCCESS',
            amount: $amount,
            currencyCode: $currencyCode,
            metadata: $metadata,
        );
    }

    public static function failed(
        string $error,
        array $metadata = [],
    ): self {
        return new self(
            status: 'FAILED',
            amount: 0,
            error: $error,
            metadata: $metadata,
        );
    }

    public function isSuccessful(): bool
    {
        return $this->status === 'SUCCESS';
    }
}