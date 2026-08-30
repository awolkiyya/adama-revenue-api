<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {


            /**
             * UUID Primary Key
             */
            $table->uuid('id')->primary();



            /**
             * User who performed action
             */
            $table->uuid('user_id')
                ->nullable()
                ->index();


            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();



            /**
             * Action
             *
             * CREATE
             * UPDATE
             * DELETE
             * LOGIN
             * LOGOUT
             * APPROVE
             * REJECT
             */
            $table->string('action')
                ->index();



            /**
             * Module
             *
             * TAXPAYER
             * REVENUE
             * PAYMENT
             * INVOICE
             * USER
             */
            $table->string('module')
                ->index();



            /**
             * Entity/Table affected
             */
            $table->string('table_name')
                ->nullable();



            /**
             * Record affected
             */
            $table->uuid('record_id')
                ->nullable()
                ->index();



            /**
             * Before change
             */
            $table->json('old_values')
                ->nullable();



            /**
             * After change
             */
            $table->json('new_values')
                ->nullable();



            /**
             * Request tracking
             */
            $table->string('ip_address',45)
                ->nullable();


            $table->text('user_agent')
                ->nullable();



            /**
             * Request ID
             *
             * Useful for tracing
             * one transaction across services
             */
            $table->uuid('request_id')
                ->nullable()
                ->index();



            /**
             * Session identifier
             */
            $table->string('session_id')
                ->nullable()
                ->index();



            /**
             * Additional context
             */
            $table->json('metadata')
                ->nullable();



            /**
             * Human readable message
             */
            $table->text('description')
                ->nullable();



            $table->timestamps();



            /**
             * Common audit queries
             */
            $table->index([
                'module',
                'action',
                'created_at'
            ]);


            $table->index([
                'user_id',
                'created_at'
            ]);

        });
    }



    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }

};