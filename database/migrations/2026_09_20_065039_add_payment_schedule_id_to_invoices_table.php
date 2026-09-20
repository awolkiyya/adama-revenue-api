<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {

            $table->foreignUuid('payment_schedule_id')
                ->nullable()
                ->after('assessment_id')
                ->constrained('payment_schedules')
                ->restrictOnDelete();

            $table->index(
                'payment_schedule_id',
                'invoices_payment_schedule_id_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {

            $table->dropForeign([
                'payment_schedule_id',
            ]);

            $table->dropIndex(
                'invoices_payment_schedule_id_index'
            );

            $table->dropColumn('payment_schedule_id');
        });
    }
};