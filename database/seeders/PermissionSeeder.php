<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class PermissionSeeder extends Seeder
{
    /**
     * Canonical permission catalog.
     *
     * IMPORTANT:
     * ---------------------------------------------------------------
     * This seeder defines WHAT permissions exist.
     *
     * It does NOT define:
     * - which role receives a permission
     * - which user receives a role
     * - frontend authorization
     * - organizational authorization
     * - administrative-unit scope
     *
     * Role/permission assignments are managed separately.
     *
     * Production safety:
     * ---------------------------------------------------------------
     * - Existing permissions are updated, not recreated.
     * - Existing role_permission relationships are preserved.
     * - Permissions missing from this catalog are NOT deleted.
     * - Catalog validation occurs before database changes.
     * - Synchronization runs inside a transaction.
     */
    public function run(): void
    {
        $guard = 'api';

        /*
        |--------------------------------------------------------------------------
        | Canonical Permission Catalog
        |--------------------------------------------------------------------------
        */

        $permissions = [

            /*
            |--------------------------------------------------------------------------
            | Dashboard
            |--------------------------------------------------------------------------
            */

            [
                'name' => 'dashboard.view',
                'label' => 'View Dashboard',
                'module' => 'dashboard',
                'description' => 'Allows viewing dashboard information and key system indicators.',
                'is_system' => true,
            ],

            [
                'name' => 'analytics.view',
                'label' => 'View Analytics',
                'module' => 'analytics',
                'description' => 'Allows viewing analytics, statistics, trends, and analytical information.',
                'is_system' => true,
            ],

            /*
            |--------------------------------------------------------------------------
            | User Management
            |--------------------------------------------------------------------------
            */

            [
                'name' => 'users.view',
                'label' => 'View Users',
                'module' => 'users',
                'description' => 'Allows viewing system users.',
                'is_system' => true,
            ],

            [
                'name' => 'users.create',
                'label' => 'Create User',
                'module' => 'users',
                'description' => 'Allows creating new system users.',
                'is_system' => true,
            ],

            [
                'name' => 'users.update',
                'label' => 'Update User',
                'module' => 'users',
                'description' => 'Allows updating eligible user information.',
                'is_system' => true,
            ],

            [
                'name' => 'users.update_password',
                'label' => 'Update User Password',
                'module' => 'users',
                'description' => 'Allows changing the password of eligible user accounts.',
                'is_system' => true,
            ],

            [
                'name' => 'users.delete',
                'label' => 'Delete User',
                'module' => 'users',
                'description' => 'Allows deleting eligible user accounts according to system policy.',
                'is_system' => true,
            ],

            [
                'name' => 'users.activate',
                'label' => 'Activate User',
                'module' => 'users',
                'description' => 'Allows activating a deactivated user account.',
                'is_system' => true,
            ],

            [
                'name' => 'users.deactivate',
                'label' => 'Deactivate User',
                'module' => 'users',
                'description' => 'Allows deactivating an eligible user account.',
                'is_system' => true,
            ],

            [
                'name' => 'users.assign_roles',
                'label' => 'Assign User Roles',
                'module' => 'users',
                'description' => 'Allows assigning eligible roles to users.',
                'is_system' => true,
            ],

            [
                'name' => 'users.view_history',
                'label' => 'View User History',
                'module' => 'users',
                'description' => 'Allows viewing user account activity and history.',
                'is_system' => true,
            ],

            /*
            |--------------------------------------------------------------------------
            | Role Management
            |--------------------------------------------------------------------------
            */

            [
                'name' => 'roles.view',
                'label' => 'View Roles',
                'module' => 'roles',
                'description' => 'Allows viewing roles and their assigned permissions.',
                'is_system' => true,
            ],

            [
                'name' => 'roles.create',
                'label' => 'Create Role',
                'module' => 'roles',
                'description' => 'Allows creating non-system roles.',
                'is_system' => true,
            ],

            [
                'name' => 'roles.update',
                'label' => 'Update Role',
                'module' => 'roles',
                'description' => 'Allows updating eligible non-system roles.',
                'is_system' => true,
            ],

            [
                'name' => 'roles.delete',
                'label' => 'Delete Role',
                'module' => 'roles',
                'description' => 'Allows deleting eligible non-system roles.',
                'is_system' => true,
            ],

            [
                'name' => 'roles.assign_permissions',
                'label' => 'Assign Role Permissions',
                'module' => 'roles',
                'description' => 'Allows assigning existing permissions to eligible roles.',
                'is_system' => true,
            ],

            [
                'name' => 'roles.revoke_permissions',
                'label' => 'Revoke Role Permissions',
                'module' => 'roles',
                'description' => 'Allows revoking permissions from eligible roles.',
                'is_system' => true,
            ],

            [
                'name' => 'roles.view_history',
                'label' => 'View Role History',
                'module' => 'roles',
                'description' => 'Allows viewing role and permission assignment history.',
                'is_system' => true,
            ],

            /*
            |--------------------------------------------------------------------------
            | Permission Catalog
            |--------------------------------------------------------------------------
            */

            [
                'name' => 'permissions.view',
                'label' => 'View Permissions',
                'module' => 'permissions',
                'description' => 'Allows viewing the backend-defined permission catalog.',
                'is_system' => true,
            ],

            /*
            |--------------------------------------------------------------------------
            | Administrative Units
            |--------------------------------------------------------------------------
            */

            [
                'name' => 'administrative_units.view',
                'label' => 'View Administrative Units',
                'module' => 'administrative_units',
                'description' => 'Allows viewing the administrative structure.',
                'is_system' => true,
            ],

            [
                'name' => 'administrative_units.create',
                'label' => 'Create Administrative Unit',
                'module' => 'administrative_units',
                'description' => 'Allows creating administrative units.',
                'is_system' => true,
            ],

            [
                'name' => 'administrative_units.update',
                'label' => 'Update Administrative Unit',
                'module' => 'administrative_units',
                'description' => 'Allows updating eligible administrative units.',
                'is_system' => true,
            ],

            [
                'name' => 'administrative_units.delete',
                'label' => 'Delete Administrative Unit',
                'module' => 'administrative_units',
                'description' => 'Allows deleting administrative units according to system policy.',
                'is_system' => true,
            ],

            /*
            |--------------------------------------------------------------------------
            | Sector Management
            |--------------------------------------------------------------------------
            */

            [
                'name' => 'sectors.view',
                'label' => 'View Sectors',
                'module' => 'sectors',
                'description' => 'Allows viewing sectors.',
                'is_system' => true,
            ],

            [
                'name' => 'sectors.create',
                'label' => 'Create Sector',
                'module' => 'sectors',
                'description' => 'Allows creating sectors.',
                'is_system' => true,
            ],

            [
                'name' => 'sectors.update',
                'label' => 'Update Sector',
                'module' => 'sectors',
                'description' => 'Allows updating eligible sectors.',
                'is_system' => true,
            ],

            [
                'name' => 'sectors.delete',
                'label' => 'Delete Sector',
                'module' => 'sectors',
                'description' => 'Allows deleting sectors according to system policy.',
                'is_system' => true,
            ],

            /*
            |--------------------------------------------------------------------------
            | Citizen Management
            |--------------------------------------------------------------------------
            */

            [
                'name' => 'citizens.view',
                'label' => 'View Citizens',
                'module' => 'citizens',
                'description' => 'Allows viewing citizen records.',
                'is_system' => false,
            ],

            [
                'name' => 'citizens.create',
                'label' => 'Register Citizen',
                'module' => 'citizens',
                'description' => 'Allows registering citizens.',
                'is_system' => false,
            ],

            [
                'name' => 'citizens.update',
                'label' => 'Update Citizen',
                'module' => 'citizens',
                'description' => 'Allows updating eligible citizen information.',
                'is_system' => false,
            ],

            [
                'name' => 'citizens.verify',
                'label' => 'Verify Citizen',
                'module' => 'citizens',
                'description' => 'Allows verifying citizen information.',
                'is_system' => false,
            ],

            [
                'name' => 'citizens.import',
                'label' => 'Import Citizens',
                'module' => 'citizens',
                'description' => 'Allows importing citizen records.',
                'is_system' => false,
            ],

            [
                'name' => 'citizens.view_history',
                'label' => 'View Citizen History',
                'module' => 'citizens',
                'description' => 'Allows viewing citizen registration and verification history.',
                'is_system' => false,
            ],

            /*
            |--------------------------------------------------------------------------
            | System Settings
            |--------------------------------------------------------------------------
            */

            [
                'name' => 'system_settings.view',
                'label' => 'View System Settings',
                'module' => 'system_settings',
                'description' => 'Allows viewing system configuration and settings.',
                'is_system' => true,
            ],

            [
                'name' => 'system_settings.update',
                'label' => 'Update System Settings',
                'module' => 'system_settings',
                'description' => 'Allows updating authorized system configuration and settings.',
                'is_system' => true,
            ],

            /*
            |--------------------------------------------------------------------------
            | Calculation Setup - Measurement Units
            |--------------------------------------------------------------------------
            */

            [
                'name' => 'measurement_units.view',
                'label' => 'View Measurement Units',
                'module' => 'measurement_units',
                'description' => 'Allows viewing measurement units used by revenue calculations.',
                'is_system' => false,
            ],

            [
                'name' => 'measurement_units.create',
                'label' => 'Create Measurement Unit',
                'module' => 'measurement_units',
                'description' => 'Allows creating measurement units.',
                'is_system' => false,
            ],

            [
                'name' => 'measurement_units.update',
                'label' => 'Update Measurement Unit',
                'module' => 'measurement_units',
                'description' => 'Allows updating measurement unit information.',
                'is_system' => false,
            ],

            [
                'name' => 'measurement_units.delete',
                'label' => 'Delete Measurement Unit',
                'module' => 'measurement_units',
                'description' => 'Allows deleting measurement units.',
                'is_system' => false,
            ],

            [
                'name' => 'measurement_units.restore',
                'label' => 'Restore Measurement Unit',
                'module' => 'measurement_units',
                'description' => 'Allows restoring deleted measurement units.',
                'is_system' => false,
            ],

            [
                'name' => 'measurement_units.activate',
                'label' => 'Activate Measurement Unit',
                'module' => 'measurement_units',
                'description' => 'Allows activating measurement units.',
                'is_system' => false,
            ],

            [
                'name' => 'measurement_units.deactivate',
                'label' => 'Deactivate Measurement Unit',
                'module' => 'measurement_units',
                'description' => 'Allows deactivating measurement units.',
                'is_system' => false,
            ],

            /*
            |--------------------------------------------------------------------------
            | Calculation Setup - Base Fields
            |--------------------------------------------------------------------------
            */

            [
                'name' => 'base_fields.view',
                'label' => 'View Base Fields',
                'module' => 'base_fields',
                'description' => 'Allows viewing base fields used by revenue assessment calculations.',
                'is_system' => false,
            ],

            [
                'name' => 'base_fields.create',
                'label' => 'Create Base Field',
                'module' => 'base_fields',
                'description' => 'Allows creating calculation base fields.',
                'is_system' => false,
            ],

            [
                'name' => 'base_fields.update',
                'label' => 'Update Base Field',
                'module' => 'base_fields',
                'description' => 'Allows updating eligible calculation base fields.',
                'is_system' => false,
            ],

            [
                'name' => 'base_fields.delete',
                'label' => 'Delete Base Field',
                'module' => 'base_fields',
                'description' => 'Allows deleting eligible calculation base fields.',
                'is_system' => false,
            ],

            /*
            |--------------------------------------------------------------------------
            | Revenue Categories
            |--------------------------------------------------------------------------
            */

            [
                'name' => 'revenue_categorys.view',
                'label' => 'View Revenue Categories',
                'module' => 'revenue_categorys',
                'description' => 'Allows viewing revenue categories.',
                'is_system' => false,
            ],

            [
                'name' => 'revenue_categorys.create',
                'label' => 'Create Revenue Category',
                'module' => 'revenue_categorys',
                'description' => 'Allows creating revenue categories.',
                'is_system' => false,
            ],

            [
                'name' => 'revenue_categorys.update',
                'label' => 'Update Revenue Category',
                'module' => 'revenue_categorys',
                'description' => 'Allows updating eligible revenue categories.',
                'is_system' => false,
            ],

            [
                'name' => 'revenue_categorys.delete',
                'label' => 'Delete Revenue Category',
                'module' => 'revenue_categorys',
                'description' => 'Allows deleting eligible revenue categories.',
                'is_system' => false,
            ],


            /*
            |--------------------------------------------------------------------------
            | Revenue Services
            |--------------------------------------------------------------------------
            */

            [
                'name' => 'revenue_services.view',
                'label' => 'View Revenue Services',
                'module' => 'revenue_services',
                'description' => 'Allows viewing revenue services and their configurations.',
                'is_system' => false,
            ],

            [
                'name' => 'revenue_services.create',
                'label' => 'Create Revenue Service',
                'module' => 'revenue_services',
                'description' => 'Allows creating revenue services.',
                'is_system' => false,
            ],

            [
                'name' => 'revenue_services.update',
                'label' => 'Update Revenue Service',
                'module' => 'revenue_services',
                'description' => 'Allows updating eligible revenue service configuration.',
                'is_system' => false,
            ],

            [
                'name' => 'revenue_services.activate',
                'label' => 'Activate Revenue Service',
                'module' => 'revenue_services',
                'description' => 'Allows activating a revenue service.',
                'is_system' => false,
            ],

            [
                'name' => 'revenue_services.deactivate',
                'label' => 'Deactivate Revenue Service',
                'module' => 'revenue_services',
                'description' => 'Allows deactivating a revenue service.',
                'is_system' => false,
            ],

            [
                'name' => 'revenue_services.view_history',
                'label' => 'View Revenue Service History',
                'module' => 'revenue_services',
                'description' => 'Allows viewing revenue service configuration history.',
                'is_system' => false,
            ],


            /*
            |--------------------------------------------------------------------------
            | Tariff Management
            |--------------------------------------------------------------------------
            */

            [
                'name' => 'tariff.view',
                'label' => 'View Tariffs',
                'module' => 'tariff',
                'description' => 'Allows viewing tariff rules and tariff versions.',
                'is_system' => false,
            ],

            [
                'name' => 'tariff.create',
                'label' => 'Create Tariff',
                'module' => 'tariff',
                'description' => 'Allows creating tariff rules and tariff configurations.',
                'is_system' => false,
            ],

            [
                'name' => 'tariff.update',
                'label' => 'Update Tariff',
                'module' => 'tariff',
                'description' => 'Allows updating tariff rules and tariff configurations.',
                'is_system' => false,
            ],

            [
                'name' => 'tariff.submit',
                'label' => 'Submit Tariff',
                'module' => 'tariff',
                'description' => 'Allows submitting tariff versions for approval.',
                'is_system' => false,
            ],

            [
                'name' => 'tariff.approve',
                'label' => 'Approve Tariff',
                'module' => 'tariff',
                'description' => 'Allows approving tariff versions.',
                'is_system' => false,
            ],

            [
                'name' => 'tariff.reject',
                'label' => 'Reject Tariff',
                'module' => 'tariff',
                'description' => 'Allows rejecting tariff versions.',
                'is_system' => false,
            ],

            [
                'name' => 'tariff.view_history',
                'label' => 'View Tariff History',
                'module' => 'tariff',
                'description' => 'Allows viewing tariff version and configuration history.',
                'is_system' => false,
            ],

            /*
            |--------------------------------------------------------------------------
            | Tariff Versions
            |--------------------------------------------------------------------------
            */

            [
                'name' => 'tariff_version.view',
                'label' => 'View Tariff Versions',
                'module' => 'tariff_version',
                'description' => 'Allows viewing tariff versions and their lifecycle status.',
                'is_system' => false,
            ],

            [
                'name' => 'tariff_version.create',
                'label' => 'Create Tariff Version',
                'module' => 'tariff_version',
                'description' => 'Allows creating tariff versions.',
                'is_system' => false,
            ],

            [
                'name' => 'tariff_version.update',
                'label' => 'Update Tariff Version',
                'module' => 'tariff_version',
                'description' => 'Allows updating eligible tariff versions.',
                'is_system' => false,
            ],

            [
                'name' => 'tariff_version.submit',
                'label' => 'Submit Tariff Version',
                'module' => 'tariff_version',
                'description' => 'Allows submitting tariff versions for approval.',
                'is_system' => false,
            ],

            [
                'name' => 'tariff_version.approve',
                'label' => 'Approve Tariff Version',
                'module' => 'tariff_version',
                'description' => 'Allows approving tariff versions.',
                'is_system' => false,
            ],

            [
                'name' => 'tariff_version.reject',
                'label' => 'Reject Tariff Version',
                'module' => 'tariff_version',
                'description' => 'Allows rejecting tariff versions.',
                'is_system' => false,
            ],

            [
                'name' => 'tariff_version.view_history',
                'label' => 'View Tariff Version History',
                'module' => 'tariff_version',
                'description' => 'Allows viewing tariff version history.',
                'is_system' => false,
            ],


           /*
            |--------------------------------------------------------------------------
            | Interest Rule Configuration
            |--------------------------------------------------------------------------
            */

            [
                'name' => 'interest_rules.view',
                'label' => 'View Interest Rules',
                'module' => 'interest_rules',
                'description' => 'Allows viewing configured interest rules, including rates, calculation methods, calculation bases, and effective periods.',
                'is_system' => false,
            ],

            [
                'name' => 'interest_rules.create',
                'label' => 'Create Interest Rule',
                'module' => 'interest_rules',
                'description' => 'Allows creating new interest rule configurations with rates, calculation methods, calculation bases, and effective periods.',
                'is_system' => false,
            ],

            [
                'name' => 'interest_rules.update',
                'label' => 'Update Interest Rule',
                'module' => 'interest_rules',
                'description' => 'Allows updating eligible interest rule configurations according to system policy.',
                'is_system' => false,
            ],

            [
                'name' => 'interest_rules.activate',
                'label' => 'Activate Interest Rule',
                'module' => 'interest_rules',
                'description' => 'Allows activating an eligible interest rule configuration.',
                'is_system' => false,
            ],

            [
                'name' => 'interest_rules.deactivate',
                'label' => 'Deactivate Interest Rule',
                'module' => 'interest_rules',
                'description' => 'Allows deactivating an eligible interest rule configuration.',
                'is_system' => false,
            ],

            [
                'name' => 'interest_rules.view_history',
                'label' => 'View Interest Rule History',
                'module' => 'interest_rules',
                'description' => 'Allows viewing the history of interest rule configurations and changes.',
                'is_system' => false,
            ],


            /*
            |--------------------------------------------------------------------------
            | Penalty Rule Configuration
            |--------------------------------------------------------------------------
            */

            [
                'name' => 'penalty_rules.view',
                'label' => 'View Penalty Rules',
                'module' => 'penalty_rules',
                'description' => 'Allows viewing configured penalty rules, including default rules and service-specific overrides.',
                'is_system' => false,
            ],

            [
                'name' => 'penalty_rules.create',
                'label' => 'Create Penalty Rule',
                'module' => 'penalty_rules',
                'description' => 'Allows creating new penalty rule configurations for tariff versions and revenue services.',
                'is_system' => false,
            ],

            [
                'name' => 'penalty_rules.update',
                'label' => 'Update Penalty Rule',
                'module' => 'penalty_rules',
                'description' => 'Allows updating eligible penalty rule configurations according to system policy.',
                'is_system' => false,
            ],

            [
                'name' => 'penalty_rules.activate',
                'label' => 'Activate Penalty Rule',
                'module' => 'penalty_rules',
                'description' => 'Allows activating an eligible penalty rule configuration.',
                'is_system' => false,
            ],

            [
                'name' => 'penalty_rules.deactivate',
                'label' => 'Deactivate Penalty Rule',
                'module' => 'penalty_rules',
                'description' => 'Allows deactivating an eligible penalty rule configuration.',
                'is_system' => false,
            ],

            [
                'name' => 'penalty_rules.view_history',
                'label' => 'View Penalty Rule History',
                'module' => 'penalty_rules',
                'description' => 'Allows viewing the history of penalty rule configurations and changes.',
                'is_system' => false,
            ],






            /*
            |--------------------------------------------------------------------------
            | Taxpayer Management
            |--------------------------------------------------------------------------
            */

            // [
            //     'name' => 'taxpayer.view',
            //     'label' => 'View Taxpayers',
            //     'module' => 'taxpayer',
            //     'description' => 'Allows viewing taxpayer records and taxpayer information.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'taxpayer.create',
            //     'label' => 'Create Taxpayer',
            //     'module' => 'taxpayer',
            //     'description' => 'Allows registering new taxpayers.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'taxpayer.update',
            //     'label' => 'Update Taxpayer',
            //     'module' => 'taxpayer',
            //     'description' => 'Allows updating eligible taxpayer information.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'taxpayer.verify',
            //     'label' => 'Verify Taxpayer',
            //     'module' => 'taxpayer',
            //     'description' => 'Allows verifying taxpayer information.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'taxpayer.view_history',
            //     'label' => 'View Taxpayer History',
            //     'module' => 'taxpayer',
            //     'description' => 'Allows viewing taxpayer registration and account history.',
            //     'is_system' => false,
            // ],



            /*
            |--------------------------------------------------------------------------
            | Access Management
            |--------------------------------------------------------------------------
            */

            // [
            //     'name' => 'access_management.view',
            //     'label' => 'View Access Management',
            //     'module' => 'access_management',
            //     'description' => 'Allows viewing role, permission, and access management information.',
            //     'is_system' => true,
            // ],

            // [
            //     'name' => 'access_management.manage',
            //     'label' => 'Manage Access',
            //     'module' => 'access_management',
            //     'description' => 'Allows managing authorized role and permission assignments.',
            //     'is_system' => true,
            // ],



             /*
            |--------------------------------------------------------------------------
            | Revenue General
            |--------------------------------------------------------------------------
            */

            // [
            //     'name' => 'revenue.view',
            //     'label' => 'View Revenue',
            //     'module' => 'revenue',
            //     'description' => 'Allows viewing revenue records and revenue information.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'revenue.verify',
            //     'label' => 'Verify Revenue',
            //     'module' => 'revenue',
            //     'description' => 'Allows verifying revenue records and transactions.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'revenue.approve',
            //     'label' => 'Approve Revenue',
            //     'module' => 'revenue',
            //     'description' => 'Allows approving authorized revenue decisions.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'revenue.collect',
            //     'label' => 'Collect Revenue',
            //     'module' => 'revenue',
            //     'description' => 'Allows performing authorized revenue collection activities.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'revenue.view_history',
            //     'label' => 'View Revenue History',
            //     'module' => 'revenue',
            //     'description' => 'Allows viewing historical revenue transactions and actions.',
            //     'is_system' => false,
            // ],

             /*
            |--------------------------------------------------------------------------
            | Assessment
            |--------------------------------------------------------------------------
            */

            // [
            //     'name' => 'assessment.view',
            //     'label' => 'View Assessments',
            //     'module' => 'assessment',
            //     'description' => 'Allows viewing revenue assessments.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'assessment.create',
            //     'label' => 'Create Assessment',
            //     'module' => 'assessment',
            //     'description' => 'Allows creating revenue assessment data.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'assessment.update',
            //     'label' => 'Update Assessment',
            //     'module' => 'assessment',
            //     'description' => 'Allows updating assessment data before finalization.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'assessment.submit',
            //     'label' => 'Submit Assessment',
            //     'module' => 'assessment',
            //     'description' => 'Allows submitting assessments for review.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'assessment.verify',
            //     'label' => 'Verify Assessment',
            //     'module' => 'assessment',
            //     'description' => 'Allows verifying revenue assessments.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'assessment.approve',
            //     'label' => 'Approve Assessment',
            //     'module' => 'assessment',
            //     'description' => 'Allows approving revenue assessments.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'assessment.reject',
            //     'label' => 'Reject Assessment',
            //     'module' => 'assessment',
            //     'description' => 'Allows rejecting revenue assessments.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'assessment.view_history',
            //     'label' => 'View Assessment History',
            //     'module' => 'assessment',
            //     'description' => 'Allows viewing assessment changes and workflow history.',
            //     'is_system' => false,
            // ],






            /*
            |--------------------------------------------------------------------------
            | Invoice Management
            |--------------------------------------------------------------------------
            */

            // [
            //     'name' => 'invoice.view',
            //     'label' => 'View Invoices',
            //     'module' => 'invoice',
            //     'description' => 'Allows viewing invoices, balances, penalties, discounts, adjustments, and payment status.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'invoice.create',
            //     'label' => 'Create Invoice',
            //     'module' => 'invoice',
            //     'description' => 'Allows creating invoices from approved assessments.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'invoice.update',
            //     'label' => 'Update Invoice',
            //     'module' => 'invoice',
            //     'description' => 'Allows updating eligible invoice information before finalization.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'invoice.issue',
            //     'label' => 'Issue Invoice',
            //     'module' => 'invoice',
            //     'description' => 'Allows issuing invoices to taxpayers.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'invoice.cancel',
            //     'label' => 'Cancel Invoice',
            //     'module' => 'invoice',
            //     'description' => 'Allows cancelling eligible invoices according to policy.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'invoice.recalculate',
            //     'label' => 'Recalculate Invoice',
            //     'module' => 'invoice',
            //     'description' => 'Allows requesting or performing authorized invoice recalculation.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'invoice.view_history',
            //     'label' => 'View Invoice History',
            //     'module' => 'invoice',
            //     'description' => 'Allows viewing invoice changes, recalculations, and historical versions.',
            //     'is_system' => false,
            // ],

            /*
            |--------------------------------------------------------------------------
            | Penalty Management
            |--------------------------------------------------------------------------
            */

            // [
            //     'name' => 'penalty.view',
            //     'label' => 'View Penalties',
            //     'module' => 'penalty',
            //     'description' => 'Allows viewing penalties applied to taxpayer obligations and invoices.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'penalty.calculate',
            //     'label' => 'Calculate Penalty',
            //     'module' => 'penalty',
            //     'description' => 'Allows calculating penalties according to configured penalty rules.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'penalty.apply',
            //     'label' => 'Apply Penalty',
            //     'module' => 'penalty',
            //     'description' => 'Allows applying an authorized penalty to an eligible obligation or invoice.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'penalty.adjust',
            //     'label' => 'Adjust Penalty',
            //     'module' => 'penalty',
            //     'description' => 'Allows making authorized penalty adjustments.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'penalty.waive',
            //     'label' => 'Waive Penalty',
            //     'module' => 'penalty',
            //     'description' => 'Allows waiving an eligible penalty according to authorized procedures.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'penalty.view_history',
            //     'label' => 'View Penalty History',
            //     'module' => 'penalty',
            //     'description' => 'Allows viewing the complete history of penalty calculations and adjustments.',
            //     'is_system' => false,
            // ],

            /*
            |--------------------------------------------------------------------------
            | Discount Management
            |--------------------------------------------------------------------------
            */

            // [
            //     'name' => 'discount.view',
            //     'label' => 'View Discounts',
            //     'module' => 'discount',
            //     'description' => 'Allows viewing discounts applicable to taxpayer obligations and invoices.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'discount.calculate',
            //     'label' => 'Calculate Discount',
            //     'module' => 'discount',
            //     'description' => 'Allows calculating discounts according to configured rules.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'discount.apply',
            //     'label' => 'Apply Discount',
            //     'module' => 'discount',
            //     'description' => 'Allows applying an authorized discount to an eligible obligation or invoice.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'discount.remove',
            //     'label' => 'Remove Discount',
            //     'module' => 'discount',
            //     'description' => 'Allows removing an eligible discount according to authorized procedures.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'discount.view_history',
            //     'label' => 'View Discount History',
            //     'module' => 'discount',
            //     'description' => 'Allows viewing the complete history of discounts applied to taxpayer obligations.',
            //     'is_system' => false,
            // ],

            /*
            |--------------------------------------------------------------------------
            | Revenue Adjustments
            |--------------------------------------------------------------------------
            */

            // [
            //     'name' => 'revenue_adjustments.view',
            //     'label' => 'View Revenue Adjustments',
            //     'module' => 'revenue_adjustments',
            //     'description' => 'Allows viewing revenue adjustment requests and adjustment history.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'revenue_adjustments.create',
            //     'label' => 'Create Revenue Adjustment',
            //     'module' => 'revenue_adjustments',
            //     'description' => 'Allows creating authorized revenue adjustment requests.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'revenue_adjustments.review',
            //     'label' => 'Review Revenue Adjustment',
            //     'module' => 'revenue_adjustments',
            //     'description' => 'Allows reviewing adjustment requests, reasons, supporting documents, penalties, discounts, and calculated effects.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'revenue_adjustments.approve',
            //     'label' => 'Approve Revenue Adjustment',
            //     'module' => 'revenue_adjustments',
            //     'description' => 'Allows approving authorized revenue adjustments.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'revenue_adjustments.reject',
            //     'label' => 'Reject Revenue Adjustment',
            //     'module' => 'revenue_adjustments',
            //     'description' => 'Allows rejecting revenue adjustment requests.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'revenue_adjustments.cancel',
            //     'label' => 'Cancel Revenue Adjustment',
            //     'module' => 'revenue_adjustments',
            //     'description' => 'Allows cancelling eligible pending adjustment requests.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'revenue_adjustments.view_history',
            //     'label' => 'View Revenue Adjustment History',
            //     'module' => 'revenue_adjustments',
            //     'description' => 'Allows viewing the complete history of revenue adjustments and decisions.',
            //     'is_system' => false,
            // ],

            /*
            |--------------------------------------------------------------------------
            | Revenue Complaints
            |--------------------------------------------------------------------------
            */

            // [
            //     'name' => 'revenue_complaints.view',
            //     'label' => 'View Revenue Complaints',
            //     'module' => 'revenue_complaints',
            //     'description' => 'Allows viewing taxpayer revenue complaints and disputes.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'revenue_complaints.create',
            //     'label' => 'Create Revenue Complaint',
            //     'module' => 'revenue_complaints',
            //     'description' => 'Allows registering taxpayer complaints and revenue disputes.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'revenue_complaints.update',
            //     'label' => 'Update Revenue Complaint',
            //     'module' => 'revenue_complaints',
            //     'description' => 'Allows updating eligible complaint information before final decision.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'revenue_complaints.review',
            //     'label' => 'Review Revenue Complaint',
            //     'module' => 'revenue_complaints',
            //     'description' => 'Allows investigating complaints, disputes, invoices, assessments, payments, and supporting evidence.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'revenue_complaints.request_information',
            //     'label' => 'Request Complaint Information',
            //     'module' => 'revenue_complaints',
            //     'description' => 'Allows requesting additional information or supporting documents.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'revenue_complaints.recommend',
            //     'label' => 'Recommend Complaint Resolution',
            //     'module' => 'revenue_complaints',
            //     'description' => 'Allows recommending a resolution for a taxpayer complaint.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'revenue_complaints.reject',
            //     'label' => 'Reject Revenue Complaint',
            //     'module' => 'revenue_complaints',
            //     'description' => 'Allows rejecting an unsupported or invalid taxpayer complaint.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'revenue_complaints.escalate',
            //     'label' => 'Escalate Revenue Complaint',
            //     'module' => 'revenue_complaints',
            //     'description' => 'Allows escalating a complaint to the appropriate authority.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'revenue_complaints.close',
            //     'label' => 'Close Revenue Complaint',
            //     'module' => 'revenue_complaints',
            //     'description' => 'Allows closing a resolved taxpayer complaint.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'revenue_complaints.view_history',
            //     'label' => 'View Complaint History',
            //     'module' => 'revenue_complaints',
            //     'description' => 'Allows viewing the complete complaint history and actions.',
            //     'is_system' => false,
            // ],

            /*
            |--------------------------------------------------------------------------
            | Payment Management
            |--------------------------------------------------------------------------
            */

            // [
            //     'name' => 'payment.view',
            //     'label' => 'View Payments',
            //     'module' => 'payment',
            //     'description' => 'Allows viewing payment records and payment history.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'payment.create',
            //     'label' => 'Create Payment',
            //     'module' => 'payment',
            //     'description' => 'Allows recording authorized taxpayer payments.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'payment.collect',
            //     'label' => 'Collect Payment',
            //     'module' => 'payment',
            //     'description' => 'Allows collecting authorized taxpayer payments.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'payment.verify',
            //     'label' => 'Verify Payment',
            //     'module' => 'payment',
            //     'description' => 'Allows verifying payment records.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'payment.approve',
            //     'label' => 'Approve Payment',
            //     'module' => 'payment',
            //     'description' => 'Allows approving eligible payment records.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'payment.reverse',
            //     'label' => 'Reverse Payment',
            //     'module' => 'payment',
            //     'description' => 'Allows reversing eligible payments according to authorized procedures.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'payment.view_history',
            //     'label' => 'View Payment History',
            //     'module' => 'payment',
            //     'description' => 'Allows viewing complete payment history and payment actions.',
            //     'is_system' => false,
            // ],

            /*
            |--------------------------------------------------------------------------
            | Receipt Management
            |--------------------------------------------------------------------------
            */

            // [
            //     'name' => 'receipt.view',
            //     'label' => 'View Receipts',
            //     'module' => 'receipt',
            //     'description' => 'Allows viewing payment receipts.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'receipt.create',
            //     'label' => 'Create Receipt',
            //     'module' => 'receipt',
            //     'description' => 'Allows generating payment receipts for eligible recorded payments.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'receipt.print',
            //     'label' => 'Print Receipt',
            //     'module' => 'receipt',
            //     'description' => 'Allows printing payment receipts.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'receipt.reprint',
            //     'label' => 'Reprint Receipt',
            //     'module' => 'receipt',
            //     'description' => 'Allows reprinting previously issued receipts.',
            //     'is_system' => false,
            // ],

            /*
            |--------------------------------------------------------------------------
            | Revenue Reports
            |--------------------------------------------------------------------------
            */

            // [
            //     'name' => 'revenue_reports.view',
            //     'label' => 'View Revenue Reports',
            //     'module' => 'revenue_reports',
            //     'description' => 'Allows viewing revenue reports.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'revenue_reports.generate',
            //     'label' => 'Generate Revenue Reports',
            //     'module' => 'revenue_reports',
            //     'description' => 'Allows generating revenue reports.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'revenue_reports.export',
            //     'label' => 'Export Revenue Reports',
            //     'module' => 'revenue_reports',
            //     'description' => 'Allows exporting revenue reports.',
            //     'is_system' => false,
            // ],

            /*
            |--------------------------------------------------------------------------
            | Collection Management
            |--------------------------------------------------------------------------
            */

            // [
            //     'name' => 'collection.view',
            //     'label' => 'View Collections',
            //     'module' => 'collection',
            //     'description' => 'Allows viewing revenue collection records and collection status.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'collection.create',
            //     'label' => 'Create Collection',
            //     'module' => 'collection',
            //     'description' => 'Allows creating authorized collection records.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'collection.verify',
            //     'label' => 'Verify Collection',
            //     'module' => 'collection',
            //     'description' => 'Allows verifying collection records.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'collection.complete',
            //     'label' => 'Complete Collection',
            //     'module' => 'collection',
            //     'description' => 'Allows completing authorized revenue collection workflows.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'collection.view_history',
            //     'label' => 'View Collection History',
            //     'module' => 'collection',
            //     'description' => 'Allows viewing collection history and collection actions.',
            //     'is_system' => false,
            // ],

            /*
            |--------------------------------------------------------------------------
            | Collection Reports
            |--------------------------------------------------------------------------
            */

            // [
            //     'name' => 'collection_report.view',
            //     'label' => 'View Collection Reports',
            //     'module' => 'collection_report',
            //     'description' => 'Allows viewing revenue collection reports.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'collection_report.generate',
            //     'label' => 'Generate Collection Reports',
            //     'module' => 'collection_report',
            //     'description' => 'Allows generating revenue collection reports.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'collection_report.export',
            //     'label' => 'Export Collection Reports',
            //     'module' => 'collection_report',
            //     'description' => 'Allows exporting revenue collection reports.',
            //     'is_system' => false,
            // ],

            /*
            |--------------------------------------------------------------------------
            | Plans
            |--------------------------------------------------------------------------
            */

            // [
            //     'name' => 'plans.view',
            //     'label' => 'View Plans',
            //     'module' => 'plans',
            //     'description' => 'Allows viewing plans.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'plans.create',
            //     'label' => 'Create Plan',
            //     'module' => 'plans',
            //     'description' => 'Allows creating plans.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'plans.update',
            //     'label' => 'Update Plan',
            //     'module' => 'plans',
            //     'description' => 'Allows updating eligible plans.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'plans.submit',
            //     'label' => 'Submit Plan',
            //     'module' => 'plans',
            //     'description' => 'Allows submitting plans for review.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'plans.approve',
            //     'label' => 'Approve Plan',
            //     'module' => 'plans',
            //     'description' => 'Allows approving plans.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'plans.reject',
            //     'label' => 'Reject Plan',
            //     'module' => 'plans',
            //     'description' => 'Allows rejecting plans.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'plans.view_history',
            //     'label' => 'View Plan History',
            //     'module' => 'plans',
            //     'description' => 'Allows viewing plan workflow and change history.',
            //     'is_system' => false,
            // ],

            /*
            |--------------------------------------------------------------------------
            | Reports
            |--------------------------------------------------------------------------
            */

            // [
            //     'name' => 'reports.view',
            //     'label' => 'View Reports',
            //     'module' => 'reports',
            //     'description' => 'Allows viewing reports.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'reports.create',
            //     'label' => 'Create Report',
            //     'module' => 'reports',
            //     'description' => 'Allows creating reports.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'reports.update',
            //     'label' => 'Update Report',
            //     'module' => 'reports',
            //     'description' => 'Allows updating eligible reports.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'reports.submit',
            //     'label' => 'Submit Report',
            //     'module' => 'reports',
            //     'description' => 'Allows submitting reports for review.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'reports.approve',
            //     'label' => 'Approve Report',
            //     'module' => 'reports',
            //     'description' => 'Allows approving reports.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'reports.reject',
            //     'label' => 'Reject Report',
            //     'module' => 'reports',
            //     'description' => 'Allows rejecting reports.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'reports.view_history',
            //     'label' => 'View Report History',
            //     'module' => 'reports',
            //     'description' => 'Allows viewing report workflow and change history.',
            //     'is_system' => false,
            // ],

            /*
            |--------------------------------------------------------------------------
            | Generic Report
            |--------------------------------------------------------------------------
            */

            // [
            //     'name' => 'report.view',
            //     'label' => 'View Report',
            //     'module' => 'report',
            //     'description' => 'Allows viewing authorized operational reports.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'report.generate',
            //     'label' => 'Generate Report',
            //     'module' => 'report',
            //     'description' => 'Allows generating authorized operational reports.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'report.export',
            //     'label' => 'Export Report',
            //     'module' => 'report',
            //     'description' => 'Allows exporting authorized operational reports.',
            //     'is_system' => false,
            // ],

            /*
            |--------------------------------------------------------------------------
            | Performance Reports
            |--------------------------------------------------------------------------
            */

            // [
            //     'name' => 'performance_report.view',
            //     'label' => 'View Performance Reports',
            //     'module' => 'performance_report',
            //     'description' => 'Allows viewing organizational and revenue performance reports.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'performance_report.generate',
            //     'label' => 'Generate Performance Reports',
            //     'module' => 'performance_report',
            //     'description' => 'Allows generating performance reports.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'performance_report.export',
            //     'label' => 'Export Performance Reports',
            //     'module' => 'performance_report',
            //     'description' => 'Allows exporting performance reports.',
            //     'is_system' => false,
            // ],

            /*
            |--------------------------------------------------------------------------
            | Decision Reports
            |--------------------------------------------------------------------------
            */

            // [
            //     'name' => 'decision_report.view',
            //     'label' => 'View Decision Reports',
            //     'module' => 'decision_report',
            //     'description' => 'Allows viewing assessment and revenue decision reports.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'decision_report.generate',
            //     'label' => 'Generate Decision Reports',
            //     'module' => 'decision_report',
            //     'description' => 'Allows generating assessment and revenue decision reports.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'decision_report.export',
            //     'label' => 'Export Decision Reports',
            //     'module' => 'decision_report',
            //     'description' => 'Allows exporting assessment and revenue decision reports.',
            //     'is_system' => false,
            // ],

            /*
            |--------------------------------------------------------------------------
            | Data Validation
            |--------------------------------------------------------------------------
            */

            // [
            //     'name' => 'data_validation.view',
            //     'label' => 'View Data Validation',
            //     'module' => 'data_validation',
            //     'description' => 'Allows viewing data validation results and validation status.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'data_validation.run',
            //     'label' => 'Run Data Validation',
            //     'module' => 'data_validation',
            //     'description' => 'Allows running authorized data validation checks.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'data_validation.resolve',
            //     'label' => 'Resolve Data Validation',
            //     'module' => 'data_validation',
            //     'description' => 'Allows resolving authorized data validation issues.',
            //     'is_system' => false,
            // ],

            /*
            |--------------------------------------------------------------------------
            | KPI Management
            |--------------------------------------------------------------------------
            */

            // [
            //     'name' => 'kpi.view',
            //     'label' => 'View KPI',
            //     'module' => 'kpi',
            //     'description' => 'Allows viewing KPIs and KPI results.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'kpi.manage',
            //     'label' => 'Manage KPI',
            //     'module' => 'kpi',
            //     'description' => 'Allows creating, updating, configuring, and managing KPIs.',
            //     'is_system' => false,
            // ],

            /*
            |--------------------------------------------------------------------------
            | Evidence Management
            |--------------------------------------------------------------------------
            */

            // [
            //     'name' => 'evidence.view',
            //     'label' => 'View Evidence',
            //     'module' => 'evidence',
            //     'description' => 'Allows viewing authorized evidence files and metadata.',
            //     'is_system' => false,
            // ],

            // [
            //     'name' => 'evidence.upload',
            //     'label' => 'Upload Evidence',
            //     'module' => 'evidence',
            //     'description' => 'Allows uploading evidence files to authorized records.',
            //     'is_system' => false,
            // ],

            /*
            |--------------------------------------------------------------------------
            | Audit
            |--------------------------------------------------------------------------
            */

            [
                'name' => 'audit.view',
                'label' => 'View Audit Logs',
                'module' => 'audit',
                'description' => 'Allows viewing audit logs and system activity history.',
                'is_system' => true,
            ],
        ];

        /*
        |--------------------------------------------------------------------------
        | Validate Catalog Before Database Changes
        |--------------------------------------------------------------------------
        */

        $this->validateCatalog(
            permissions: $permissions,
            guard: $guard
        );

        /*
        |--------------------------------------------------------------------------
        | Synchronize Catalog
        |--------------------------------------------------------------------------
        */

        DB::transaction(function () use ($permissions, $guard): void {
            foreach ($permissions as $permissionData) {
                Permission::updateOrCreate(
                    [
                        'name' => $permissionData['name'],
                        'guard_name' => $guard,
                    ],
                    [
                        'label' => $permissionData['label'],
                        'module' => $permissionData['module'],
                        'description' => $permissionData['description'],
                        'is_system' => $permissionData['is_system'],
                    ]
                );
            }
        });

        /*
        |--------------------------------------------------------------------------
        | Clear Spatie Permission Cache
        |--------------------------------------------------------------------------
        */

        app(PermissionRegistrar::class)
            ->forgetCachedPermissions();

        /*
        |--------------------------------------------------------------------------
        | Clear Application Permission Catalog Cache
        |--------------------------------------------------------------------------
        */

        Cache::forget('permission_catalog');

        /*
        |--------------------------------------------------------------------------
        | Report Result
        |--------------------------------------------------------------------------
        */

        $this->command?->info(
            sprintf(
                'Permission catalog synchronized successfully. %d permissions processed.',
                count($permissions)
            )
        );
    }

    /**
     * Validate the complete permission catalog.
     *
     * Validation is intentionally performed before any database changes.
     */
    private function validateCatalog(
        array $permissions,
        string $guard
    ): void {
        /*
        |--------------------------------------------------------------------------
        | Guard Validation
        |--------------------------------------------------------------------------
        */

        if ($guard !== 'api') {
            throw new RuntimeException(
                sprintf(
                    'Invalid permission guard [%s]. Expected [api].',
                    $guard
                )
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Catalog Must Not Be Empty
        |--------------------------------------------------------------------------
        */

        if ($permissions === []) {
            throw new RuntimeException(
                'Permission catalog cannot be empty.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Required Fields
        |--------------------------------------------------------------------------
        */

        $requiredFields = [
            'name',
            'label',
            'module',
            'description',
            'is_system',
        ];

        $permissionNames = [];

        /*
        |--------------------------------------------------------------------------
        | Validate Individual Permissions
        |--------------------------------------------------------------------------
        */

        foreach ($permissions as $index => $permission) {

            if (!is_array($permission)) {
                throw new RuntimeException(
                    sprintf(
                        'Permission definition at index %d must be an array.',
                        $index
                    )
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Required Fields
            |--------------------------------------------------------------------------
            */

            foreach ($requiredFields as $field) {
                if (!array_key_exists($field, $permission)) {
                    throw new RuntimeException(
                        sprintf(
                            'Permission definition at index %d is missing required field [%s].',
                            $index,
                            $field
                        )
                    );
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Permission Name
            |--------------------------------------------------------------------------
            */

            if (
                !is_string($permission['name']) ||
                trim($permission['name']) === ''
            ) {
                throw new RuntimeException(
                    sprintf(
                        'Permission definition at index %d must have a valid name.',
                        $index
                    )
                );
            }

            $permissionName = trim($permission['name']);

            if (
                !preg_match(
                    '/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/',
                    $permissionName
                )
            ) {
                throw new RuntimeException(
                    sprintf(
                        'Invalid permission name [%s]. Expected format module.action.',
                        $permissionName
                    )
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Label
            |--------------------------------------------------------------------------
            */

            if (
                !is_string($permission['label']) ||
                trim($permission['label']) === ''
            ) {
                throw new RuntimeException(
                    sprintf(
                        'Permission [%s] must have a valid label.',
                        $permissionName
                    )
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Module
            |--------------------------------------------------------------------------
            */

            if (
                !is_string($permission['module']) ||
                trim($permission['module']) === ''
            ) {
                throw new RuntimeException(
                    sprintf(
                        'Permission [%s] must have a valid module.',
                        $permissionName
                    )
                );
            }

            $module = trim($permission['module']);

            if (
                !preg_match(
                    '/^[a-z][a-z0-9_]*$/',
                    $module
                )
            ) {
                throw new RuntimeException(
                    sprintf(
                        'Permission [%s] has invalid module [%s].',
                        $permissionName,
                        $module
                    )
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Description
            |--------------------------------------------------------------------------
            */

            if (
                !is_string($permission['description']) ||
                trim($permission['description']) === ''
            ) {
                throw new RuntimeException(
                    sprintf(
                        'Permission [%s] must have a valid description.',
                        $permissionName
                    )
                );
            }

            /*
            |--------------------------------------------------------------------------
            | System Flag
            |--------------------------------------------------------------------------
            */

            if (!is_bool($permission['is_system'])) {
                throw new RuntimeException(
                    sprintf(
                        'Permission [%s] must define is_system as boolean.',
                        $permissionName
                    )
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Extract Module
            |--------------------------------------------------------------------------
            */

            [$permissionModule] = explode(
                '.',
                $permissionName,
                2
            );

            /*
            |--------------------------------------------------------------------------
            | Module Consistency
            |--------------------------------------------------------------------------
            */

            if ($permissionModule !== $module) {
                throw new RuntimeException(
                    sprintf(
                        'Permission [%s] has inconsistent module [%s]. Expected [%s].',
                        $permissionName,
                        $module,
                        $permissionModule
                    )
                );
            }

            $permissionNames[] = $permissionName;
        }

        /*
        |--------------------------------------------------------------------------
        | Duplicate Permission Names
        |--------------------------------------------------------------------------
        */

        $nameCounts = array_count_values($permissionNames);

        $duplicateNames = [];

        foreach ($nameCounts as $name => $count) {
            if ($count > 1) {
                $duplicateNames[] = $name;
            }
        }

        if ($duplicateNames !== []) {
            throw new RuntimeException(
                'Duplicate permission names detected: ' .
                implode(', ', $duplicateNames)
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Canonical Uniqueness Check
        |--------------------------------------------------------------------------
        */

        if (
            count($permissionNames) !==
            count(array_unique($permissionNames))
        ) {
            throw new RuntimeException(
                'Permission catalog contains duplicate canonical permission names.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Existing Database Guard Conflict
        |--------------------------------------------------------------------------
        |
        | Spatie identifies permissions by name + guard.
        |
        | If the same permission name already exists under another
        | guard, fail rather than silently creating an inconsistent
        | permission model.
        |
        */

        $existingWrongGuardPermissions = Permission::query()
            ->whereIn('name', $permissionNames)
            ->where('guard_name', '!=', $guard)
            ->get([
                'name',
                'guard_name',
            ]);

        if ($existingWrongGuardPermissions->isNotEmpty()) {
            $conflicts = $existingWrongGuardPermissions
                ->map(
                    fn (Permission $permission): string =>
                        sprintf(
                            '%s [%s]',
                            $permission->name,
                            $permission->guard_name
                        )
                )
                ->implode(', ');

            throw new RuntimeException(
                'Permission guard conflicts detected: ' . $conflicts
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Existing System Permission Protection
        |--------------------------------------------------------------------------
        |
        | Once a permission is marked as system-level, the seeder
        | must never silently downgrade it to non-system.
        |
        */

        $existingSystemPermissions = Permission::query()
            ->whereIn('name', $permissionNames)
            ->where('guard_name', $guard)
            ->where('is_system', true)
            ->get(['name']);

        $catalogByName = [];

        foreach ($permissions as $permission) {
            $catalogByName[$permission['name']] = $permission;
        }

        foreach ($existingSystemPermissions as $existingPermission) {
            if (
                isset($catalogByName[$existingPermission->name]) &&
                $catalogByName[$existingPermission->name]['is_system'] !== true
            ) {
                throw new RuntimeException(
                    sprintf(
                        'Protected system permission [%s] cannot be changed to non-system.',
                        $existingPermission->name
                    )
                );
            }
        }
    }
}