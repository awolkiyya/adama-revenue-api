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
        Schema::table('base_fields', function (Blueprint $table) {
            $table->enum('input_source', [
                'MANUAL',
                'AI',
                'MANUAL_OR_AI',
            ])
                ->default('MANUAL')
                ->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('base_fields', function (Blueprint $table) {
            $table->dropIndex(['input_source']);
            $table->dropColumn('input_source');
        });
    }
};
