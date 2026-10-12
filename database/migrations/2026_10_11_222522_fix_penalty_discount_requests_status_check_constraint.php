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
        DB::statement(<<<'SQL'
            ALTER TABLE penalty_discount_requests
            DROP CONSTRAINT IF EXISTS penalty_discount_requests_status_check
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE penalty_discount_requests
            ADD CONSTRAINT penalty_discount_requests_status_check
            CHECK (
                status IN (
                    'DRAFT',
                    'SUBMITTED',
                    'APPROVED',
                    'REJECTED',
                    'APPLIED',
                    'CANCELLED'
                )
            )
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Intentionally do not restore the old constraint because its
        // definition may not allow statuses already used by existing records.
        // Restore the previous constraint manually if a rollback is required.
    }
};
