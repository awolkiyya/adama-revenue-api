<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedTinyInteger('failed_login_attempts')
                ->default(0)
                ->after('last_login_at');

            $table->timestamp('locked_until')
                ->nullable()
                ->index()
                ->after('failed_login_attempts');

            $table->unsignedInteger('lockout_count')
                ->default(0)
                ->after('locked_until');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'failed_login_attempts',
                'locked_until',
                'lockout_count',
            ]);
        });
    }
};