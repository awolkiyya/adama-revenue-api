
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

        if (! in_array($driver, ['mysql', 'pgsql'], true)) {
            throw new RuntimeException(
                "Unsupported database driver: {$driver}"
            );
        }

        if (! Schema::hasTable('lease_amendments')) {
            throw new RuntimeException(
                'The lease_amendments table does not exist.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | 1. Add Other Amendment Description
        |--------------------------------------------------------------------------
        */

        if (! Schema::hasColumn(
            'lease_amendments',
            'other_amendment_description'
        )) {
            Schema::table('lease_amendments', function (Blueprint $table) {
                $table->text('other_amendment_description')
                    ->nullable();
            });
        }

        /*
        |--------------------------------------------------------------------------
        | 2. Ensure Applied Information Exists
        |--------------------------------------------------------------------------
        */

        if (! Schema::hasColumn('lease_amendments', 'applied_by')) {
            Schema::table('lease_amendments', function (Blueprint $table) {
                $table->foreignUuid('applied_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('lease_amendments', 'applied_at')) {
            Schema::table('lease_amendments', function (Blueprint $table) {
                $table->timestamp('applied_at')->nullable();
            });
        }

        /*
        |--------------------------------------------------------------------------
        | 3. Reconcile Status Column
        |--------------------------------------------------------------------------
        |
        | Normally, the earlier lifecycle migration has already renamed
        | status_new to status. This also handles the case where status_new
        | exists but status does not.
        |
        */

        $hasStatus = Schema::hasColumn('lease_amendments', 'status');
        $hasTemporaryStatus = Schema::hasColumn(
            'lease_amendments',
            'status_new'
        );

        if (! $hasStatus && $hasTemporaryStatus) {
            Schema::table('lease_amendments', function (Blueprint $table) {
                $table->renameColumn('status_new', 'status');
            });

            $hasStatus = true;
        }

        if (! $hasStatus) {
            throw new RuntimeException(
                'Neither status nor status_new exists. '
                . 'Inspect the current schema before continuing.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | 4. Expand Status Lifecycle
        |--------------------------------------------------------------------------
        */

        $allowedStatuses = [
            'DRAFT',
            'PENDING_APPROVAL',
            'APPROVED',
            'REJECTED',
            'CANCELLED',
            'APPLIED',
        ];

        $invalidStatuses = DB::table('lease_amendments')
            ->whereNotIn('status', $allowedStatuses)
            ->whereNotNull('status')
            ->pluck('status')
            ->unique()
            ->values()
            ->all();

        if ($invalidStatuses !== []) {
            throw new RuntimeException(
                'Unexpected lease amendment statuses found: '
                . implode(', ', $invalidStatuses)
                . '. Resolve these records before migrating.'
            );
        }

        if ($driver === 'mysql') {
            DB::statement("
                ALTER TABLE lease_amendments
                MODIFY COLUMN status
                ENUM(
                    'DRAFT',
                    'PENDING_APPROVAL',
                    'APPROVED',
                    'REJECTED',
                    'CANCELLED',
                    'APPLIED'
                ) NOT NULL DEFAULT 'DRAFT'
            ");
        } else {
            /*
             * Laravel enum columns on PostgreSQL are normally represented
             * by character columns. Native PostgreSQL enums require a
             * separate migration strategy.
             */

            $statusColumn = DB::selectOne("
                SELECT data_type
                FROM information_schema.columns
                WHERE table_schema = current_schema()
                  AND table_name = 'lease_amendments'
                  AND column_name = 'status'
            ");

            if (! $statusColumn) {
                throw new RuntimeException(
                    'The status column could not be found.'
                );
            }

            if ($statusColumn->data_type === 'USER-DEFINED') {
                throw new RuntimeException(
                    'The status column uses a PostgreSQL native enum. '
                    . 'Migrate that enum explicitly before proceeding.'
                );
            }

            DB::statement("
                ALTER TABLE lease_amendments
                DROP CONSTRAINT IF EXISTS lease_amendments_status_check
            ");

            DB::statement("
                ALTER TABLE lease_amendments
                ADD CONSTRAINT lease_amendments_status_check
                CHECK (
                    status IN (
                        'DRAFT',
                        'PENDING_APPROVAL',
                        'APPROVED',
                        'REJECTED',
                        'CANCELLED',
                        'APPLIED'
                    )
                )
            ");
        }

        /*
        |--------------------------------------------------------------------------
        | 5. Normalize Amendment Types
        |--------------------------------------------------------------------------
        */

        if ($driver === 'mysql') {
            /*
             * Expand the enum first so legacy and current values are valid
             * while records are being converted.
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
             * Keep only the current enum values.
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
        } else {
            $typeColumn = DB::selectOne("
                SELECT data_type
                FROM information_schema.columns
                WHERE table_schema = current_schema()
                  AND table_name = 'lease_amendments'
                  AND column_name = 'amendment_type'
            ");

            if (! $typeColumn) {
                throw new RuntimeException(
                    'The amendment_type column could not be found.'
                );
            }

            if ($typeColumn->data_type === 'USER-DEFINED') {
                throw new RuntimeException(
                    'The amendment_type column uses a PostgreSQL native '
                    . 'enum. Migrate that enum explicitly.'
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
                DROP CONSTRAINT IF EXISTS
                lease_amendments_amendment_type_check
            ");

            DB::statement("
                ALTER TABLE lease_amendments
                ADD CONSTRAINT lease_amendments_amendment_type_check
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
        }

        /*
        |--------------------------------------------------------------------------
        | 6. Add a Status Index If Missing
        |--------------------------------------------------------------------------
        */

        if ($driver === 'mysql') {
            $indexExists = DB::selectOne("
                SELECT COUNT(*) AS aggregate
                FROM information_schema.statistics
                WHERE table_schema = DATABASE()
                  AND table_name = 'lease_amendments'
                  AND column_name = 'status'
            ");

            if (! $indexExists || (int) $indexExists->aggregate === 0) {
                Schema::table('lease_amendments', function (Blueprint $table) {
                    $table->index(
                        'status',
                        'lease_amendments_status_index'
                    );
                });
            }
        } else {
            $indexExists = DB::selectOne("
                SELECT COUNT(*) AS aggregate
                FROM pg_indexes
                WHERE schemaname = current_schema()
                  AND tablename = 'lease_amendments'
                  AND indexdef ILIKE '%(status)%'
            ");

            if (! $indexExists || (int) $indexExists->aggregate === 0) {
                Schema::table('lease_amendments', function (Blueprint $table) {
                    $table->index(
                        'status',
                        'lease_amendments_status_index'
                    );
                });
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Important: Legacy Columns Are Intentionally Preserved
        |--------------------------------------------------------------------------
        |
        | Do not drop document_path or document_number until their existing
        | values have been migrated to the polymorphic files table.
        |
        | effective_date is also left untouched if it still exists, so a
        | partially migrated environment does not lose its data.
        |
        */
    }

    public function down(): void
    {
        /*
         * This migration normalizes persisted enum values and may add
         * APPLIED records in future. Reverting it automatically could lose
         * lifecycle information, so rollback is intentionally blocked.
         */

        throw new RuntimeException(
            'This corrective migration is intentionally irreversible. '
            . 'Create a reviewed forward migration if a rollback is required.'
        );
    }
};