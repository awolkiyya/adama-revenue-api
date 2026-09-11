<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_access_rules', function (Blueprint $table) {

            /*
            |--------------------------------------------------------------------------
            | Primary Key
            |--------------------------------------------------------------------------
            */

            $table->uuid('id')->primary();

            /*
            |--------------------------------------------------------------------------
            | Revenue Service
            |--------------------------------------------------------------------------
            |
            | The revenue service whose sector access is being configured.
            |
            */

            $table->foreignUuid('service_id')
                ->constrained('revenue_services')
                ->cascadeOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Sector
            |--------------------------------------------------------------------------
            |
            | The sector that is allowed or denied access to the service.
            |
            */

            $table->foreignUuid('sector_id')
                ->constrained('sectors')
                ->cascadeOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Access Status
            |--------------------------------------------------------------------------
            |
            | true  = sector is allowed to access the service
            | false = sector is not allowed to access the service
            |
            */

            $table->boolean('is_active')
                ->default(true);

            /*
            |--------------------------------------------------------------------------
            | Audit Users
            |--------------------------------------------------------------------------
            |
            | Tracks who created and last updated the access rule.
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
            | Timestamps
            |--------------------------------------------------------------------------
            */

            $table->timestamps();

            /*
            |--------------------------------------------------------------------------
            | Soft Deletes
            |--------------------------------------------------------------------------
            |
            | Allows an access rule to be removed without permanently
            | destroying its history.
            |
            */

            $table->softDeletes();

            /*
            |--------------------------------------------------------------------------
            | Unique Service + Sector
            |--------------------------------------------------------------------------
            |
            | A service can have only one access configuration
            | for each sector.
            |
            */

            $table->unique([
                'service_id',
                'sector_id',
            ]);

            /*
            |--------------------------------------------------------------------------
            | Indexes
            |--------------------------------------------------------------------------
            */

            $table->index([
                'service_id',
                'is_active',
            ]);

            $table->index([
                'sector_id',
                'is_active',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_access_rules');
    }
};