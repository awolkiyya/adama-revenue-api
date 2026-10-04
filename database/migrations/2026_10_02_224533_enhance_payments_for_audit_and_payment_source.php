<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {

            /*
            |--------------------------------------------------------------------------
            | Payment Source
            |--------------------------------------------------------------------------
            |
            | Describes how the payment entered the municipal system.
            |
            | ONLINE
            | AGENT_ASSISTED
            | OFFICE_RECORDED
            | FIELD_COLLECTION
            | SYSTEM
            |
            */

            $table->string('payment_source', 50)
                ->default('ONLINE')
                ->after('payment_provider');


            /*
            |--------------------------------------------------------------------------
            | Payment Processor
            |--------------------------------------------------------------------------
            |
            | The authenticated system user who processed/recorded
            | the payment.
            |
            | Examples:
            |
            | - AGENT
            | - REVENUE_COLLECTOR
            | - REVENUE_OFFICER
            |
            | NULL is valid for fully automated online payments.
            |
            */

            $table->foreignUuid('processed_by')
                ->nullable()
                ->after('payment_source')
                ->constrained('users')
                ->nullOnDelete();


            /*
            |--------------------------------------------------------------------------
            | Payment Source / Processor Index
            |--------------------------------------------------------------------------
            */

            $table->index([
                'payment_source',
                'status',
            ]);

            $table->index([
                'processed_by',
                'created_at',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {

            /*
            |--------------------------------------------------------------------------
            | Remove Indexes
            |--------------------------------------------------------------------------
            */

            $table->dropIndex([
                'payment_source',
                'status',
            ]);

            $table->dropIndex([
                'processed_by',
                'created_at',
            ]);


            /*
            |--------------------------------------------------------------------------
            | Remove Foreign Key
            |--------------------------------------------------------------------------
            */

            $table->dropForeign([
                'processed_by',
            ]);


            /*
            |--------------------------------------------------------------------------
            | Remove Columns
            |--------------------------------------------------------------------------
            */

            $table->dropColumn([
                'payment_source',
                'processed_by',
            ]);
        });
    }
};
