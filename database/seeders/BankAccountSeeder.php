<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BankAccountSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('bank_accounts')->insert([
            [
                'id' => (string) Str::uuid(),
                'bank_name' => 'Commercial Bank of Ethiopia',
                'account_name' => 'Adama City Administration',
                'account_number' => '100000000001',
                'currency' => 'ETB',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::uuid(),
                'bank_name' => 'Awash Bank',
                'account_name' => 'Adama City Administration',
                'account_number' => '200000000002',
                'currency' => 'ETB',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::uuid(),
                'bank_name' => 'Dashen Bank',
                'account_name' => 'Adama City Administration',
                'account_number' => '300000000003',
                'currency' => 'ETB',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}