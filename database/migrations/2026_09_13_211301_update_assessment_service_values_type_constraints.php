<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * data_type
         *
         * SELECT is currently used by the BaseField configuration
         * (for example PROPERTY_TYPE), so the database must allow it.
         */
        DB::statement(
            'ALTER TABLE assessment_service_values
             DROP CONSTRAINT IF EXISTS assessment_service_values_data_type_check'
        );

        DB::statement(
            "ALTER TABLE assessment_service_values
             ADD CONSTRAINT assessment_service_values_data_type_check
             CHECK (
                 data_type IN (
                     'NUMBER',
                     'DECIMAL',
                     'TEXT',
                     'BOOLEAN',
                     'DATE',
                     'SELECT'
                 )
             )"
        );

        /*
         * input_type
         *
         * Keep SELECT and add MULTI_FILE because the application
         * supports both input types.
         */
        DB::statement(
            'ALTER TABLE assessment_service_values
             DROP CONSTRAINT IF EXISTS assessment_service_values_input_type_check'
        );

        DB::statement(
            "ALTER TABLE assessment_service_values
             ADD CONSTRAINT assessment_service_values_input_type_check
             CHECK (
                 input_type IN (
                     'TEXT',
                     'NUMBER',
                     'DECIMAL',
                     'SELECT',
                     'RADIO',
                     'CHECKBOX',
                     'DATE',
                     'TEXTAREA',
                     'FILE',
                     'MULTI_FILE'
                 )
             )"
        );
    }

    public function down(): void
    {
        /*
         * Restore original data_type constraint.
         */
        DB::statement(
            'ALTER TABLE assessment_service_values
             DROP CONSTRAINT IF EXISTS assessment_service_values_data_type_check'
        );

        DB::statement(
            "ALTER TABLE assessment_service_values
             ADD CONSTRAINT assessment_service_values_data_type_check
             CHECK (
                 data_type IN (
                     'NUMBER',
                     'DECIMAL',
                     'TEXT',
                     'BOOLEAN',
                     'DATE'
                 )
             )"
        );

        /*
         * Restore original input_type constraint.
         */
        DB::statement(
            'ALTER TABLE assessment_service_values
             DROP CONSTRAINT IF EXISTS assessment_service_values_input_type_check'
        );

        DB::statement(
            "ALTER TABLE assessment_service_values
             ADD CONSTRAINT assessment_service_values_input_type_check
             CHECK (
                 input_type IN (
                     'TEXT',
                     'NUMBER',
                     'DECIMAL',
                     'SELECT',
                     'RADIO',
                     'CHECKBOX',
                     'DATE',
                     'TEXTAREA',
                     'FILE'
                 )
             )"
        );
    }
};