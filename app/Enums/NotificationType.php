<?php

declare(strict_types=1);

namespace App\Enums;

enum NotificationType: string
{
    case PAYMENT_RECEIVED = 'payment.received';

    case INVOICE_GENERATED = 'invoice.generated';

    case ASSESSMENT_APPROVED = 'assessment.approved';

    case LEASE_EXPIRING = 'lease.expiring';

    case CASH_RECONCILIATION_REMINDER = 'cash_reconciliation.reminder';
}
