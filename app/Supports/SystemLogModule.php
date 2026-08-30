<?php

namespace App\Supports;

final class SystemLogModule
{
    private function __construct()
    {
    }

    public const AUTHENTICATION = 'Authentication';

    public const USERS = 'Users';

    public const ADMINISTRATIVE = 'Administrative';

    public const SECTORS = 'Sectors';

    public const CITIZENS = 'Citizens';

    public const REVENUE = 'Revenue';

    public const TARIFF = 'Tariff';

    public const INVOICE = 'Invoice';

    public const PAYMENT = 'Payment';

    public const PLANS = 'Plans';

    public const KPI = 'KPI';

    public const REPORTS = 'Reports';

    public const PERMISSIONS = 'Permissions';

    public const SYSTEM = 'System';
}