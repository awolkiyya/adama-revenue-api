<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('revenue_services', function (Blueprint $table) {

            /*
            |--------------------------------------------------------------------------
            | Primary Key
            |--------------------------------------------------------------------------
            */

            $table->uuid('id')->primary();


            /*
            |--------------------------------------------------------------------------
            | Revenue Code
            |--------------------------------------------------------------------------
            */

            $table->foreignUuid('revenue_code_id')
                ->constrained('revenue_codes')
                ->cascadeOnDelete();


            /*
            |--------------------------------------------------------------------------
            | Service Identity
            |--------------------------------------------------------------------------
            */

            $table->string('name');

            $table->text('description')
                ->nullable();


            /*
            |--------------------------------------------------------------------------
            | Service Type
            |--------------------------------------------------------------------------
            |
            | Defines the operational purpose of the service.
            |
            */

            $table->enum('service_type', [
                'REGISTRATION',
                'ASSESSMENT',
                'PERMIT',
                'RENEWAL',
                'COLLECTION',
                'PENALTY',
            ])
            ->nullable()
            ->index();


            /*
            |--------------------------------------------------------------------------
            | Collection Mode
            |--------------------------------------------------------------------------
            |
            | ASSESSMENT_ONLY
            |     Assessment is required before collection.
            |
            | FIELD_COLLECTION
            |     Collector can directly create an invoice/collection
            |     at field level.
            |
            | BOTH
            |     Service supports both workflows.
            |
            */

            $table->enum('collection_mode', [
                'ASSESSMENT_ONLY',
                'FIELD_COLLECTION',
                'BOTH',
            ])
            ->default('ASSESSMENT_ONLY')
            ->index();


            /*
            |--------------------------------------------------------------------------
            | Active Status
            |--------------------------------------------------------------------------
            */

            $table->boolean('is_active')
                ->default(true)
                ->index();


            /*
            |--------------------------------------------------------------------------
            | Audit Users
            |--------------------------------------------------------------------------
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
            | System
            |--------------------------------------------------------------------------
            */

            $table->timestamps();

            $table->softDeletes();


            /*
            |--------------------------------------------------------------------------
            | Prevent Duplicate Services
            |--------------------------------------------------------------------------
            */

            $table->unique([
                'revenue_code_id',
                'name',
            ]);
        });
    }


    public function down(): void
    {
        Schema::dropIfExists('revenue_services');
    }
};