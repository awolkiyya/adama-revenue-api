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

            // Office Portal Access
            // Allows authorized users to access the municipal Office portal.
            [
                'name' => 'office.portal_access',
                'label' => 'Access Office Portal',
                'module' => 'office',
                'description' => 'Allows accessing the municipal Office portal.',
                'is_system' => true,
            ],

            // Agent Portal Access
            // Allows authorized agents to access the Agent portal for taxpayer assistance and payment services.
            [
                'name' => 'agent.portal_access',
                'label' => 'Access Agent Portal',
                'module' => 'agent',
                'description' => 'Allows accessing the Agent portal for authorized taxpayer assistance and payment services.',
                'is_system' => true,
            ],

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
            | Administrative Units
            |--------------------------------------------------------------------------
            */

            [
                'name' => 'administrative_units.view',
                'label' => 'View Administrative Units',
                'module' => 'administrative_units',
                'description' => 'Allows accessing the Administrative Units management interface.',
                'is_system' => true,
            ],

            [
                'name' => 'administrative_units.read',
                'label' => 'Read Administrative Units',
                'module' => 'administrative_units',
                'description' => 'Allows reading and retrieving administrative unit records and the administrative structure.',
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
                'description' => 'Allows accessing the Sector Management interface.',
                'is_system' => true,
            ],

            [
                'name' => 'sectors.read',
                'label' => 'Read Sectors',
                'module' => 'sectors',
                'description' => 'Allows reading and retrieving sector records.',
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
            | Citizen Management
            |--------------------------------------------------------------------------
            */

            [
                'name' => 'citizens.view',
                'label' => 'View Citizens',
                'module' => 'citizens',
                'description' => 'Allows accessing the citizen management interface.',
                'is_system' => false,
            ],

            [
                'name' => 'citizens.read',
                'label' => 'Read Citizens',
                'module' => 'citizens',
                'description' => 'Allows reading and retrieving citizen records.',
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
            | System Settings this is the general profile settings
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
            | Revenue Settings
            |--------------------------------------------------------------------------
            |
            | Global configuration for the Revenue Management module.
            |
            | Revenue settings control system-wide revenue behavior such as:
            |
            | - Calendar configuration
            | - Global penalty/interest switches
            | - Assessment behavior
            | - Invoice behavior
            | - Payment configuration
            | - Receipt configuration
            | - Currency and calculation precision
            |
            | Actual tariff, penalty, and interest rules are managed separately
            | through their respective modules.
            |
            */

            [
                'name' => 'revenue_settings.view',
                'label' => 'View Revenue Settings',
                'module' => 'revenue_settings',
                'description' => 'Allows viewing global Revenue Management configuration, including calendar, assessment, invoice, payment, receipt, and calculation settings.',
                'is_system' => true,
            ],

            [
                'name' => 'revenue_settings.update',
                'label' => 'Update Revenue Settings',
                'module' => 'revenue_settings',
                'description' => 'Allows updating authorized global Revenue Management configuration and operational settings.',
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
                'name' => 'measurement_units.read',
                'label' => 'Read Measurement Units',
                'module' => 'measurement_units',
                'description' => 'Allows reading measurement unit details and retrieving measurement unit records.',
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
                'name' => 'base_fields.read',
                'label' => 'Read Base Fields',
                'module' => 'base_fields',
                'description' => 'Allows reading base field details and retrieving base field records.',
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
                'name' => 'revenue_categorys.read',
                'label' => 'Read Revenue Category',
                'module' => 'revenue_categorys',
                'description' => 'Allows viewing the details of an individual revenue category.',
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
                'description' => 'Allows accessing the Revenue Services management interface.',
                'is_system' => false,
            ],

            [
                'name' => 'revenue_services.read',
                'label' => 'Read Revenue Services',
                'module' => 'revenue_services',
                'description' => 'Allows reading and retrieving revenue service records and configurations.',
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

            [
                'name' => 'revenue_services.manage',
                'label' => 'Manage Revenue Service Access',
                'module' => 'revenue_services',
                'description' => 'Allows managing access permissions for revenue services.',
                'is_system' => false,
            ],


            /*
            |--------------------------------------------------------------------------
            | Service Access Rule Configuration
            |--------------------------------------------------------------------------
            */

            [
                'name' => 'service_access_rules.view',
                'label' => 'View Service Access Rules',
                'module' => 'service_access_rules',
                'description' => 'Allows viewing revenue service access configuration, including which sectors are allowed or not allowed to use each revenue service.',
                'is_system' => false,
            ],

            [
                'name' => 'service_access_rules.update',
                'label' => 'Update Service Access Rules',
                'module' => 'service_access_rules',
                'description' => 'Allows configuring and updating which sectors are allowed or not allowed to use a revenue service.',
                'is_system' => false,
            ],

            [
                'name' => 'service_access_rules.activate',
                'label' => 'Activate Service Access Rule',
                'module' => 'service_access_rules',
                'description' => 'Allows activating access for a sector so the sector is allowed to use the configured revenue service.',
                'is_system' => false,
            ],

            [
                'name' => 'service_access_rules.deactivate',
                'label' => 'Deactivate Service Access Rule',
                'module' => 'service_access_rules',
                'description' => 'Allows deactivating access for a sector so the sector is not allowed to use the configured revenue service.',
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

            [
                'name' => 'tariff_version.duplicate',
                'label' => 'Duplicate Tariff Version',
                'module' => 'tariff_version',
                'description' => 'Allows duplicating an existing tariff version into a new draft for modification.',
                'is_system' => false,
            ],

            [
                'name' => 'tariff_version.delete',
                'label' => 'Delete Tariff Version',
                'module' => 'tariff_version',
                'description' => 'Allows deleting eligible draft tariff versions that have no dependent records.',
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


            // tariff formula management 
            [
                'name' => 'tariff_formula.view',
                'label' => 'View Tariff Formulas',
                'module' => 'tariff',
                'description' => 'Allows viewing formulas used in tariff rule calculations.',
                'is_system' => false,
            ],
            
            [
                'name' => 'tariff_formula.create',
                'label' => 'Create Tariff Formula',
                'module' => 'tariff',
                'description' => 'Allows creating formulas for tariff rule calculations.',
                'is_system' => false,
            ],
            
            [
                'name' => 'tariff_formula.update',
                'label' => 'Update Tariff Formula',
                'module' => 'tariff',
                'description' => 'Allows updating formulas used in tariff rule calculations.',
                'is_system' => false,
            ],
            
            [
                'name' => 'tariff_formula.delete',
                'label' => 'Delete Tariff Formula',
                'module' => 'tariff',
                'description' => 'Allows deleting formulas from tariff rule calculations.',
                'is_system' => false,
            ],
            
            [
                'name' => 'tariff_formula.validate',
                'label' => 'Validate Tariff Formula',
                'module' => 'tariff',
                'description' => 'Allows validating tariff formulas before they are used for revenue calculation.',
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
                'description' => 'Allows viewing configured interest rules, including interest rates, calculation basis, and effective periods.',
                'is_system' => false,
            ],

            [
                'name' => 'interest_rules.create',
                'label' => 'Create Interest Rule',
                'module' => 'interest_rules',
                'description' => 'Allows creating new interest rules with an annual interest rate, calculation basis, and effective period.',
                'is_system' => false,
            ],

            [
                'name' => 'interest_rules.update',
                'label' => 'Update Interest Rule',
                'module' => 'interest_rules',
                'description' => 'Allows updating eligible interest rule configurations, including rates, calculation basis, effective periods, legal references, and descriptions.',
                'is_system' => false,
            ],

            [
                'name' => 'interest_rules.activate',
                'label' => 'Activate Interest Rule',
                'module' => 'interest_rules',
                'description' => 'Allows activating an interest rule configuration when its effective period does not conflict with another active rule.',
                'is_system' => false,
            ],

            [
                'name' => 'interest_rules.deactivate',
                'label' => 'Deactivate Interest Rule',
                'module' => 'interest_rules',
                'description' => 'Allows deactivating an active interest rule configuration without deleting its historical record.',
                'is_system' => false,
            ],

            [
                'name' => 'interest_rules.view_history',
                'label' => 'View Interest Rule History',
                'module' => 'interest_rules',
                'description' => 'Allows viewing the historical configuration and changes of interest rules.',
                'is_system' => false,
            ],

            /*
            |--------------------------------------------------------------------------
            | Revenue Code Payment Schedule Rules
            |--------------------------------------------------------------------------
            */

            [
                'name' => 'payment_schedule_rules.view',
                'label' => 'View Payment Schedule Rules',
                'module' => 'payment_schedule_rules',
                'description' => 'Allows viewing payment schedule configuration for revenue codes.',
                'is_system' => false,
            ],

            [
                'name' => 'payment_schedule_rules.create',
                'label' => 'Create Payment Schedule Rule',
                'module' => 'payment_schedule_rules',
                'description' => 'Allows configuring payment schedule rules for revenue codes.',
                'is_system' => false,
            ],

            [
                'name' => 'payment_schedule_rules.update',
                'label' => 'Update Payment Schedule Rule',
                'module' => 'payment_schedule_rules',
                'description' => 'Allows updating payment schedule configuration for revenue codes, including schedule availability and first-installment percentage.',
                'is_system' => false,
            ],

            [
                'name' => 'payment_schedule_rules.activate',
                'label' => 'Activate Payment Schedule Rule',
                'module' => 'payment_schedule_rules',
                'description' => 'Allows enabling payment schedule processing for a revenue code.',
                'is_system' => false,
            ],

            [
                'name' => 'payment_schedule_rules.deactivate',
                'label' => 'Deactivate Payment Schedule Rule',
                'module' => 'payment_schedule_rules',
                'description' => 'Allows disabling payment schedule processing for a revenue code without deleting its configuration.',
                'is_system' => false,
            ],

            [
                'name' => 'payment_schedule_rules.view_history',
                'label' => 'View Payment Schedule Rule History',
                'module' => 'payment_schedule_rules',
                'description' => 'Allows viewing historical changes to payment schedule rules.',
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
            | Assessment
            |--------------------------------------------------------------------------
            */

            [
                'name' => 'assessment.read',
                'label' => 'Read Assessments',
                'module' => 'assessment',
                'description' => 'Allows reading and viewing revenue assessment records.',
                'is_system' => false,
            ],

            [
                'name' => 'assessment.view',
                'label' => 'View Assessments',
                'module' => 'assessment',
                'description' => 'Allows viewing revenue assessments.',
                'is_system' => false,
            ],

            [
                'name' => 'assessment.create',
                'label' => 'Create Assessment',
                'module' => 'assessment',
                'description' => 'Allows creating new revenue assessments.',
                'is_system' => false,
            ],

            [
                'name' => 'assessment.register_existing',
                'label' => 'Register Existing Agreement',
                'module' => 'assessment',
                'description' => 'Allows registering an existing revenue agreement and continuing its outstanding financial obligation from the current balance.',
                'is_system' => false,
            ],

            [
                'name' => 'assessment.update',
                'label' => 'Update Assessment',
                'module' => 'assessment',
                'description' => 'Allows updating eligible revenue assessments.',
                'is_system' => false,
            ],

            [
                'name' => 'assessment.delete',
                'label' => 'Delete Assessment',
                'module' => 'assessment',
                'description' => 'Allows deleting eligible draft revenue assessments.',
                'is_system' => false,
            ],

            [
                'name' => 'assessment.submit',
                'label' => 'Submit Assessment',
                'module' => 'assessment',
                'description' => 'Allows submitting revenue assessments for decision.',
                'is_system' => false,
            ],

            [
                'name' => 'assessment.verify',
                'label' => 'Verify Assessment',
                'module' => 'assessment',
                'description' => 'Allows verifying revenue assessment details.',
                'is_system' => false,
            ],

            [
                'name' => 'assessment.approve',
                'label' => 'Approve Assessment',
                'module' => 'assessment',
                'description' => 'Allows approving submitted revenue assessments.',
                'is_system' => false,
            ],

            [
                'name' => 'assessment.return',
                'label' => 'Return Assessment',
                'module' => 'assessment',
                'description' => 'Allows returning revenue assessments for correction.',
                'is_system' => false,
            ],

            [
                'name' => 'assessment.reject',
                'label' => 'Reject Assessment',
                'module' => 'assessment',
                'description' => 'Allows rejecting submitted revenue assessments.',
                'is_system' => false,
            ],

            [
                'name' => 'assessment.cancel',
                'label' => 'Cancel Assessment',
                'module' => 'assessment',
                'description' => 'Allows cancelling revenue assessments according to the assessment lifecycle rules.',
                'is_system' => false,
            ],

            [
                'name' => 'assessment.view_history',
                'label' => 'View Assessment History',
                'module' => 'assessment',
                'description' => 'Allows viewing the history of revenue assessments and their decisions.',
                'is_system' => false,
            ],



           /*
            |--------------------------------------------------------------------------
            | Invoice Management
            |--------------------------------------------------------------------------
            */

            [
                'name' => 'invoices.view',
                'label' => 'View Invoices',
                'module' => 'invoices',
                'description' => 'Allows accessing the invoice management module and invoice-related screens.',
                'is_system' => false,
            ],

            [
                'name' => 'invoices.read',
                'label' => 'Read Invoices',
                'module' => 'invoices',
                'description' => 'Allows retrieving and viewing invoice data, including invoice details, amounts, status, taxpayer information, and payment balance.',
                'is_system' => false,
            ],

            [
                'name' => 'invoices.create',
                'label' => 'Create Invoice',
                'module' => 'invoices',
                'description' => 'Allows creating invoices from eligible assessments or authorized direct collection processes.',
                'is_system' => false,
            ],

            [
                'name' => 'invoices.issue',
                'label' => 'Issue Invoice',
                'module' => 'invoices',
                'description' => 'Allows officially issuing a draft invoice and making it financially effective for payment.',
                'is_system' => false,
            ],

            [
                'name' => 'invoices.cancel',
                'label' => 'Cancel Invoice',
                'module' => 'invoices',
                'description' => 'Allows cancelling eligible draft invoices before they become financially effective.',
                'is_system' => false,
            ],

            [
                'name' => 'invoices.void',
                'label' => 'Void Invoice',
                'module' => 'invoices',
                'description' => 'Allows voiding an already issued invoice when it must be invalidated according to financial control rules.',
                'is_system' => false,
            ],

            [
                'name' => 'invoices.view_history',
                'label' => 'View Invoice History',
                'module' => 'invoices',
                'description' => 'Allows viewing the history of invoice creation, issuance, cancellation, voiding, and other recorded changes.',
                'is_system' => false,
            ],



            /*
            |--------------------------------------------------------------------------
            | Cash Reconciliation
            |--------------------------------------------------------------------------
            |
            | Cash reconciliation allows revenue collectors to record and submit
            | their cash closing, while authorized finance officers review the
            | submitted reconciliation.
            |
            | Expected cash and the difference are calculated by the backend.
            | Collectors cannot review their own reconciliations.
            |
            */

            [
                'name' => 'cash-reconciliations.view',
                'label' => 'View Cash Reconciliations',
                'module' => 'cash-reconciliations',
                'description' => 'Allows accessing the cash reconciliation management interface.',
                'is_system' => false,
            ],

            [
                'name' => 'cash-reconciliations.view-all',
                'label' => 'View All Cash Reconciliations',
                'module' => 'cash-reconciliations',
                'description' => 'Allows viewing cash reconciliations belonging to all authorized revenue collectors instead of only the authenticated collector.',
                'is_system' => false,
            ],

            [
                'name' => 'cash-reconciliations.create',
                'label' => 'Create Cash Reconciliation',
                'module' => 'cash-reconciliations',
                'description' => 'Allows creating cash reconciliation drafts for the authenticated revenue collector.',
                'is_system' => false,
            ],

            [
                'name' => 'cash-reconciliations.submit',
                'label' => 'Submit Cash Reconciliation',
                'module' => 'cash-reconciliations',
                'description' => 'Allows submitting a cash closing for review after the backend calculates expected cash and the difference between expected and physically counted amounts.',
                'is_system' => false,
            ],

            [
                'name' => 'cash-reconciliations.review',
                'label' => 'Review Cash Reconciliation',
                'module' => 'cash-reconciliations',
                'description' => 'Allows authorized finance officers to review submitted cash reconciliations, record review notes, reconcile balanced closings, flag discrepancies, or return closings for correction.',
                'is_system' => false,
            ],

            /*
            |--------------------------------------------------------------------------
            | Penalty Discount Management
            |--------------------------------------------------------------------------
            */

            [
                'name' => 'penalty_discount_requests.view',
                'label' => 'View Penalty Discount Requests',
                'module' => 'penalty_discount_requests',
                'description' => 'Allows accessing the penalty discount request management module and related screens.',
                'is_system' => false,
            ],

            [
                'name' => 'penalty_discount_requests.read',
                'label' => 'Read Penalty Discount Requests',
                'module' => 'penalty_discount_requests',
                'description' => 'Allows retrieving and viewing penalty discount request details, requested amounts, reasons, decisions, approved amounts, and application status.',
                'is_system' => false,
            ],

            [
                'name' => 'penalty_discount_requests.create',
                'label' => 'Create Penalty Discount Request',
                'module' => 'penalty_discount_requests',
                'description' => 'Allows creating a penalty discount request for an eligible invoice.',
                'is_system' => false,
            ],

            [
                'name' => 'penalty_discount_requests.submit',
                'label' => 'Submit Penalty Discount Request',
                'module' => 'penalty_discount_requests',
                'description' => 'Allows submitting a penalty discount request for administrative review and decision.',
                'is_system' => false,
            ],

            [
                'name' => 'penalty_discount_requests.decide',
                'label' => 'Decide Penalty Discount Request',
                'module' => 'penalty_discount_requests',
                'description' => 'Allows an authorized Revenue Tax Administrative Officer to approve or reject a penalty discount request and specify the approved discount amount.',
                'is_system' => false,
            ],

            [
                'name' => 'penalty_discount_requests.cancel',
                'label' => 'Cancel Penalty Discount Request',
                'module' => 'penalty_discount_requests',
                'description' => 'Allows cancelling an eligible penalty discount request before a final administrative decision is made.',
                'is_system' => false,
            ],

            [
                'name' => 'penalty_discount_requests.view_history',
                'label' => 'View Penalty Discount Request History',
                'module' => 'penalty_discount_requests',
                'description' => 'Allows viewing the history of penalty discount requests, submissions, administrative decisions, and invoice application.',
                'is_system' => false,
            ],

            /*
            |--------------------------------------------------------------------------
            | Payments Management
            |--------------------------------------------------------------------------
            */

            [
                'name' => 'payments.view',
                'label' => 'View Payments',
                'module' => 'payments',
                'description' => 'Allows accessing the payment collection and payment management module and related screens.',
                'is_system' => false,
            ],

            [
                'name' => 'payments.read',
                'label' => 'Read Payments',
                'module' => 'payments',
                'description' => 'Allows retrieving and viewing payment records, payment amounts, payment methods, transaction references, and payment status.',
                'is_system' => false,
            ],

            [
                'name' => 'payments.collect',
                'label' => 'Collect Payment',
                'module' => 'payments',
                'description' => 'Allows collecting payments against eligible invoices using authorized payment methods such as cash or bank transfer.',
                'is_system' => false,
            ],

            [
                'name' => 'payments.cancel',
                'label' => 'Cancel Payment',
                'module' => 'payments',
                'description' => 'Allows cancelling an eligible payment according to payment lifecycle and financial control rules.',
                'is_system' => false,
            ],

            [
                'name' => 'payments.reverse',
                'label' => 'Reverse Payment',
                'module' => 'payments',
                'description' => 'Allows reversing a completed payment according to authorized financial reversal procedures.',
                'is_system' => false,
            ],

            [
                'name' => 'payments.view_history',
                'label' => 'View Payment History',
                'module' => 'payments',
                'description' => 'Allows viewing the history of payment collection, cancellation, reversal, and other recorded payment changes.',
                'is_system' => false,
            ],

            [
                'name' => 'audit.view',
                'label' => 'View Audit Logs',
                'module' => 'audit',
                'description' => 'Allows viewing audit logs and system activity history.',
                'is_system' => true,
            ],


            /*
            |--------------------------------------------------------------------------
            | Bank Accounts
            |--------------------------------------------------------------------------
            */

            [
                'name' => 'bank_accounts.view',
                'label' => 'View Bank Accounts',
                'module' => 'bank_accounts',
                'description' => 'Allows viewing bank accounts configured for municipal revenue collection.',
                'is_system' => false,
            ],
            [
                'name' => 'bank_accounts.create',
                'label' => 'Create Bank Account',
                'module' => 'bank_accounts',
                'description' => 'Allows registering a bank account for authorized revenue collection.',
                'is_system' => false,
            ],
            [
                'name' => 'bank_accounts.update',
                'label' => 'Update Bank Account',
                'module' => 'bank_accounts',
                'description' => 'Allows updating eligible bank account configuration.',
                'is_system' => false,
            ],
            [
                'name' => 'bank_accounts.activate',
                'label' => 'Activate Bank Account',
                'module' => 'bank_accounts',
                'description' => 'Allows activating an eligible bank account.',
                'is_system' => false,
            ],
            [
                'name' => 'bank_accounts.deactivate',
                'label' => 'Deactivate Bank Account',
                'module' => 'bank_accounts',
                'description' => 'Allows deactivating an eligible bank account.',
                'is_system' => false,
            ],
            [
                'name' => 'bank_accounts.view_history',
                'label' => 'View Bank Account History',
                'module' => 'bank_accounts',
                'description' => 'Allows viewing recorded bank account configuration changes.',
                'is_system' => false,
            ],

            /*
            |--------------------------------------------------------------------------
            | Payment Providers
            |--------------------------------------------------------------------------
            */

            [
                'name' => 'payment_providers.view',
                'label' => 'View Payment Providers',
                'module' => 'payment_providers',
                'description' => 'Allows viewing configured payment providers and their status.',
                'is_system' => false,
            ],
            [
                'name' => 'payment_providers.create',
                'label' => 'Create Payment Provider',
                'module' => 'payment_providers',
                'description' => 'Allows configuring a supported payment provider.',
                'is_system' => false,
            ],
            [
                'name' => 'payment_providers.update',
                'label' => 'Update Payment Provider',
                'module' => 'payment_providers',
                'description' => 'Allows updating eligible payment provider configuration.',
                'is_system' => false,
            ],
            [
                'name' => 'payment_providers.activate',
                'label' => 'Activate Payment Provider',
                'module' => 'payment_providers',
                'description' => 'Allows activating a configured payment provider.',
                'is_system' => false,
            ],
            [
                'name' => 'payment_providers.deactivate',
                'label' => 'Deactivate Payment Provider',
                'module' => 'payment_providers',
                'description' => 'Allows deactivating a configured payment provider.',
                'is_system' => false,
            ],
            [
                'name' => 'payment_providers.view_history',
                'label' => 'View Payment Provider History',
                'module' => 'payment_providers',
                'description' => 'Allows viewing recorded payment provider configuration changes.',
                'is_system' => false,
            ],

            /*
            |--------------------------------------------------------------------------
            | Lease Amendments
            |--------------------------------------------------------------------------
            */

            [
                'name' => 'lease_amendments.view',
                'label' => 'View Lease Amendments',
                'module' => 'lease_amendments',
                'description' => 'Allows viewing lease amendment requests and their status.',
                'is_system' => false,
            ],
            [
                'name' => 'lease_amendments.create',
                'label' => 'Create Lease Amendment',
                'module' => 'lease_amendments',
                'description' => 'Allows creating a lease amendment request.',
                'is_system' => false,
            ],
            [
                'name' => 'lease_amendments.update',
                'label' => 'Update Lease Amendment',
                'module' => 'lease_amendments',
                'description' => 'Allows updating eligible lease amendment drafts.',
                'is_system' => false,
            ],
            [
                'name' => 'lease_amendments.submit',
                'label' => 'Submit Lease Amendment',
                'module' => 'lease_amendments',
                'description' => 'Allows submitting a lease amendment for approval.',
                'is_system' => false,
            ],
            [
                'name' => 'lease_amendments.approve',
                'label' => 'Approve Lease Amendment',
                'module' => 'lease_amendments',
                'description' => 'Allows approving a lease amendment request.',
                'is_system' => false,
            ],
            [
                'name' => 'lease_amendments.reject',
                'label' => 'Reject Lease Amendment',
                'module' => 'lease_amendments',
                'description' => 'Allows rejecting a lease amendment request.',
                'is_system' => false,
            ],
            [
                'name' => 'lease_amendments.apply',
                'label' => 'Apply Lease Amendment',
                'module' => 'lease_amendments',
                'description' => 'Allows applying an approved lease amendment according to the amendment workflow.',
                'is_system' => false,
            ],
            [
                'name' => 'lease_amendments.cancel',
                'label' => 'Cancel Lease Amendment',
                'module' => 'lease_amendments',
                'description' => 'Allows cancelling an eligible lease amendment.',
                'is_system' => false,
            ],
            [
                'name' => 'lease_amendments.view_history',
                'label' => 'View Lease Amendment History',
                'module' => 'lease_amendments',
                'description' => 'Allows viewing the history of lease amendment requests and decisions.',
                'is_system' => false,
            ],

            /*
            |--------------------------------------------------------------------------
            | Field Collection
            |--------------------------------------------------------------------------
            */

            [
                'name' => 'field_collection.view',
                'label' => 'View Field Collections',
                'module' => 'field_collection',
                'description' => 'Allows viewing authorized field collection records.',
                'is_system' => false,
            ],
            [
                'name' => 'field_collection.create',
                'label' => 'Create Field Collection',
                'module' => 'field_collection',
                'description' => 'Allows recording an authorized field collection transaction.',
                'is_system' => false,
            ],
            [
                'name' => 'field_collection.submit',
                'label' => 'Submit Field Collection',
                'module' => 'field_collection',
                'description' => 'Allows submitting field collection records according to the collection workflow.',
                'is_system' => false,
            ],
            [
                'name' => 'field_collection.view_history',
                'label' => 'View Field Collection History',
                'module' => 'field_collection',
                'description' => 'Allows viewing field collection transaction history.',
                'is_system' => false,
            ],

            /*
            |--------------------------------------------------------------------------
            | Penalty Operations
            |--------------------------------------------------------------------------
            */

            [
                'name' => 'penalty.view',
                'label' => 'View Penalties',
                'module' => 'penalty',
                'description' => 'Allows viewing penalties applied to eligible revenue obligations.',
                'is_system' => false,
            ],
            [
                'name' => 'penalty.calculate',
                'label' => 'Calculate Penalty',
                'module' => 'penalty',
                'description' => 'Allows calculating penalties according to configured rules.',
                'is_system' => false,
            ],
            [
                'name' => 'penalty.apply',
                'label' => 'Apply Penalty',
                'module' => 'penalty',
                'description' => 'Allows applying an authorized penalty to an eligible revenue obligation.',
                'is_system' => false,
            ],
            [
                'name' => 'penalty.adjust',
                'label' => 'Adjust Penalty',
                'module' => 'penalty',
                'description' => 'Allows making authorized adjustments to penalties.',
                'is_system' => false,
            ],
            [
                'name' => 'penalty.waive',
                'label' => 'Waive Penalty',
                'module' => 'penalty',
                'description' => 'Allows waiving a penalty when authorized by the applicable workflow.',
                'is_system' => false,
            ],
            [
                'name' => 'penalty.view_history',
                'label' => 'View Penalty History',
                'module' => 'penalty',
                'description' => 'Allows viewing penalty calculation, application, adjustment, and waiver history.',
                'is_system' => false,
            ],

            /*
            |--------------------------------------------------------------------------
            | Revenue Adjustments
            |--------------------------------------------------------------------------
            */

            [
                'name' => 'revenue_adjustments.view',
                'label' => 'View Revenue Adjustments',
                'module' => 'revenue_adjustments',
                'description' => 'Allows viewing revenue adjustment requests and records.',
                'is_system' => false,
            ],
            [
                'name' => 'revenue_adjustments.create',
                'label' => 'Create Revenue Adjustment',
                'module' => 'revenue_adjustments',
                'description' => 'Allows creating a revenue adjustment request.',
                'is_system' => false,
            ],
            [
                'name' => 'revenue_adjustments.review',
                'label' => 'Review Revenue Adjustment',
                'module' => 'revenue_adjustments',
                'description' => 'Allows reviewing a revenue adjustment request.',
                'is_system' => false,
            ],
            [
                'name' => 'revenue_adjustments.approve',
                'label' => 'Approve Revenue Adjustment',
                'module' => 'revenue_adjustments',
                'description' => 'Allows approving a revenue adjustment request.',
                'is_system' => false,
            ],
            [
                'name' => 'revenue_adjustments.reject',
                'label' => 'Reject Revenue Adjustment',
                'module' => 'revenue_adjustments',
                'description' => 'Allows rejecting a revenue adjustment request.',
                'is_system' => false,
            ],
            [
                'name' => 'revenue_adjustments.cancel',
                'label' => 'Cancel Revenue Adjustment',
                'module' => 'revenue_adjustments',
                'description' => 'Allows cancelling an eligible revenue adjustment request.',
                'is_system' => false,
            ],
            [
                'name' => 'revenue_adjustments.view_history',
                'label' => 'View Revenue Adjustment History',
                'module' => 'revenue_adjustments',
                'description' => 'Allows viewing revenue adjustment history.',
                'is_system' => false,
            ],

            /*
            |--------------------------------------------------------------------------
            | Revenue Complaints
            |--------------------------------------------------------------------------
            */

            [
                'name' => 'revenue_complaints.view',
                'label' => 'View Revenue Complaints',
                'module' => 'revenue_complaints',
                'description' => 'Allows viewing revenue complaints and their workflow status.',
                'is_system' => false,
            ],
            [
                'name' => 'revenue_complaints.create',
                'label' => 'Create Revenue Complaint',
                'module' => 'revenue_complaints',
                'description' => 'Allows creating a revenue complaint.',
                'is_system' => false,
            ],
            [
                'name' => 'revenue_complaints.update',
                'label' => 'Update Revenue Complaint',
                'module' => 'revenue_complaints',
                'description' => 'Allows updating an eligible revenue complaint.',
                'is_system' => false,
            ],
            [
                'name' => 'revenue_complaints.review',
                'label' => 'Review Revenue Complaint',
                'module' => 'revenue_complaints',
                'description' => 'Allows reviewing a revenue complaint.',
                'is_system' => false,
            ],
            [
                'name' => 'revenue_complaints.request_information',
                'label' => 'Request Complaint Information',
                'module' => 'revenue_complaints',
                'description' => 'Allows requesting additional information for a revenue complaint.',
                'is_system' => false,
            ],
            [
                'name' => 'revenue_complaints.recommend',
                'label' => 'Recommend Complaint Decision',
                'module' => 'revenue_complaints',
                'description' => 'Allows submitting a recommendation for a revenue complaint decision.',
                'is_system' => false,
            ],
            [
                'name' => 'revenue_complaints.reject',
                'label' => 'Reject Revenue Complaint',
                'module' => 'revenue_complaints',
                'description' => 'Allows rejecting a revenue complaint.',
                'is_system' => false,
            ],
            [
                'name' => 'revenue_complaints.escalate',
                'label' => 'Escalate Revenue Complaint',
                'module' => 'revenue_complaints',
                'description' => 'Allows escalating a revenue complaint for further review.',
                'is_system' => false,
            ],
            [
                'name' => 'revenue_complaints.close',
                'label' => 'Close Revenue Complaint',
                'module' => 'revenue_complaints',
                'description' => 'Allows closing a revenue complaint.',
                'is_system' => false,
            ],
            [
                'name' => 'revenue_complaints.view_history',
                'label' => 'View Revenue Complaint History',
                'module' => 'revenue_complaints',
                'description' => 'Allows viewing revenue complaint history.',
                'is_system' => false,
            ],

            /*
            |--------------------------------------------------------------------------
            | Reports
            |--------------------------------------------------------------------------
            */

            [
                'name' => 'reports.view',
                'label' => 'View Reports',
                'module' => 'reports',
                'description' => 'Allows accessing authorized system reports.',
                'is_system' => false,
            ],

            /*
            |--------------------------------------------------------------------------
            | Mobile App Releases
            |--------------------------------------------------------------------------
            */

            [
                'name' => 'mobile_app_releases.view',
                'label' => 'View Mobile App Releases',
                'module' => 'mobile_app_releases',
                'description' => 'Allows viewing mobile application release records and update status.',
                'is_system' => false,
            ],
            [
                'name' => 'mobile_app_releases.create',
                'label' => 'Create Mobile App Release',
                'module' => 'mobile_app_releases',
                'description' => 'Allows creating a mobile application release record.',
                'is_system' => false,
            ],
            [
                'name' => 'mobile_app_releases.update',
                'label' => 'Update Mobile App Release',
                'module' => 'mobile_app_releases',
                'description' => 'Allows updating eligible mobile application release records.',
                'is_system' => false,
            ],
            [
                'name' => 'mobile_app_releases.publish',
                'label' => 'Publish Mobile App Release',
                'module' => 'mobile_app_releases',
                'description' => 'Allows publishing a mobile application release.',
                'is_system' => false,
            ],
            [
                'name' => 'mobile_app_releases.deactivate',
                'label' => 'Deactivate Mobile App Release',
                'module' => 'mobile_app_releases',
                'description' => 'Allows deactivating an eligible mobile application release.',
                'is_system' => false,
            ],


            /*
            |--------------------------------------------------------------------------
            | Cash Reconciliation
            |--------------------------------------------------------------------------
            |
            | Cash reconciliation allows revenue collectors to record and submit
            | their cash closing, while authorized finance officers review the
            | submitted reconciliation.
            |
            | Expected cash and the difference are calculated by the backend.
            | Collectors cannot review their own reconciliations.
            |
            */

            [
                'name' => 'cash_reconciliations.view',
                'label' => 'View Cash Reconciliations',
                'module' => 'cash_reconciliations',
                'description' => 'Allows accessing the cash reconciliation management interface.',
                'is_system' => false,
            ],

            [
                'name' => 'cash_reconciliations.view_all',
                'label' => 'View All Cash Reconciliations',
                'module' => 'cash_reconciliations',
                'description' => 'Allows viewing cash reconciliations belonging to all authorized revenue collectors instead of only the authenticated collector.',
                'is_system' => false,
            ],

            [
                'name' => 'cash_reconciliations.create',
                'label' => 'Create Cash Reconciliation',
                'module' => 'cash_reconciliations',
                'description' => 'Allows creating cash reconciliation drafts for the authenticated revenue collector.',
                'is_system' => false,
            ],

            [
                'name' => 'cash_reconciliations.update',
                'label' => 'Update Cash Reconciliation',
                'module' => 'cash_reconciliations',
                'description' => 'Allows updating eligible cash reconciliation drafts before submission.',
                'is_system' => false,
            ],

            [
                'name' => 'cash_reconciliations.submit',
                'label' => 'Submit Cash Reconciliation',
                'module' => 'cash_reconciliations',
                'description' => 'Allows submitting a cash closing for review after the backend calculates expected cash and the difference between expected and physically counted amounts.',
                'is_system' => false,
            ],

            [
                'name' => 'cash_reconciliations.review',
                'label' => 'Review Cash Reconciliation',
                'module' => 'cash_reconciliations',
                'description' => 'Allows authorized finance officers to review submitted cash reconciliations, record review notes, reconcile balanced closings, flag discrepancies, or return closings for correction.',
                'is_system' => false,
            ],

            [
                'name' => 'cash_reconciliations.cancel',
                'label' => 'Cancel Cash Reconciliation',
                'module' => 'cash_reconciliations',
                'description' => 'Allows cancelling eligible cash reconciliation drafts according to the reconciliation workflow.',
                'is_system' => false,
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






