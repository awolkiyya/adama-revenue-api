<?php

declare(strict_types=1);

namespace App\Modules\Payment\Providers\Telebirr\DTOs;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class TelebirrToken
{
    public function __construct(
        public string $accessToken,
        public ?DateTimeImmutable $expiresAt = null,
    ) {
        if (trim($this->accessToken) === '') {
            throw new InvalidArgumentException(
                'Telebirr access token cannot be empty.'
            );
        }
    }

    public function isExpired(): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        return $this->expiresAt <= new DateTimeImmutable();
    }
}
