<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::transaction(function (): void {

            /*
            |--------------------------------------------------------------------------
            | 1. Add a temporary UUID column
            |--------------------------------------------------------------------------
            */

            DB::statement('
                ALTER TABLE cash_reconciliations
                ADD COLUMN id_uuid UUID
            ');

            /*
            |--------------------------------------------------------------------------
            | 2. Generate UUIDs for existing reconciliations
            |--------------------------------------------------------------------------
            */

            DB::statement('
                UPDATE cash_reconciliations
                SET id_uuid = gen_random_uuid()
            ');

            /*
            |--------------------------------------------------------------------------
            | 3. Update existing polymorphic file references
            |--------------------------------------------------------------------------
            |
            | Preserve existing file associations by replacing the old
            | integer reconciliation ID with its newly generated UUID.
            |
            */

            DB::update(
                '
                UPDATE files AS f
                SET fileable_id = c.id_uuid,
                    updated_at = NOW()
                FROM cash_reconciliations AS c
                WHERE f.fileable_type = ?
                  AND f.fileable_id::text = c.id::text
                ',
                ['App\\Models\\CashReconciliation']
            );

            /*
            |--------------------------------------------------------------------------
            | 4. Remove the old primary key
            |--------------------------------------------------------------------------
            */

            DB::statement('
                ALTER TABLE cash_reconciliations
                DROP CONSTRAINT cash_reconciliations_pkey
            ');

            /*
            |--------------------------------------------------------------------------
            | 5. Remove the old integer ID
            |--------------------------------------------------------------------------
            */

            DB::statement('
                ALTER TABLE cash_reconciliations
                DROP COLUMN id
            ');

            /*
            |--------------------------------------------------------------------------
            | 6. Rename the UUID column to id
            |--------------------------------------------------------------------------
            */

            DB::statement('
                ALTER TABLE cash_reconciliations
                RENAME COLUMN id_uuid TO id
            ');

            /*
            |--------------------------------------------------------------------------
            | 7. Make UUID the new primary key
            |--------------------------------------------------------------------------
            */

            DB::statement('
                ALTER TABLE cash_reconciliations
                ALTER COLUMN id SET NOT NULL
            ');

            DB::statement('
                ALTER TABLE cash_reconciliations
                ADD PRIMARY KEY (id)
            ');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException(
            'This migration cannot be safely reversed because the original integer IDs cannot be reconstructed.'
        );
    }
};
