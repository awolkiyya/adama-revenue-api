<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    public function up(): void
    {
        Schema::create('service_access_rules', function (Blueprint $table) {


            /**
             * UUID Primary Key
             */
            $table->uuid('id')->primary();



            /**
             * Revenue Service relation
             */
            $table->uuid('service_id')
                ->index();

            $table->foreign('service_id')
                ->references('id')
                ->on('revenue_services')
                ->cascadeOnDelete();



            /**
             * Sector relation
             */
            $table->uuid('sector_id')
                ->index();

            $table->foreign('sector_id')
                ->references('id')
                ->on('sectors')
                ->cascadeOnDelete();



            /**
             * Spatie Role relation
             *
             * roles.id = bigint
             */
            $table->unsignedBigInteger('role_id')
                ->index();

            $table->foreign('role_id')
                ->references('id')
                ->on('roles')
                ->cascadeOnDelete();



            /**
             * Allowed Service Actions
             *
             * JSON array:
             *
             * [
             *   "CREATE",
             *   "UPDATE",
             *   "SUBMIT",
             *   "VERIFY"
             * ]
             */
            $table->json('actions');



            /**
             * Active status
             */
            $table->boolean('is_active')
                ->default(true)
                ->index();



            /**
             * Audit users
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



            $table->timestamps();


            $table->softDeletes();



            /**
             * One permission group:
             *
             * Service + Sector + Role
             */
            $table->unique([
                'service_id',
                'sector_id',
                'role_id'
            ]);

        });
    }



    public function down(): void
    {
        Schema::dropIfExists('service_access_rules');
    }

};