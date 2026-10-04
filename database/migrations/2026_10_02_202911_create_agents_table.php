<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agents', function (Blueprint $table) {

            /*
            |--------------------------------------------------------------------------
            | UUID Primary Key
            |--------------------------------------------------------------------------
            */

            $table->uuid('id')->primary();


            /*
            |--------------------------------------------------------------------------
            | Agent Code
            |--------------------------------------------------------------------------
            |
            | Unique identifier used to identify the Agent in the revenue system.
            | Example: AGT-000001
            |
            */

            $table->string('agent_code')
                ->unique();


            /*
            |--------------------------------------------------------------------------
            | Agent Type
            |--------------------------------------------------------------------------
            |
            | Defines the type/purpose of the Agent.
            |
            */

            $table->enum('agent_type', [
                'PAYMENT',
            ])
            ->default('PAYMENT')
            ->index();


            /*
            |--------------------------------------------------------------------------
            | Phone
            |--------------------------------------------------------------------------
            |
            | Business/contact phone number of the Agent.
            |
            */

            $table->string('phone')
                ->nullable()
                ->index();


            /*
            |--------------------------------------------------------------------------
            | Status
            |--------------------------------------------------------------------------
            */

            $table->enum('status', [
                'ACTIVE',
                'INACTIVE',
                'SUSPENDED',
            ])
            ->default('ACTIVE')
            ->index();


            /*
            |--------------------------------------------------------------------------
            | Timestamps
            |--------------------------------------------------------------------------
            */

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agents');
    }
};