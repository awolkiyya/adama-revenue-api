<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    public function up(): void
    {
        Schema::create('service_requests', function (Blueprint $table) {


            /**
             * UUID Primary Key
             */
            $table->uuid('id')->primary();



            /**
             * Human readable request number
             *
             * Example:
             * REQ-2026-000001
             */
            $table->string('request_number')
                ->unique();




            /**
             * Revenue Service
             *
             * revenue_services.id
             *
             * Example:
             * Property Tax
             * Business License
             */
            $table->uuid('service_id')
                ->index();


            $table->foreign('service_id')
                ->references('id')
                ->on('revenue_services')
                ->cascadeOnDelete();





            /**
             * Citizen who requested service
             *
             * citizens.id
             */
            $table->uuid('citizen_id')
                ->index();


            $table->foreign('citizen_id')
                ->references('id')
                ->on('citizens')
                ->cascadeOnDelete();





            /**
             * Responsible sector
             *
             * sectors.id
             *
             * Example:
             * Revenue Office
             */
            $table->uuid('sector_id')
                ->index();


            $table->foreign('sector_id')
                ->references('id')
                ->on('sectors')
                ->cascadeOnDelete();





            /**
             * Current workflow status
             */
            $table->enum('status',[

                'DRAFT',

                'SUBMITTED',

                'UNDER_REVIEW',

                'ASSESSMENT_PENDING',

                'ASSESSMENT_COMPLETED',

                'APPROVED',

                'REJECTED',

                'INVOICED',

                'PAID',

                'CANCELLED'

            ])
            ->default('DRAFT')
            ->index();





            /**
             * Request information
             *
             * Dynamic service-specific data
             *
             * Example:
             *
             * {
             *   "property_area":200,
             *   "property_type":"COMMERCIAL"
             * }
             */
            $table->json('data')
                ->nullable();





            /**
             * Optional citizen notes
             */
            $table->text('remarks')
                ->nullable();





            /**
             * Created by user
             *
             * Usually:
             * Sector Officer
             */
            $table->uuid('created_by')
                ->nullable();


            $table->foreign('created_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();





            /**
             * Current assigned officer
             *
             * Who is processing this request
             */
            $table->uuid('assigned_to')
                ->nullable();


            $table->foreign('assigned_to')
                ->references('id')
                ->on('users')
                ->nullOnDelete();





            /**
             * Completion information
             */
            $table->timestamp('submitted_at')
                ->nullable();


            $table->timestamp('completed_at')
                ->nullable();





            /**
             * Audit
             */
            $table->uuid('updated_by')
                ->nullable();


            $table->foreign('updated_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();





            $table->timestamps();


            /**
             * Soft Delete
             */
            $table->softDeletes();


        });
    }



    public function down(): void
    {
        Schema::dropIfExists('service_requests');
    }

};