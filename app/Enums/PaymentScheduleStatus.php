<?php

namespace App\Enums;

enum PaymentScheduleStatus: string
{
    case PENDING = 'PENDING';

    case PARTIALLY_PAID = 'PARTIALLY_PAID';

    case PAID = 'PAID';

    case OVERDUE = 'OVERDUE';

    case CANCELLED = 'CANCELLED';
}