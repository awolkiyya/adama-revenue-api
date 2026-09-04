<?php

namespace App\Services;

use App\Models\RevenueSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RevenueSettingService
{
    /**
     * Get the active global revenue configuration.
     */
    public function getActive(): ?RevenueSetting
    {
        return RevenueSetting::query()
            ->active()
            ->first();
    }


    /**
     * Get the active configuration or fail.
     */
    public function getActiveOrFail(): RevenueSetting
    {
        return RevenueSetting::activeOrFail();
    }


    /**
     * Update the global revenue configuration.
     *
     * This table is treated as a singleton configuration.
     *
     * There is no normal create/update/delete lifecycle exposed
     * to the controller.
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
            | Normalize Payment Methods
            |--------------------------------------------------------------------------
            */

            if (array_key_exists('enabled_payment_methods', $data)) {
                $data['enabled_payment_methods'] = array_values(
                    array_unique(
                        array_map(
                            static fn ($method) =>
                                strtoupper(trim((string) $method)),
                            $data['enabled_payment_methods']
                        )
                    )
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Normalize Prefixes
            |--------------------------------------------------------------------------
            */

            if (array_key_exists('invoice_prefix', $data)) {
                $data['invoice_prefix'] = trim(
                    (string) $data['invoice_prefix']
                );
            }

            if (array_key_exists('receipt_prefix', $data)) {
                $data['receipt_prefix'] = trim(
                    (string) $data['receipt_prefix']
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Normalize Legal Reference
            |--------------------------------------------------------------------------
            */

            if (array_key_exists('legal_reference', $data)) {
                $data['legal_reference'] = $data['legal_reference'] !== null
                    ? trim((string) $data['legal_reference'])
                    : null;
            }


            /*
            |--------------------------------------------------------------------------
            | Audit
            |--------------------------------------------------------------------------
            */

            if ($user) {
                $data['updated_by'] = $user->id;
            }


            /*
            |--------------------------------------------------------------------------
            | Keep Configuration Active
            |--------------------------------------------------------------------------
            |
            | This is the global configuration record.
            |
            | We do not expose activation/deactivation as a normal
            | business operation.
            |
            */

            $data['is_active'] = true;


            /*
            |--------------------------------------------------------------------------
            | Update
            |--------------------------------------------------------------------------
            */

            $revenueSetting->fill($data);

            $revenueSetting->save();


            /*
            |--------------------------------------------------------------------------
            | Return Fresh Configuration
            |--------------------------------------------------------------------------
            */

            return $revenueSetting->fresh();
        });
    }


    /**
     * Create the initial global configuration if none exists.
     *
     * This is useful for deployment/installation/seeding.
     *
     * It should NOT normally be exposed as a public API endpoint.
     */
    public function ensureExists(?User $user = null): RevenueSetting
    {
        return DB::transaction(function () use ($user) {

            $existing = RevenueSetting::query()
                ->active()
                ->first();

            if ($existing) {
                return $existing;
            }


            /*
            |--------------------------------------------------------------------------
            | Default Configuration
            |--------------------------------------------------------------------------
            */

            $setting = new RevenueSetting();

            $setting->fill([
                'payment_start_month' => 1,
                'payment_start_day' => 1,

                'payment_end_month' => 13,
                'payment_end_day' => 5,

                'penalty_enabled' => true,
                'interest_enabled' => true,

                'assessment_auto_calculation' => true,
                'assessment_allow_manual_adjustment' => false,
                'assessment_requires_approval' => false,
                'assessment_reassessment_allowed' => true,

                'invoice_auto_numbering' => true,
                'invoice_prefix' => 'INV',
                'invoice_allow_overpayment' => false,
                'invoice_allow_overdue_payment' => true,

                'payment_confirmation_required' => true,
                'payment_auto_receipt' => true,

                'enabled_payment_methods' => [
                    'CASH',
                    'BANK',
                    'MOBILE_MONEY',
                ],

                'receipt_auto_numbering' => true,
                'receipt_prefix' => 'REC',
                'receipt_allow_reprint' => true,

                'is_active' => true,

                'legal_reference' => null,
                'description' => null,
            ]);


            if ($user) {
                $setting->created_by = $user->id;
                $setting->updated_by = $user->id;
            }


            $setting->save();

            return $setting->fresh();
        });
    }
}