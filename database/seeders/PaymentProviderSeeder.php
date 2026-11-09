<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentProviderSeeder extends Seeder
{
    public function run(): void
    {
        $providers = [
            [
                'code' => 'TELEBIRR',
                'name' => 'Telebirr',
                'fee_percentage' => 0,
            ],
            [
                'code' => 'CBE_BIRR',
                'name' => 'CBE Birr',
                'fee_percentage' => 0,
            ],
            [
                'code' => 'CHAPA',
                'name' => 'Chapa',
                'fee_percentage' => 0,
            ],
        ];
        
        foreach ($providers as $provider) {
            $existing = DB::table('payment_providers')
                ->where('code', $provider['code'])
                ->first();
        
            $values = [
                ...$provider,
                'is_active' => true,
                'updated_at' => now(),
            ];
        
            if ($existing) {
                DB::table('payment_providers')
                    ->where('id', $existing->id)
                    ->update($values);
            } else {
                DB::table('payment_providers')->insert([
                    'id' => (string) Str::uuid(),
                    ...$values,
                    'created_at' => now(),
                ]);
            }
        }
    }
}