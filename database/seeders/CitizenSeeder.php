<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use App\Models\Citizen;
use App\Models\User;
use App\Services\CitizenUidService;


class CitizenSeeder extends Seeder
{

    public function run(
        CitizenUidService $uidService
    ): void {


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


            DB::transaction(function () use (
                $data,
                $uidService
            ) {



                /*
                |--------------------------------------------------------------------------
                | Create User
                |--------------------------------------------------------------------------
                */

                $user = User::firstOrCreate(

                    [
                        'phone' => $data['phone'],
                    ],

                    [

                        'id' => Str::uuid(),

                        'name' =>
                            $data['full_name'],

                        'label' =>
                            'Citizen',

                        'password' =>
                            null,

                        'user_type' =>
                            'citizen',

                        'is_phone_verified' =>
                            true,

                        'is_active' =>
                            true,

                    ]

                );






                /*
                |--------------------------------------------------------------------------
                | Generate Citizen UID
                |--------------------------------------------------------------------------
                */

                $citizenUid =
                    $uidService->generate();







                /*
                |--------------------------------------------------------------------------
                | Create Citizen
                |--------------------------------------------------------------------------
                */

                $citizen = Citizen::create([


                    'id' =>
                        Str::uuid(),


                    'citizen_uid' =>
                        $citizenUid,


                    'full_name' =>
                        $data['full_name'],


                    'phone' =>
                        $data['phone'],


                    'national_id' =>
                        $data['national_id'],


                    'address' =>
                        $data['address'],


                    'gender' =>
                        $data['gender'],


                    'date_of_birth' =>
                        $data['date_of_birth'],


                    'source' =>
                        'MANUAL',


                    'is_active' =>
                        true,


                ]);







                /*
                |--------------------------------------------------------------------------
                | Link Account
                |--------------------------------------------------------------------------
                */

                DB::table('citizen_accounts')
                ->insert([
            
                    'id' =>
                        Str::uuid(),
            
                    'user_id' =>
                        $user->id,
            
                    'citizen_id' =>
                        $citizen->id,
            
                    'login_type' =>
                        'OTP',
            
                    'is_active' =>
                        true,
            
                    'created_at' =>
                        now(),
            
                    'updated_at' =>
                        now(),
            
                ]);

            });

        }

    }

}