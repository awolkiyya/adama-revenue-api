<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {

            /*
            |--------------------------------------------------------------------------
            | UUID Primary Key
            |--------------------------------------------------------------------------
            */
            $table->uuid('id')->primary();
        
        
            /*
            |--------------------------------------------------------------------------
            | Administrative Unit
            |--------------------------------------------------------------------------
            | City / Subcity / Wereda
            |
            | SYSTEM_ADMIN can be NULL because they are global.
            */
            $table->foreignUuid('administrative_unit_id')
                ->nullable()
                ->constrained('administrative_units')
                ->nullOnDelete();
        
        
            /*
            |--------------------------------------------------------------------------
            | Sector Assignment
            |--------------------------------------------------------------------------
            | Only used by:
            | - SECTOR_OFFICER
            | - REVENUE_DECISION_OFFICER
            | - REVENUE_COLLECTOR
            |
            | Other users will have NULL.
            */
            $table->foreignUuid('sector_id')
                ->nullable()
                ->constrained('sectors')
                ->nullOnDelete();
        
        
            /*
            |--------------------------------------------------------------------------
            | Full Name
            |--------------------------------------------------------------------------
            */
            $table->string('name')
                ->index();
        
        
            /*
            |--------------------------------------------------------------------------
            | Display Label
            |--------------------------------------------------------------------------
            | Example:
            | Revenue Collector
            | Registration Officer
            */
            $table->string('label')
                ->nullable()
                ->index();
        
        
            /*
            |--------------------------------------------------------------------------
            | Authentication
            |--------------------------------------------------------------------------
            */
            $table->string('email')
                ->nullable()
                ->unique();
        
        
            $table->string('phone', 20)
                ->nullable()
                ->unique()
                ->index();
        
        
            $table->string('password')
                ->nullable();
        
        
            /*
            |--------------------------------------------------------------------------
            | User Type
            |--------------------------------------------------------------------------
            | employee | citizen
            */
            $table->string('user_type', 30)
                ->default('employee')
                ->index();

            $table->timestamp('last_login_at')
            ->nullable()
            ->index();
        
        
            /*
            |--------------------------------------------------------------------------
            | Verification
            |--------------------------------------------------------------------------
            */
            $table->timestamp('email_verified_at')
                ->nullable();
        
        
            $table->boolean('is_phone_verified')
                ->default(false);
        
        
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
            | Remember Token + Timestamps
            |--------------------------------------------------------------------------
            */
            $table->rememberToken();
        
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
