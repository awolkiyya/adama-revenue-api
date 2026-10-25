<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assessment_services', function (Blueprint $table) {
            $table->date('agreement_date')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('assessment_services', function (Blueprint $table) {
            $table->dropColumn('agreement_date');
        });
    }
};