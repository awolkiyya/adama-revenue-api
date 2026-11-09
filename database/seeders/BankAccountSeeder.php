<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BankAccountSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            [
                'bank_name' => 'Commercial Bank of Ethiopia',
                'account_name' => 'Adama City Administration',
                'account_number' => '100000000001',
                'currency' => 'ETB',
            ],
            [
                'bank_name' => 'Awash Bank',
                'account_name' => 'Adama City Administration',
                'account_number' => '200000000002',
                'currency' => 'ETB',
            ],
            [
                'bank_name' => 'Dashen Bank',
                'account_name' => 'Adama City Administration',
                'account_number' => '300000000003',
                'currency' => 'ETB',
            ],
        ];
        
        foreach ($accounts as $account) {
            $existing = DB::table('bank_accounts')
                ->where('account_number', $account['account_number'])
                ->first();
        
            $values = [
                ...$account,
                'is_active' => true,
                'updated_at' => now(),
            ];
        
            if ($existing) {
                DB::table('bank_accounts')
                    ->where('id', $existing->id)
                    ->update($values);
            } else {
                DB::table('bank_accounts')->insert([
                    'id' => (string) Str::uuid(),
                    ...$values,
                    'created_at' => now(),
                ]);
            }
        }
    }
}