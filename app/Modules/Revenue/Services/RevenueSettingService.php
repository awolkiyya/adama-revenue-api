<?php

namespace App\Modules\Revenue\Services;

use App\Models\RevenueSetting;
use App\Models\User;
use App\Services\SystemLogService;
use Illuminate\Support\Facades\DB;

class RevenueSettingService
{
    /**
     * Revenue Management audit-log module name.
     */
    private const MODULE = 'revenue_settings';

    /**
     * @param SystemLogService $systemLogService
     */
    public function __construct(
        private readonly SystemLogService $systemLogService
    ) {
    }


    /*
    |--------------------------------------------------------------------------
    | Get Active
    |--------------------------------------------------------------------------
    */

    /**
     * Get the active global revenue configuration.
     */
    public function getActive(): ?RevenueSetting
    {
        return RevenueSetting::query()
            ->active()
            ->first();
    }


    /*
    |--------------------------------------------------------------------------
    | Get Active Or Fail
    |--------------------------------------------------------------------------
    */

    /**
     * Get the active configuration or fail.
     */
    public function getActiveOrFail(): RevenueSetting
    {
        return RevenueSetting::activeOrFail();
    }


    /*
    |--------------------------------------------------------------------------
    | Save
    |--------------------------------------------------------------------------
    */

