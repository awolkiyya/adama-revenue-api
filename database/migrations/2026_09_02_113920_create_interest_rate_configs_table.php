<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interest_rate_configs', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('name');

            // Stored as decimal:
            // 24.725% = 0.24725
            $table->decimal('rate', 12, 8);

            $table->string('rate_type')->default('ANNUAL');

            $table->date('effective_from');
            $table->date('effective_to')->nullable();

            $table->boolean('is_active')->default(true);

            $table->string('source')->nullable();
            $table->text('description')->nullable();

            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();

            $table->timestamps();

            $table->index([
                'effective_from',
                'effective_to',
            ]);

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interest_rate_configs');
    }
};