<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remove redundant change fields from lease_amendments.
     *
     * Individual taxpayer and land-area changes are stored in
     * lease_amendment_changes.
     */
    public function up(): void
    {
        if (! Schema::hasTable('lease_amendments')) {
            return;
        }

        $columnsToRemove = [
            'previous_citizen_id',
            'new_citizen_id',
            'previous_land_area',
            'new_land_area',
            'measurement_unit_id',
        ];

        $existingColumns = array_values(array_filter(
            $columnsToRemove,
            fn (string $column): bool => Schema::hasColumn(
                'lease_amendments',
                $column
            )
        ));

        if ($existingColumns === []) {
            return;
        }

        /*
         * Remove foreign keys only for columns that still exist.
         */
        $foreignKeyColumns = [
            'previous_citizen_id',
            'new_citizen_id',
            'measurement_unit_id',
        ];

        $existingForeignKeyColumns = array_values(array_intersect(
            $existingColumns,
            $foreignKeyColumns
        ));

        Schema::table('lease_amendments', function (Blueprint $table) use (
            $existingForeignKeyColumns
        ) {
            foreach ($existingForeignKeyColumns as $column) {
                $table->dropForeign([$column]);
            }
        });

        /*
         * Remove redundant columns after preserving their data
         * and updating all application references.
         */
        Schema::table('lease_amendments', function (Blueprint $table) use (
            $existingColumns
        ) {
            $table->dropColumn($existingColumns);
        });
    }

    /**
     * Restore the removed columns.
     *
     * Original values cannot be reconstructed automatically.
     */
    public function down(): void
    {
        if (! Schema::hasTable('lease_amendments')) {
            return;
        }

        Schema::table('lease_amendments', function (Blueprint $table) {
            if (! Schema::hasColumn(
                'lease_amendments',
                'previous_citizen_id'
            )) {
                $table->foreignUuid('previous_citizen_id')
                    ->nullable()
                    ->constrained('citizens')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn(
                'lease_amendments',
                'new_citizen_id'
            )) {
                $table->foreignUuid('new_citizen_id')
                    ->nullable()
                    ->constrained('citizens')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn(
                'lease_amendments',
                'previous_land_area'
            )) {
                $table->decimal('previous_land_area', 15, 4)
                    ->nullable();
            }

            if (! Schema::hasColumn(
                'lease_amendments',
                'new_land_area'
            )) {
                $table->decimal('new_land_area', 15, 4)
                    ->nullable();
            }

            if (! Schema::hasColumn(
                'lease_amendments',
                'measurement_unit_id'
            )) {
                $table->foreignUuid('measurement_unit_id')
                    ->nullable()
                    ->constrained('measurement_units')
                    ->nullOnDelete();
            }
        });
    }
};
