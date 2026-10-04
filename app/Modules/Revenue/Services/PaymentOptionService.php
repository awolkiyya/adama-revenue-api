<?php

namespace App\Modules\Revenue\Services;

use App\Models\BankAccount;
use App\Models\PaymentProvider;
use App\Models\RevenueSetting;

class PaymentOptionService
{
    public function getAvailableOptions(): array
    {
        $settings = RevenueSetting::query()
            ->where('is_active', true)
            ->firstOrFail();

        $methods = collect(
            $settings->enabled_payment_methods
        );

        return [
            'payment_methods' => $methods->values()->all(),

            'bank_accounts' => $methods->contains('BANK')
                ? BankAccount::query()
                    ->where('is_active', true)
                    ->orderBy('bank_name')
                    ->get()
                : collect(),

            'payment_providers' => $methods->contains('MOBILE_MONEY')
                ? PaymentProvider::query()
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->get()
                : collect(),
        ];
    }
}