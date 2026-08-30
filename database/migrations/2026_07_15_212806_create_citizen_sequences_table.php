<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('citizen_sequences', function (Blueprint $table) {

            $table->id();

            /*
             * Ethiopian calendar year
             * Example: 2018
             */
            $table->integer('year')
                ->unique();

            /*
             * Last generated citizen number
             * Example: 100
             */
            $table->unsignedBigInteger('last_number')
                ->default(0);

            $table->timestamps();

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('citizen_sequences');
    }
};