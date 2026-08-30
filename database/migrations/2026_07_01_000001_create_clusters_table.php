<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clusters', function (Blueprint $table) {

            // UUID primary key
            $table->uuid('id')->primary();


            /**
             * CITY RELATION (UUID MATCH)
             */
            $table->uuid('city_id');


            $table->foreign('city_id')
                ->references('id')
                ->on('administrative_units')
                ->cascadeOnDelete();


            $table->string('name');

            $table->string('code')
                ->nullable();

            $table->text('description')
                ->nullable();


            $table->boolean('is_active')
                ->default(true);


            $table->timestamps();


            $table->unique(
                ['city_id', 'name'],
                'unique_cluster_per_city'
            );

        });
    }


    public function down(): void
    {
        Schema::dropIfExists('clusters');
    }
};