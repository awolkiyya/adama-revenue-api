<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'ALTER TABLE assessments DROP CONSTRAINT IF EXISTS assessments_status_check'
        );

        DB::statement(
            "ALTER TABLE assessments
             ADD CONSTRAINT assessments_status_check
             CHECK (
                 status IN (
                     'DRAFT',
                     'PENDING_APPROVAL',
                     'APPROVED',
                     'RETURNED',
                     'CANCELLED'
                 )
             )"
        );
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE assessments DROP CONSTRAINT IF EXISTS assessments_status_check'
        );

        DB::statement(
            "ALTER TABLE assessments
             ADD CONSTRAINT assessments_status_check
             CHECK (
                 status IN (
                     'DRAFT',
                     'PENDING_APPROVAL',
                     'APPROVED',
                     'REJECTED',
                     'CANCELLED'
                 )
             )"
        );
    }
};