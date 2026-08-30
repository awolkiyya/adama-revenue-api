<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class ServiceActionSeeder extends Seeder
{
    public function run(): void
    {

        $actions = [

            [
                'code' => 'CREATE_REQUEST',
                'name' => 'Create Request',
                'description' => 'Create a new revenue service request for a citizen.',
            ],


            [
                'code' => 'SUBMIT_REQUEST',
                'name' => 'Submit Request',
                'description' => 'Submit the request for further processing.',
            ],


            [
                'code' => 'ASSESS',
                'name' => 'Perform Assessment',
                'description' => 'Calculate and prepare the revenue assessment.',
            ],


            [
                'code' => 'APPROVE_ASSESSMENT',
                'name' => 'Approve Assessment',
                'description' => 'Approve the assessment result before payment collection.',
            ],


            [
                'code' => 'REQUEST_CORRECTION',
                'name' => 'Request Correction',
                'description' => 'Request missing information or correction from another office.',
            ],


            [
                'code' => 'REJECT_REQUEST',
                'name' => 'Reject Request',
                'description' => 'Reject the service request due to invalid information.',
            ],


            [
                'code' => 'COLLECT_PAYMENT',
                'name' => 'Collect Payment',
                'description' => 'Collect payment from the citizen.',
            ],



            [
                'code' => 'CANCEL_REQUEST',
                'name' => 'Cancel Request',
                'description' => 'Cancel an existing revenue service request.',
            ],


            [
                'code' => 'TRANSFER_REQUEST',
                'name' => 'Transfer Request',
                'description' => 'Transfer the request to another responsible office.',
            ],

        ];


        foreach ($actions as $action) {

            DB::table('service_actions')->updateOrInsert(

                [
                    'code' => $action['code']
                ],

                [
                    'id' => Str::uuid(),

                    'name' => $action['name'],

                    'description' => $action['description'],

                    'is_active' => true,

                    'updated_at' => now(),

                    'created_at' => now(),
                ]

            );

        }

    }
}