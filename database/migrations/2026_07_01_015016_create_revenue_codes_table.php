<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    public function up(): void
    {
        Schema::create('revenue_codes', function (Blueprint $table) {


            /**
             * 🧠 UUID Primary Key
             */
            $table->uuid('id')->primary();



            /**
             * 🧠 Revenue category relation
             *
             * revenue_categories.id = UUID
             */
            $table->uuid('category_id');


            $table->foreign('category_id')
                ->references('id')
                ->on('revenue_categories')
                ->cascadeOnDelete();



            /**
             * 🧠 Government revenue code
             *
             * Example:
             * 1701
             * 1702
             */
            $table->string('code');



            /**
             * 🧠 Revenue name
             */
            $table->string('name');



            /**
             * 🧠 Description
             */
            $table->text('description')
                ->nullable();


            /**
             * 🧠 Active status
             */
            $table->boolean('is_active')
                ->default(true)
                ->index();



            $table->timestamps();


            /**
             * 🧠 Soft delete
             */
            $table->softDeletes();



            /**
             * 🧠 Same code cannot repeat inside same category
             */
            $table->unique([
                'category_id',
                'code'
            ]);

        });
    }


    public function down(): void
    {
        Schema::dropIfExists('revenue_codes');
    }

};