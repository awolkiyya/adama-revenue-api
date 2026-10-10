<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mobile_app_releases', function (Blueprint $table) {
            /*
            |--------------------------------------------------------------------------
            | Remove Duplicated APK Storage Metadata
            |--------------------------------------------------------------------------
            */

            $table->dropColumn([
                'apk_path',
                'apk_size',
                'apk_sha256',
            ]);

            /*
            |--------------------------------------------------------------------------
            | Change Primary Key To UUID
            |--------------------------------------------------------------------------
            |
            | This is required because files.fileable_id is UUID-based.
            | The existing integer primary key must be migrated safely.
            |
            | Do not change the primary key in this migration without
            | first checking existing records and foreign-key constraints.
            |
            */
        });

        /*
         * Primary-key conversion is intentionally not performed here.
         * See the instructions below before applying the migration.
         */
    }

    public function down(): void
    {
        Schema::table('mobile_app_releases', function (Blueprint $table) {
            $table->string('apk_path')->nullable();
            $table->unsignedBigInteger('apk_size')->nullable();
            $table->string('apk_sha256', 64)->nullable();
        });
    }
};