    /**
     * Create the initial configuration or update the existing configuration.
     *
     * Revenue settings are a singleton-style global configuration.
     *
     * The API therefore exposes one save operation instead of a normal
     * create/update lifecycle.
     *
     * Every successful create/update operation is recorded in the
     * system audit log.
     */
    public function save(
        array $data,
        ?User $user = null
    ): RevenueSetting {
        return DB::transaction(function () use ($data, $user) {

            /*
            |--------------------------------------------------------------------------
            | Find Existing Active Configuration
            |--------------------------------------------------------------------------
            */

            $revenueSetting = RevenueSetting::query()
                ->active()
                ->lockForUpdate()
                ->first();

            /*
            |--------------------------------------------------------------------------
            | Determine Operation
            |--------------------------------------------------------------------------
            */

            $isCreating = $revenueSetting === null;

            /*
            |--------------------------------------------------------------------------
            | Create Initial Configuration
            |--------------------------------------------------------------------------
            */

            if ($isCreating) {
                $revenueSetting = new RevenueSetting();

                $this->applyDefaults($revenueSetting);

                if ($user) {
                    $revenueSetting->created_by = $user->id;
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Capture Original Values
            |--------------------------------------------------------------------------
            |
            | Only capture old values for updates.
            |
            | For a newly created configuration there are no previous
            | persisted values.
            |
            */

            $oldValues = $isCreating
                ? null
                : $this->getAuditValues($revenueSetting);

            /*
            |--------------------------------------------------------------------------
            | Normalize Input
            |--------------------------------------------------------------------------
            */

            $data = $this->normalize($data);

            /*
            |--------------------------------------------------------------------------
            | Audit User
            |--------------------------------------------------------------------------
            */

            if ($user) {
                $data['updated_by'] = $user->id;
            }

            /*
            |--------------------------------------------------------------------------
            | Global Configuration Must Remain Active
            |--------------------------------------------------------------------------
            */

            $data['is_active'] = true;

            /*
            |--------------------------------------------------------------------------
            | Apply Changes
            |--------------------------------------------------------------------------
            */

            $revenueSetting->fill($data);

            /*
            |--------------------------------------------------------------------------
            | Save
            |--------------------------------------------------------------------------
            */

            $revenueSetting->save();

            /*
            |--------------------------------------------------------------------------
            | Refresh
            |--------------------------------------------------------------------------
            */

            $revenueSetting->refresh();

            /*
            |--------------------------------------------------------------------------
            | Audit
            |--------------------------------------------------------------------------
            |
            | The audit log is intentionally created after the business
            | operation succeeds.
            |
            | SystemLogService itself is fail-safe and will not cause the
            | revenue-settings transaction to fail if logging encounters
            | an error.
            |
            */

            if ($isCreating) {
                $this->systemLogService->created(
                    resource: $revenueSetting,
                    module: self::MODULE,
                    description: 'Global revenue settings created successfully.',
                    newValues: $this->getAuditValues($revenueSetting),
                    metadata: [
                        'operation' => 'initial_configuration',
                    ],
                );
            } else {
                $newValues = $this->getAuditValues($revenueSetting);

                /*
                |--------------------------------------------------------------------------
                | Only Audit Actual Changes
                |--------------------------------------------------------------------------
                |
                | Avoid creating an UPDATE audit entry when the submitted
                | configuration contains no actual changes.
                |
                */

                $changedValues = $this->getChangedAuditValues(
                    $oldValues ?? [],
                    $newValues
                );

                if (
                    $changedValues['old'] !== []
                    || $changedValues['new'] !== []
                ) {
                    $this->systemLogService->updated(
                        resource: $revenueSetting,
                        module: self::MODULE,
                        oldValues: $changedValues['old'],
                        newValues: $changedValues['new'],
                        description: 'Global revenue settings updated successfully.',
                        metadata: [
                            'operation' => 'configuration_update',
                        ],
                    );
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Return Fresh Configuration
            |--------------------------------------------------------------------------
            */

            return $revenueSetting;
        });
    }


    /*
    |--------------------------------------------------------------------------
    | Update Existing Configuration
    |--------------------------------------------------------------------------
    */

    /**
     * Update an existing global revenue configuration.
     *
     * This method can be used internally when the configuration is already
     * guaranteed to exist.
     *
     * Successful changes are recorded in the audit log.
     */
    public function update(
        RevenueSetting $revenueSetting,
        array $data,
        ?User $user = null
    ): RevenueSetting {
        return DB::transaction(function () use (
            $revenueSetting,
            $data,
            $user
        ) {

            /*
            |--------------------------------------------------------------------------
            | Lock Existing Configuration
            |--------------------------------------------------------------------------
            */

            $revenueSetting = RevenueSetting::query()
                ->whereKey($revenueSetting->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            /*
            |--------------------------------------------------------------------------
            | Capture Old Values
            |--------------------------------------------------------------------------
            */

            $oldValues = $this->getAuditValues(
                $revenueSetting
            );

            /*
            |--------------------------------------------------------------------------
            | Normalize Input
            |--------------------------------------------------------------------------
            */

            $data = $this->normalize($data);

            /*
            |--------------------------------------------------------------------------
            | Audit User
            |--------------------------------------------------------------------------
            */

            if ($user) {
                $data['updated_by'] = $user->id;
            }

            /*
            |--------------------------------------------------------------------------
            | Keep Configuration Active
            |--------------------------------------------------------------------------
            */

            $data['is_active'] = true;

            /*
            |--------------------------------------------------------------------------
            | Apply Changes
            |--------------------------------------------------------------------------
            */

            $revenueSetting->fill($data);

            /*
            |--------------------------------------------------------------------------
            | Save
            |--------------------------------------------------------------------------
            */

            $revenueSetting->save();

            /*
            |--------------------------------------------------------------------------
            | Refresh
            |--------------------------------------------------------------------------
            */

            $revenueSetting->refresh();

            /*
            |--------------------------------------------------------------------------
            | Audit Actual Changes
            |--------------------------------------------------------------------------
            */

            $newValues = $this->getAuditValues(
                $revenueSetting
            );

            $changedValues = $this->getChangedAuditValues(
                $oldValues,
                $newValues
            );

            if (
                $changedValues['old'] !== []
                || $changedValues['new'] !== []
            ) {
                $this->systemLogService->updated(
                    resource: $revenueSetting,
                    module: self::MODULE,
                    oldValues: $changedValues['old'],
                    newValues: $changedValues['new'],
                    description: 'Global revenue settings updated successfully.',
                    metadata: [
                        'operation' => 'configuration_update',
                    ],
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Return Fresh Configuration
            |--------------------------------------------------------------------------
            */

            return $revenueSetting;
        });
    }


    /*
    |--------------------------------------------------------------------------
    | Ensure Exists
    |--------------------------------------------------------------------------
    */

    /**
     * Ensure that the global revenue configuration exists.
     *
     * Intended for:
     *
     * - deployment
     * - installation
     * - seeders
     *
     * This should not normally be exposed directly as an API operation.
     *
     * This method intentionally does NOT create an audit log because
     * installation/seeding is not an administrator configuration change.
     */
    public function ensureExists(?User $user = null): RevenueSetting
    {
        return DB::transaction(function () use ($user) {

            $existing = RevenueSetting::query()
                ->active()
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            $setting = new RevenueSetting();

            $this->applyDefaults($setting);

            if ($user) {
                $setting->created_by = $user->id;
                $setting->updated_by = $user->id;
            }

            $setting->save();

            return $setting->fresh();
        });
    }


    /*
    |--------------------------------------------------------------------------
    | Default Configuration
    |--------------------------------------------------------------------------
    */

    /**
     * Apply the application's default global revenue configuration.
     */
    private function applyDefaults(
        RevenueSetting $setting
    ): void {
        $setting->fill([
            /*
            |--------------------------------------------------------------------------
            | Payment Period
            |--------------------------------------------------------------------------
            |
            | Ethiopian Calendar:
            |
            | Month 1  = Meskerem
            | Month 13 = Pagume
            |
            */

            'payment_start_month' => 1,
            'payment_start_day' => 1,

            'payment_end_month' => 13,
            'payment_end_day' => 6,

            /*
            |--------------------------------------------------------------------------
            | Penalty / Interest
            |--------------------------------------------------------------------------
            */

            'penalty_enabled' => true,
            'interest_enabled' => true,

            /*
            |--------------------------------------------------------------------------
            | Assessment
            |--------------------------------------------------------------------------
            */

            'assessment_auto_calculation' => true,
            'assessment_allow_manual_adjustment' => false,
            'assessment_requires_approval' => false,
            'assessment_reassessment_allowed' => true,

            /*
            |--------------------------------------------------------------------------
            | Invoice
            |--------------------------------------------------------------------------
            */

            'invoice_auto_numbering' => true,
            'invoice_prefix' => 'INV',
            'invoice_allow_overpayment' => false,
            'invoice_allow_overdue_payment' => true,

            /*
            |--------------------------------------------------------------------------
            | Payment
            |--------------------------------------------------------------------------
            */

            'payment_confirmation_required' => true,
            'payment_auto_receipt' => true,

            'enabled_payment_methods' => [
                'CASH',
                'BANK',
                'MOBILE_MONEY',
            ],

            /*
            |--------------------------------------------------------------------------
            | Receipt
            |--------------------------------------------------------------------------
            */

            'receipt_auto_numbering' => true,
            'receipt_prefix' => 'REC',
            'receipt_allow_reprint' => true,

            /*
            |--------------------------------------------------------------------------
            | Status
            |--------------------------------------------------------------------------
            */

            'is_active' => true,

            /*
            |--------------------------------------------------------------------------
            | Metadata
            |--------------------------------------------------------------------------
            */

            'legal_reference' => null,
            'description' => null,
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | Normalize
    |--------------------------------------------------------------------------
    */

    /**
     * Normalize incoming settings before persistence.
     */
    private function normalize(array $data): array
    {
        /*
        |--------------------------------------------------------------------------
        | Payment Methods
        |--------------------------------------------------------------------------
        */

        if (array_key_exists('enabled_payment_methods', $data)) {
            $methods = $data['enabled_payment_methods'];

            if (is_array($methods)) {
                $data['enabled_payment_methods'] = array_values(
                    array_unique(
                        array_map(
                            static fn ($method) =>
                                strtoupper(trim((string) $method)),
                            $methods
                        )
                    )
                );
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Invoice Prefix
        |--------------------------------------------------------------------------
        */

        if (array_key_exists('invoice_prefix', $data)) {
            $data['invoice_prefix'] = trim(
                (string) $data['invoice_prefix']
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Receipt Prefix
        |--------------------------------------------------------------------------
        */

        if (array_key_exists('receipt_prefix', $data)) {
            $data['receipt_prefix'] = trim(
                (string) $data['receipt_prefix']
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Legal Reference
        |--------------------------------------------------------------------------
        */

        if (array_key_exists('legal_reference', $data)) {
            $data['legal_reference'] =
                $data['legal_reference'] !== null
                    ? trim((string) $data['legal_reference'])
                    : null;
        }

        /*
        |--------------------------------------------------------------------------
        | Description
        |--------------------------------------------------------------------------
        */

        if (array_key_exists('description', $data)) {
            $data['description'] =
                $data['description'] !== null
                    ? trim((string) $data['description'])
                    : null;
        }

        return $data;
    }


    /*
    |--------------------------------------------------------------------------
    | Audit Values
    |--------------------------------------------------------------------------
    */

    /**
     * Return the values that are relevant to Revenue Settings auditing.
     *
     * We deliberately avoid logging timestamps because they do not represent
     * configuration changes.
     */
    private function getAuditValues(
        RevenueSetting $setting
    ): array {
        return [
            /*
            |--------------------------------------------------------------------------
            | Payment Period
            |--------------------------------------------------------------------------
            */

            'payment_start_month' =>
                $setting->payment_start_month,

            'payment_start_day' =>
                $setting->payment_start_day,

            'payment_end_month' =>
                $setting->payment_end_month,

            'payment_end_day' =>
                $setting->payment_end_day,

            /*
            |--------------------------------------------------------------------------
            | Penalty / Interest
            |--------------------------------------------------------------------------
            */

            'penalty_enabled' =>
                $setting->penalty_enabled,

            'interest_enabled' =>
                $setting->interest_enabled,

            /*
            |--------------------------------------------------------------------------
            | Assessment
            |--------------------------------------------------------------------------
            */

            'assessment_auto_calculation' =>
                $setting->assessment_auto_calculation,

            'assessment_allow_manual_adjustment' =>
                $setting->assessment_allow_manual_adjustment,

            'assessment_requires_approval' =>
                $setting->assessment_requires_approval,

            'assessment_reassessment_allowed' =>
                $setting->assessment_reassessment_allowed,

            /*
            |--------------------------------------------------------------------------
            | Invoice
            |--------------------------------------------------------------------------
            */

            'invoice_auto_numbering' =>
                $setting->invoice_auto_numbering,

            'invoice_prefix' =>
                $setting->invoice_prefix,

            'invoice_allow_overpayment' =>
                $setting->invoice_allow_overpayment,

            'invoice_allow_overdue_payment' =>
                $setting->invoice_allow_overdue_payment,

            /*
            |--------------------------------------------------------------------------
            | Payment
            |--------------------------------------------------------------------------
            */

            'payment_confirmation_required' =>
                $setting->payment_confirmation_required,

            'payment_auto_receipt' =>
                $setting->payment_auto_receipt,

            'enabled_payment_methods' =>
                $setting->enabled_payment_methods,

            /*
            |--------------------------------------------------------------------------
            | Receipt
            |--------------------------------------------------------------------------
            */

            'receipt_auto_numbering' =>
                $setting->receipt_auto_numbering,

            'receipt_prefix' =>
                $setting->receipt_prefix,

            'receipt_allow_reprint' =>
                $setting->receipt_allow_reprint,

            /*
            |--------------------------------------------------------------------------
            | Status
            |--------------------------------------------------------------------------
            */

            'is_active' =>
                $setting->is_active,

            /*
            |--------------------------------------------------------------------------
            | Legal / Description
            |--------------------------------------------------------------------------
            */

            'legal_reference' =>
                $setting->legal_reference,

            'description' =>
                $setting->description,
        ];
    }


    /*
    |--------------------------------------------------------------------------
    | Changed Audit Values
    |--------------------------------------------------------------------------
    */

    /**
     * Extract only values that actually changed.
     *
     * This makes audit logs much easier to read.
     *
     * Example:
     *
     * old:
     *     penalty_enabled = true
     *
     * new:
     *     penalty_enabled = false
     *
     * The audit record contains only that change.
     */
    private function getChangedAuditValues(
        array $oldValues,
        array $newValues
    ): array {
        $oldChanged = [];
        $newChanged = [];

        $keys = array_unique(
            array_merge(
                array_keys($oldValues),
                array_keys($newValues)
            )
        );

        foreach ($keys as $key) {
            $oldValue = $oldValues[$key] ?? null;
            $newValue = $newValues[$key] ?? null;

            if (!$this->auditValuesEqual($oldValue, $newValue)) {
                $oldChanged[$key] = $oldValue;
                $newChanged[$key] = $newValue;
            }
        }

        return [
            'old' => $oldChanged,
            'new' => $newChanged,
        ];
    }


    /*
    |--------------------------------------------------------------------------
    | Audit Value Comparison
    |--------------------------------------------------------------------------
    */

    /**
     * Compare audit values safely.
     */
    private function auditValuesEqual(
        mixed $oldValue,
        mixed $newValue
    ): bool {
        if (
            is_array($oldValue)
            && is_array($newValue)
        ) {
            return $oldValue === $newValue;
        }

        return $oldValue === $newValue;
    }
}