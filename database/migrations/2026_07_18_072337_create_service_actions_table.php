<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    public function up(): void
    {
        Schema::create('service_actions', function (Blueprint $table) {


            /**
             * UUID Primary Key
             */
            $table->uuid('id')->primary();



            /**
             * Action code
             *
             * Examples:
             * CREATE
             * ASSESS
             * APPROVE
             * COLLECT
             */
            $table->string('code')
                ->unique()
                ->index();



            /**
             * Display name
             *
             * Example:
             * Create Request
             * Perform Assessment
             */
            $table->string('name');



            /**
             * Description
             */
            $table->text('description')
                ->nullable();



            /**
             * Active status
             */
            $table->boolean('is_active')
                ->default(true)
                ->index();



            /**
             * Audit users
             *
             * users.id = UUID
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



            /**
             * Timestamps
             */
            $table->timestamps();



            /**
             * Soft delete
             */
            $table->softDeletes();

        });
    }


    public function down(): void
    {
        Schema::dropIfExists('service_actions');
    }

};