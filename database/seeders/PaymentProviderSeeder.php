<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentProviderSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('payment_providers')->insert([
            [
                'id' => (string) Str::uuid(),
                'code' => 'TELEBIRR',
                'name' => 'Telebirr',
                'fee_percentage' => 0,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::uuid(),
                'code' => 'CBE_BIRR',
                'name' => 'CBE Birr',
                'fee_percentage' => 0,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::uuid(),
                'code' => 'CHAPA',
                'name' => 'Chapa',
                'fee_percentage' => 0,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}