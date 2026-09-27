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
        Schema::table('invoice_items', function (Blueprint $table) {
            /*
            |--------------------------------------------------------------------------
            | PAYMENT SCHEDULE
            |--------------------------------------------------------------------------
            |
            | Nullable because only scheduled assessment invoice items
            | reference a payment schedule.
            |
            | ONE-TIME ASSESSMENT:
            |     payment_schedule_id = NULL
            |
            | SCHEDULED ASSESSMENT:
            |     payment_schedule_id = specific payment schedule
            |
            | DIRECT COLLECTION:
            |     payment_schedule_id = NULL
            |
            */

            $table->foreignUuid('payment_schedule_id')
                ->nullable()
                ->after('assessment_service_id')
                ->constrained('payment_schedules')
                ->restrictOnDelete();

            $table->index('payment_schedule_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropForeign(['payment_schedule_id']);
            $table->dropIndex(['payment_schedule_id']);
            $table->dropColumn('payment_schedule_id');
        });
    }
};