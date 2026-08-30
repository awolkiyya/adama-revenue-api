<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    public function up(): void
    {
        Schema::create('revenue_categories', function (Blueprint $table) {


            /**
             * 🧠 UUID Primary Key
             */
            $table->uuid('id')->primary();



            /**
             * 🧠 Revenue domain
             *
             * TAX
             * RENT
             * INVESTMENT
             * SERVICE
             * SALE
             * CAPITAL
             */
            $table->enum('revenue_domain', [

                'TAX',

                'RENT',

                'INVESTMENT',

                'SERVICE',

                'SALE',

                'CAPITAL'

            ])
            ->index();



            /**
             * 🧠 Revenue category name
             *
             * Example:
             *
             * Galii Taaksii Mana Gopheessaa
             * Galii Kiraarraa Argamu
             * Kaffaltii Tajaajilaa
             */
            $table->string('name')
                ->index();



            /**
             * 🧠 Revenue code range
             *
             * Example:
             *
             * Galii Taaksii Mana Gopheessaa
             *
             * 1701 - 1719
             */
            $table->integer('start_code')
                ->nullable();



            $table->integer('end_code')
                ->nullable();



            /**
             * 🧠 Description
             */
            $table->text('description')
                ->nullable();



            /**
             * 🧠 Display ordering
             */
            $table->unsignedInteger('sort_order')
                ->default(0);



            /**
             * 🧠 Status
             */
            $table->boolean('is_active')
                ->default(true)
                ->index();



            /**
             * 🧠 Timestamps
             */
            $table->timestamps();



            /**
             * 🧠 Soft delete
             */
            $table->softDeletes();


        });
    }



    public function down(): void
    {
        Schema::dropIfExists('revenue_categories');
    }

};