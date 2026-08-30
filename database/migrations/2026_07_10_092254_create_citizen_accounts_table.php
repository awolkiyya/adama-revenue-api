<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('citizen_accounts', function (Blueprint $table) {

            /*
            |--------------------------------------------------------------------------
            | UUID Primary Key
            |--------------------------------------------------------------------------
            */

            $table->uuid('id')->primary();


            /*
            |--------------------------------------------------------------------------
            | Authentication User
            |--------------------------------------------------------------------------
            |
            | Login account stored in users table.
            |
            */

            $table->foreignUuid('user_id')
                ->unique()
                ->constrained('users')
                ->cascadeOnDelete();


            /*
            |--------------------------------------------------------------------------
            | Citizen Profile
            |--------------------------------------------------------------------------
            |
            | Citizen personal information.
            |
            */

            $table->foreignUuid('citizen_id')
                ->unique()
                ->constrained('citizens')
                ->cascadeOnDelete();


            /*
            |--------------------------------------------------------------------------
            | Login Method
            |--------------------------------------------------------------------------
            */

            $table->enum('login_type', [
                'OTP',
                'PASSWORD',
            ])
            ->default('OTP')
            ->index();


            /*
            |--------------------------------------------------------------------------
            | Account Status
            |--------------------------------------------------------------------------
            */

            $table->boolean('is_active')
                ->default(true)
                ->index();


            /*
            |--------------------------------------------------------------------------
            | Last Login
            |--------------------------------------------------------------------------
            */

            $table->timestamp('last_login_at')
                ->nullable();


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
        Schema::dropIfExists('citizen_accounts');
    }
};