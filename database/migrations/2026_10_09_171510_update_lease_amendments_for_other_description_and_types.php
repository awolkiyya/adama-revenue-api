
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        /*
        |--------------------------------------------------------------------------
        | Add Other Amendment Description
        |--------------------------------------------------------------------------
        */

        if (! Schema::hasColumn(
            'lease_amendments',
            'other_amendment_description'
        )) {
            Schema::table('lease_amendments', function (Blueprint $table) {
                $table->text('other_amendment_description')
                    ->nullable()
                    ->after('reason');
            });
        }

        /*
        |--------------------------------------------------------------------------
        | Remove Effective Date
        |--------------------------------------------------------------------------
        |
        | The current workflow uses approval and application timestamps.
        | A separately supplied effective date is not required.
        |
        */

        if (Schema::hasColumn('lease_amendments', 'effective_date')) {
            if ($driver === 'mysql') {
                // Drop the existing index before dropping the column.
                $indexExists = DB::selectOne("
                    SELECT COUNT(*) AS aggregate
                    FROM information_schema.statistics
                    WHERE table_schema = DATABASE()
                      AND table_name = 'lease_amendments'
                      AND index_name = 'lease_amendments_effective_date_index'
                ");

                if ($indexExists && (int) $indexExists->aggregate > 0) {
                    Schema::table('lease_amendments', function (Blueprint $table) {
                        $table->dropIndex(
                            'lease_amendments_effective_date_index'
                        );
                    });
                }
            } elseif ($driver === 'pgsql') {
                $indexExists = DB::selectOne("
                    SELECT COUNT(*) AS aggregate
                    FROM pg_indexes
                    WHERE schemaname = current_schema()
                      AND tablename = 'lease_amendments'
                      AND indexname = 'lease_amendments_effective_date_index'
                ");

                if ($indexExists && (int) $indexExists->aggregate > 0) {
                    Schema::table('lease_amendments', function (Blueprint $table) {
                        $table->dropIndex(
                            'lease_amendments_effective_date_index'
                        );
                    });
                }
            }

            Schema::table('lease_amendments', function (Blueprint $table) {
                $table->dropColumn('effective_date');
            });
        }

        /*
        |--------------------------------------------------------------------------
        | Align Amendment Types With The Frontend
        |--------------------------------------------------------------------------
        */

        if ($driver === 'mysql') {
            /*
             * Expand the enum before changing existing values.
             */

            DB::statement("
                ALTER TABLE lease_amendments
                MODIFY COLUMN amendment_type
                ENUM(
                    'NAME_TRANSFER',
                    'OWNERSHIP_TRANSFER',
                    'LAND_AREA_CHANGE',
                    'LAND_PARTIAL_TRANSFER',
                    'PARTIAL_TRANSFER',
                    'LAND_MERGE',
                    'OTHER'
                ) NOT NULL
            ");

            /*
             * Migrate legacy values.
             */

            DB::table('lease_amendments')
                ->where('amendment_type', 'NAME_TRANSFER')
                ->update([
                    'amendment_type' => 'OWNERSHIP_TRANSFER',
                ]);

            DB::table('lease_amendments')
                ->where('amendment_type', 'LAND_PARTIAL_TRANSFER')
                ->update([
                    'amendment_type' => 'PARTIAL_TRANSFER',
                ]);

            /*
             * Remove legacy enum values.
             */

            DB::statement("
                ALTER TABLE lease_amendments
                MODIFY COLUMN amendment_type
                ENUM(
                    'OWNERSHIP_TRANSFER',
                    'LAND_AREA_CHANGE',
                    'PARTIAL_TRANSFER',
                    'LAND_MERGE',
                    'OTHER'
                ) NOT NULL
            ");
        } elseif ($driver === 'pgsql') {
            /*
             * This branch supports VARCHAR/TEXT columns, not native enums.
             */

            $columnType = DB::selectOne("
                SELECT data_type
                FROM information_schema.columns
                WHERE table_schema = current_schema()
                  AND table_name = 'lease_amendments'
                  AND column_name = 'amendment_type'
            ");

            if (! $columnType) {
                throw new RuntimeException(
                    'The lease_amendments.amendment_type column was not found.'
                );
            }

            if ($columnType->data_type === 'USER-DEFINED') {
                throw new RuntimeException(
                    'amendment_type uses a PostgreSQL native enum. '
                    . 'A migration tailored to that enum is required.'
                );
            }

            DB::table('lease_amendments')
                ->where('amendment_type', 'NAME_TRANSFER')
                ->update([
                    'amendment_type' => 'OWNERSHIP_TRANSFER',
                ]);

            DB::table('lease_amendments')
                ->where('amendment_type', 'LAND_PARTIAL_TRANSFER')
                ->update([
                    'amendment_type' => 'PARTIAL_TRANSFER',
                ]);

            DB::statement("
                ALTER TABLE lease_amendments
                ADD CONSTRAINT lease_amendments_type_check
                CHECK (
                    amendment_type IN (
                        'OWNERSHIP_TRANSFER',
                        'LAND_AREA_CHANGE',
                        'PARTIAL_TRANSFER',
                        'LAND_MERGE',
                        'OTHER'
                    )
                )
            ");
        } else {
            throw new RuntimeException(
                "Unsupported database driver: {$driver}"
            );
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        /*
        |--------------------------------------------------------------------------
        | Restore Legacy Amendment Types
        |--------------------------------------------------------------------------
        */

        if ($driver === 'mysql') {
            /*
             * Allow both old and new values before converting records.
             */

            DB::statement("
                ALTER TABLE lease_amendments
                MODIFY COLUMN amendment_type
                ENUM(
                    'NAME_TRANSFER',
                    'OWNERSHIP_TRANSFER',
                    'LAND_AREA_CHANGE',
                    'LAND_PARTIAL_TRANSFER',
                    'PARTIAL_TRANSFER',
                    'LAND_MERGE',
                    'OTHER'
                ) NOT NULL
            ");

            DB::table('lease_amendments')
                ->where('amendment_type', 'OWNERSHIP_TRANSFER')
                ->update([
                    'amendment_type' => 'NAME_TRANSFER',
                ]);

            DB::table('lease_amendments')
                ->where('amendment_type', 'PARTIAL_TRANSFER')
                ->update([
                    'amendment_type' => 'LAND_PARTIAL_TRANSFER',
                ]);

            /*
             * Restore the original enum values.
             */

            DB::statement("
                ALTER TABLE lease_amendments
                MODIFY COLUMN amendment_type
                ENUM(
                    'NAME_TRANSFER',
                    'LAND_AREA_CHANGE',
                    'LAND_PARTIAL_TRANSFER',
                    'LAND_MERGE',
                    'OTHER'
                ) NOT NULL
            ");
        } elseif ($driver === 'pgsql') {
            DB::statement("
                ALTER TABLE lease_amendments
                DROP CONSTRAINT IF EXISTS lease_amendments_type_check
            ");

            DB::table('lease_amendments')
                ->where('amendment_type', 'OWNERSHIP_TRANSFER')
                ->update([
                    'amendment_type' => 'NAME_TRANSFER',
                ]);

            DB::table('lease_amendments')
                ->where('amendment_type', 'PARTIAL_TRANSFER')
                ->update([
                    'amendment_type' => 'LAND_PARTIAL_TRANSFER',
                ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Remove Other Amendment Description
        |--------------------------------------------------------------------------
        */

        if (Schema::hasColumn(
            'lease_amendments',
            'other_amendment_description'
        )) {
            Schema::table('lease_amendments', function (Blueprint $table) {
                $table->dropColumn('other_amendment_description');
            });
        }

        /*
        |--------------------------------------------------------------------------
        | Restore Effective Date
        |--------------------------------------------------------------------------
        */

        if (! Schema::hasColumn('lease_amendments', 'effective_date')) {
            Schema::table('lease_amendments', function (Blueprint $table) {
                $table->date('effective_date')
                    ->nullable()
                    ->index();
            });
        }
    }
};