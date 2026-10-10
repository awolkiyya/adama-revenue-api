<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mobile_app_releases')) {
            throw new RuntimeException(
                'The mobile_app_releases table does not exist.'
            );
        }

        $idColumn = DB::selectOne("
            SELECT data_type
            FROM information_schema.columns
            WHERE table_schema = current_schema()
              AND table_name = 'mobile_app_releases'
              AND column_name = 'id'
        ");

        if (! $idColumn) {
            throw new RuntimeException(
                'The mobile_app_releases.id column does not exist.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Check Existing Release Records
        |--------------------------------------------------------------------------
        |
        | The files.fileable_id column is polymorphic. Existing relationships
        | must be migrated using an explicit old-ID-to-UUID mapping.
        |
        */

        if (DB::table('mobile_app_releases')->exists()) {
            throw new RuntimeException(
                'mobile_app_releases contains existing records. '
                . 'Migrate release IDs and files.fileable_id associations '
                . 'before converting the primary key.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Convert Primary Key To UUID
        |--------------------------------------------------------------------------
        */

        if ($idColumn->data_type === 'bigint') {
            /*
             * Check for ordinary foreign keys referencing this primary key.
             */
            $references = DB::select("
                SELECT
                    tc.table_name,
                    kcu.column_name
                FROM information_schema.table_constraints AS tc
                JOIN information_schema.key_column_usage AS kcu
                  ON tc.constraint_name = kcu.constraint_name
                 AND tc.constraint_schema = kcu.constraint_schema
                JOIN information_schema.constraint_column_usage AS ccu
                  ON ccu.constraint_name = tc.constraint_name
                 AND ccu.constraint_schema = tc.constraint_schema
                WHERE tc.constraint_type = 'FOREIGN KEY'
                  AND ccu.table_schema = current_schema()
                  AND ccu.table_name = 'mobile_app_releases'
                  AND ccu.column_name = 'id'
            ");

            if (! empty($references)) {
                throw new RuntimeException(
                    'Foreign keys reference mobile_app_releases.id. '
                    . 'Update those relationships before converting the key.'
                );
            }

            Schema::table('mobile_app_releases', function (Blueprint $table) {
                $table->uuid('new_uuid_id')->nullable();
            });

            /*
             * No release records exist, so there are no IDs or file
             * associations to remap.
             */
            Schema::table('mobile_app_releases', function (Blueprint $table) {
                $table->dropPrimary();
                $table->dropColumn('id');
            });

            Schema::table('mobile_app_releases', function (Blueprint $table) {
                $table->renameColumn('new_uuid_id', 'id');
            });

            DB::statement(
                'ALTER TABLE mobile_app_releases '
                . 'ALTER COLUMN id SET NOT NULL'
            );

            DB::statement(
                'ALTER TABLE mobile_app_releases '
                . 'ADD PRIMARY KEY (id)'
            );
        } elseif ($idColumn->data_type !== 'uuid') {
            throw new RuntimeException(
                'Unsupported mobile_app_releases.id type: '
                . $idColumn->data_type
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Remove Duplicated APK Storage Metadata
        |--------------------------------------------------------------------------
        |
        | The files table is the authoritative storage metadata source.
        |
        */

        $columnsToDrop = [];

        foreach (['apk_path', 'apk_size', 'apk_sha256'] as $column) {
            if (Schema::hasColumn('mobile_app_releases', $column)) {
                $columnsToDrop[] = $column;
            }
        }

        if ($columnsToDrop !== []) {
            Schema::table('mobile_app_releases', function (Blueprint $table) use ($columnsToDrop) {
                $table->dropColumn($columnsToDrop);
            });
        }
    }

    public function down(): void
    {
        /*
         * Restoring BIGINT IDs cannot be done safely without the original
         * numeric IDs. Recreate the metadata columns as nullable because
         * their previous values are not recoverable from this migration.
         */

        Schema::table('mobile_app_releases', function (Blueprint $table) {
            if (! Schema::hasColumn('mobile_app_releases', 'apk_path')) {
                $table->string('apk_path')->nullable();
            }

            if (! Schema::hasColumn('mobile_app_releases', 'apk_size')) {
                $table->unsignedBigInteger('apk_size')->nullable();
            }

            if (! Schema::hasColumn('mobile_app_releases', 'apk_sha256')) {
                $table->string('apk_sha256', 64)->nullable();
            }
        });
    }
};
