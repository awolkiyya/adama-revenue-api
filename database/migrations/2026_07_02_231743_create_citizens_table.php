<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('citizens', function (Blueprint $table) {

            /*
            |--------------------------------------------------------------------------
            | UUID Primary Key
            |--------------------------------------------------------------------------
            */

            $table->uuid('id')->primary();


            /*
            |--------------------------------------------------------------------------
            | Citizen Reference Number
            |--------------------------------------------------------------------------
            |
            | Example:
            | CIT-2026-000001
            |
            */

            $table->string('citizen_uid')
                ->unique()
                ->index();


            /*
            |--------------------------------------------------------------------------
            | Identity Information
            |--------------------------------------------------------------------------
            */

            $table->string('full_name')
                ->index();

            $table->string('national_id')
                ->unique()
                ->index();

            $table->string('phone')
                ->unique()
                ->index();

            $table->string('email')
                ->nullable()
                ->index();


            /*
            |--------------------------------------------------------------------------
            | Personal Information
            |--------------------------------------------------------------------------
            */

            $table->enum('gender', [
                'MALE',
                'FEMALE',
                'OTHER',
            ]);

            $table->date('date_of_birth')
                ->nullable();


            /*
            |--------------------------------------------------------------------------
            | Address Information
            |--------------------------------------------------------------------------
            |
            | Manual registration:
            |   Selected administrative_unit_id is converted to:
            |   "Wereda 01, Abbaa Gadaa Subcity, Adama City"
            |
            | External systems:
            |   Address can come directly from external source.
            |
            */

            $table->foreignUuid('administrative_unit_id')
            ->nullable()
            ->constrained('administrative_units')
            ->restrictOnDelete();


            $table->string('address')
            ->nullable()
            ->index();


            /*
            |--------------------------------------------------------------------------
            | Registration Audit
            |--------------------------------------------------------------------------
            |
            | User who created/updated this citizen.
            |
            | Possible roles:
            | - SYSTEM_ADMIN
            | - DATA_MANAGER
            | - REGISTRATION_OFFICER
            | - SECTOR_OFFICER
            | - REVENUE_COLLECTOR
            |
            */

            $table->foreignUuid('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignUuid('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();


            /*
            |--------------------------------------------------------------------------
            | Registration Metadata
            |--------------------------------------------------------------------------
            */

            $table->timestamp('registered_at')
                ->useCurrent();


            /*
            |--------------------------------------------------------------------------
            | Data Source
            |--------------------------------------------------------------------------
            |
            | MANUAL
            | NATIONAL_ID_API
            | IMPORT
            |
            */

            $table->enum('source', [
                'MANUAL',
                'EXTERNAL_SYSTEM',
                'IMPORT',
            ])
            ->default('MANUAL')
            ->index();


            /*
            |--------------------------------------------------------------------------
            | External System Reference
            |--------------------------------------------------------------------------
            */

            $table->string('external_id')
                ->nullable()
                ->unique();


            /*
            |--------------------------------------------------------------------------
            | Status
            |--------------------------------------------------------------------------
            */

            $table->boolean('is_active')
                ->default(true)
                ->index();


            /*
            |--------------------------------------------------------------------------
            | Timestamps
            |--------------------------------------------------------------------------
            */

            $table->timestamps();

            $table->softDeletes();


            /*
            |--------------------------------------------------------------------------
            | Performance Indexes
            |--------------------------------------------------------------------------
            */

            $table->index([
                'administrative_unit_id',
                'created_at',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('citizens');
    }
};