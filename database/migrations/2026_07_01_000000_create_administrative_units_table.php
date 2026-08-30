<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {

        Schema::create('administrative_units', function (Blueprint $table) {


            // UUID Primary Key
            $table->uuid('id')->primary();


            // Information
            $table->string('name');

            $table->string('code')
                  ->unique();


            // Parent hierarchy
            $table->uuid('parent_id')
                  ->nullable();


            // CITY -> SUBCITY -> WEREDA
            $table->enum('level', [
                'CITY',
                'SUBCITY',
                'WEREDA'
            ]);


            $table->boolean('is_active')
                  ->default(true);


            // Audit
            $table->uuid('created_by')
                  ->nullable();

            $table->uuid('updated_by')
                  ->nullable();


            $table->timestamps();


            $table->index('parent_id');
            $table->index('level');

        });



        /*
        |--------------------------------------------------------------------------
        | Self Reference Foreign Key
        |--------------------------------------------------------------------------
        */

        Schema::table('administrative_units', function (Blueprint $table) {

            $table->foreign('parent_id')
                  ->references('id')
                  ->on('administrative_units')
                  ->nullOnDelete();

        });

    }


    public function down(): void
    {
        Schema::dropIfExists('administrative_units');
    }

};