<?php

namespace Database\Seeders;

use App\Models\Citizen;
use App\Models\User;
use App\Services\CitizenUidService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CitizenSeeder extends Seeder
{
    public function run(CitizenUidService $uidService): void
    {
        $citizens = [
            [
                'full_name' => 'Abebe Kebede',
                'phone' => '+251911111111',
                'national_id' => 'ET123456789',
                'gender' => 'MALE',
                'date_of_birth' => '1990-01-15',
                'address' => 'Adama City',
            ],
            [
                'full_name' => 'Aster Tadesse',
                'phone' => '+251922222222',
                'national_id' => 'ET987654321',
                'gender' => 'FEMALE',
                'date_of_birth' => '1995-05-20',
                'address' => 'Adama City',
            ],
        ];

        foreach ($citizens as $data) {
            DB::transaction(function () use ($data, $uidService): void {

                /*
                |--------------------------------------------------------------------------
                | Create or retrieve user account
                |--------------------------------------------------------------------------
                */

                $user = User::firstOrCreate(
                    [
                        'phone' => $data['phone'],
                    ],
                    [
                        'id' => (string) Str::uuid(),
                        'name' => $data['full_name'],
                        'label' => 'Citizen',
                        'password' => null,
                        'user_type' => 'citizen',
                        'is_phone_verified' => true,
                        'is_active' => true,
                    ]
                );

                /*
                |--------------------------------------------------------------------------
                | Create or update citizen
                |--------------------------------------------------------------------------
                |
                | national_id is used as the citizen's natural identifier.
                | Existing citizen UUIDs and citizen UIDs are preserved.
                |
                */

                $citizen = Citizen::firstOrNew([
                    'national_id' => $data['national_id'],
                ]);

                $isNewCitizen = ! $citizen->exists;

                $citizen->full_name = $data['full_name'];
                $citizen->phone = $data['phone'];
                $citizen->national_id = $data['national_id'];
                $citizen->address = $data['address'];
                $citizen->gender = $data['gender'];
                $citizen->date_of_birth = $data['date_of_birth'];
                $citizen->source = 'MANUAL';
                $citizen->is_active = true;

                if ($isNewCitizen) {
                    $citizen->id = (string) Str::uuid();
                    $citizen->citizen_uid = $uidService->generate();
                }

                $citizen->save();

                /*
                |--------------------------------------------------------------------------
                | Link user account to citizen
                |--------------------------------------------------------------------------
                |
                | updateOrInsert prevents duplicate links when the seeder
                | is executed again.
                |
                */

                DB::table('citizen_accounts')->updateOrInsert(
                    [
                        'user_id' => $user->id,
                        'citizen_id' => $citizen->id,
                        'login_type' => 'OTP',
                    ],
                    [
                        'is_active' => true,
                        'updated_at' => now(),
                    ]
                );
            });
        }

        $this->command?->info('Citizen seeder completed successfully.');
    }
}
