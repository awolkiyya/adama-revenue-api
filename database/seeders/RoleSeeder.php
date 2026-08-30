<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $guard = 'api';

        /**
         * `is_system` roles are protected — see App\Models\Role::isSystem().
         * They cannot be deleted or renamed through role management, only
         * seeded/updated here. Everything else is a regular, fully
         * manageable role.
         */
        $roles = [

            /*
            |--------------------------------------------------------------------------
            | System Administration
            |--------------------------------------------------------------------------
            */

            [
                'name' => 'SYSTEM_ADMIN',
                'description' => 'Full system access. Manages roles, permissions, and all administrative settings.',
                'is_system' => true,
            ],

            /*
            |--------------------------------------------------------------------------
            | Data Management
            |--------------------------------------------------------------------------
            */

            [
                'name' => 'DATA_MANAGER',
                'description' => 'Manages and maintains data integrity across modules.',
                'is_system' => false,
            ],

            /*
            |--------------------------------------------------------------------------
            | Executive
            |--------------------------------------------------------------------------
            */

            [
                'name' => 'EXECUTIVE_VIEWER',
                'description' => 'Read-only access to dashboards and reports for leadership oversight.',
                'is_system' => false,
            ],

            /*
            |--------------------------------------------------------------------------
            | Sector Management
            |--------------------------------------------------------------------------
            */

            [
                'name' => 'SECTOR_ADMIN',
                'description' => 'Administers a specific sector, including its officers and settings.',
                'is_system' => false,
            ],

            [
                'name' => 'SECTOR_OFFICER',
                'description' => 'Handles day-to-day operations within an assigned sector.',
                'is_system' => false,
            ],

            /*
            |--------------------------------------------------------------------------
            | Citizen Registration
            |--------------------------------------------------------------------------
            */

            [
                'name' => 'REGISTRATION_OFFICER',
                'description' => 'Registers and verifies citizens.',
                'is_system' => false,
            ],

            /*
            |--------------------------------------------------------------------------
            | Revenue Decision
            |--------------------------------------------------------------------------
            |
            | Responsible for revenue assessments and tariff decisions.
            |
            */

            [
                'name' => 'REVENUE_DECISION_OFFICER',
                'description' => 'Reviews and approves revenue assessments and tariff decisions.',
                'is_system' => false,
            ],

            /*
            |--------------------------------------------------------------------------
            | Revenue Complaint
            |--------------------------------------------------------------------------
            |
            | Garee Komii
            |
            | Responsible for taxpayer complaints, disputes, appeals, and
            | requests for review.
            |
            */

            [
                'name' => 'REVENUE_COMPLAINT_OFFICER',
                'description' => 'Handles taxpayer complaints, revenue disputes, appeals, review requests, supporting evidence, and complaint resolution workflows.',
                'is_system' => false,
            ],

            /*
            |--------------------------------------------------------------------------
            | Revenue Tax Administration
            |--------------------------------------------------------------------------
            |
            | Garee Hordoffii Taxii
            |
            | Responsible for administering taxpayer tax obligations,
            | invoices, penalties, discounts, and related tax operations.
            |
            | This role is NOT the payment collector.
            |
            */

            [
                'name' => 'REVENUE_TAX_ADMINISTRATION_OFFICER',
                'description' => 'Administers taxpayer tax obligations, invoices, penalties, discounts, authorized adjustments, and related revenue administration activities.',
                'is_system' => false,
            ],

            /*
            |--------------------------------------------------------------------------
            | Revenue Collection
            |--------------------------------------------------------------------------
            |
            | Responsible for receiving and recording actual payments.
            |
            */

            [
                'name' => 'REVENUE_COLLECTOR',
                'description' => 'Collects and records taxpayer payments and issues payment receipts.',
                'is_system' => false,
            ],
        ];

        /*
        |--------------------------------------------------------------------------
        | Create / Update Roles
        |--------------------------------------------------------------------------
        */

        foreach ($roles as $role) {

            Role::updateOrCreate(
                [
                    'name' => $role['name'],
                    'guard_name' => $guard,
                ],
                [
                    'description' => $role['description'],
                    'is_system' => $role['is_system'],
                ]
            );
        }
    }
}