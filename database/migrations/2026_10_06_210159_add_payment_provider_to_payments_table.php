<?php

use App\Enums\PaymentProvider;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table
                ->string('payment_provider')
                ->nullable()
                ->after('payment_method');

            $table->index('payment_provider');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex([
                'payment_provider',
            ]);

            $table->dropColumn(
                'payment_provider'
            );
        });
    }
};