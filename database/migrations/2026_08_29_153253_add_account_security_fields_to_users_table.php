<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Users - Account Security
        |--------------------------------------------------------------------------
        */

        Schema::table('users', function (Blueprint $table) {

            /*
            |--------------------------------------------------------------------------
            | Username
            |--------------------------------------------------------------------------
            */
            if (!Schema::hasColumn('users', 'username')) {
                $table->string('username')
                    ->nullable()
                    ->unique()
                    ->after('phone');
            }


            /*
            |--------------------------------------------------------------------------
            | Login Tracking
            |--------------------------------------------------------------------------
            */
            if (!Schema::hasColumn('users', 'last_failed_login_at')) {
                $table->timestamp('last_failed_login_at')
                    ->nullable()
                    ->after('last_login_at');
            }


            /*
            |--------------------------------------------------------------------------
            | Password Security
            |--------------------------------------------------------------------------
            */
            if (!Schema::hasColumn('users', 'password_changed_at')) {
                $table->timestamp('password_changed_at')
                    ->nullable()
                    ->after('locked_until');
            }

            if (!Schema::hasColumn('users', 'must_change_password')) {
                $table->boolean('must_change_password')
                    ->default(false)
                    ->after('password_changed_at');
            }
        });


        /*
        |--------------------------------------------------------------------------
        | Sessions
        |--------------------------------------------------------------------------
        | Your users.id is UUID, so sessions.user_id must also be UUID.
        |--------------------------------------------------------------------------
        */

        Schema::table('sessions', function (Blueprint $table) {

            /*
            | Remove the existing user_id foreign key if present.
            */
            try {
                $table->dropForeign(['user_id']);
            } catch (\Throwable $e) {
                // No foreign key exists.
            }

            /*
            | Remove the old integer user_id.
            */
            if (Schema::hasColumn('sessions', 'user_id')) {
                $table->dropColumn('user_id');
            }
        });


        /*
        |--------------------------------------------------------------------------
        | Re-create sessions.user_id as UUID
        |--------------------------------------------------------------------------
        */

        Schema::table('sessions', function (Blueprint $table) {

            $table->foreignUuid('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
        });
    }


    public function down(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Sessions
        |--------------------------------------------------------------------------
        */

        Schema::table('sessions', function (Blueprint $table) {

            try {
                $table->dropForeign(['user_id']);
            } catch (\Throwable $e) {
                // No foreign key exists.
            }

            if (Schema::hasColumn('sessions', 'user_id')) {
                $table->dropColumn('user_id');
            }
        });


        /*
        |--------------------------------------------------------------------------
        | Users
        |--------------------------------------------------------------------------
        */

        Schema::table('users', function (Blueprint $table) {

            if (Schema::hasColumn('users', 'username')) {
                $table->dropUnique(['username']);
                $table->dropColumn('username');
            }

            if (Schema::hasColumn('users', 'last_failed_login_at')) {
                $table->dropColumn('last_failed_login_at');
            }

            if (Schema::hasColumn('users', 'password_changed_at')) {
                $table->dropColumn('password_changed_at');
            }

            if (Schema::hasColumn('users', 'must_change_password')) {
                $table->dropColumn('must_change_password');
            }
        });
    }
};
