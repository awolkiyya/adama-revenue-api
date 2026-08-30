<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::create('user_sector_assignments', function (Blueprint $table) {


            /*
            |--------------------------------------------------------------------------
            | UUID Primary Key
            |--------------------------------------------------------------------------
            */

            $table->uuid('id')->primary();



            /*
            |--------------------------------------------------------------------------
            | User
            |--------------------------------------------------------------------------
            */

            $table->uuid('user_id');


            $table->foreign('user_id')
                  ->references('id')
                  ->on('users')
                  ->cascadeOnDelete();



            /*
            |--------------------------------------------------------------------------
            | Sector
            |--------------------------------------------------------------------------
            */

            $table->uuid('sector_id');


            $table->foreign('sector_id')
                  ->references('id')
                  ->on('sectors')
                  ->cascadeOnDelete();



            /*
            |--------------------------------------------------------------------------
            | Administrative Unit
            |--------------------------------------------------------------------------
            */

            $table->uuid('administrative_unit_id');


            $table->foreign('administrative_unit_id')
                  ->references('id')
                  ->on('administrative_units')
                  ->cascadeOnDelete();



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

            $table->uuid('created_by')
                  ->nullable();


            $table->uuid('updated_by')
                  ->nullable();



            $table->foreign('created_by')
                  ->references('id')
                  ->on('users')
                  ->nullOnDelete();



            $table->foreign('updated_by')
                  ->references('id')
                  ->on('users')
                  ->nullOnDelete();



            /*
            |--------------------------------------------------------------------------
            | Timestamps
            |--------------------------------------------------------------------------
            */

            $table->timestamps();


            $table->softDeletes();



            /*
            |--------------------------------------------------------------------------
            | Prevent Duplicate Assignment
            |--------------------------------------------------------------------------
            */

            $table->unique([
                'user_id',
                'sector_id',
                'administrative_unit_id'
            ]);

        });
    }


    public function down(): void
    {
        Schema::dropIfExists('user_sector_assignments');
    }

};