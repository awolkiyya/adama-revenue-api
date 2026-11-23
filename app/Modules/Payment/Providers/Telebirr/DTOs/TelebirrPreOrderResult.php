<?php

declare(strict_types=1);

namespace App\Modules\Payment\Providers\Telebirr\DTOs;

use InvalidArgumentException;

final readonly class TelebirrPreOrderResult
{
    public function __construct(
        public string $prepayId,
        public ?string $rawTradeStatus = null,
    ) {
        if (trim($this->prepayId) === '') {
            throw new InvalidArgumentException(
                'Telebirr prepay ID cannot be empty.'
            );
        }
    }
}
