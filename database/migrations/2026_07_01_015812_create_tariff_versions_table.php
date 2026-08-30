<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tariff_versions', function (Blueprint $table) {

            /**
             * -----------------------------------------------------------------
             * Primary Key
             * -----------------------------------------------------------------
             */
            $table->uuid('id')->primary();

            /**
             * -----------------------------------------------------------------
             * Tariff Information
             * -----------------------------------------------------------------
             */

            /**
             * Tariff year
             *
             * Example:
             * 2026
             * 2027
             */
            $table->unsignedInteger('year')->index();

            /**
             * Version number within the year.
             *
             * Example:
             * 2026 Version 1
             * 2026 Version 2 (Revised)
             */
            $table->unsignedSmallInteger('version')
                ->default(1);

            /**
             * Human-readable name.
             *
             * Examples:
             * 2026 Standard Tariff
             * 2026 Revised Tariff
             */
            $table->string('name', 150);

            /**
             * Optional description.
             */
            $table->text('description')
                ->nullable();

            /**
             * -----------------------------------------------------------------
             * Effective Dates
             * -----------------------------------------------------------------
             */

            /**
             * Date this tariff becomes effective.
             */
            $table->date('effective_from');

            /**
             * Date this tariff expires.
             *
             * NULL = Still valid.
             */
            $table->date('effective_to')
                ->nullable();

            /**
             * -----------------------------------------------------------------
             * Status
             * -----------------------------------------------------------------
             */

            /**
             * Indicates whether this tariff version
             * is currently active.
             *
             * NOTE:
             * Only one version should normally be active.
             * Enforce this in the service layer.
             */
            $table->boolean('is_active')
                ->default(false)
                ->index();

            /**
             * Indicates whether this tariff version
             * has been officially approved.
             */
            $table->boolean('is_approved')
                ->default(false)
                ->index();

            /**
             * Date approval was granted.
             */
            $table->timestamp('approved_at')
                ->nullable();

            /**
             * -----------------------------------------------------------------
             * Audit
             * -----------------------------------------------------------------
             */

            $table->timestamps();

            /**
             * Soft delete.
             */
            $table->softDeletes();

            /**
             * -----------------------------------------------------------------
             * Constraints
             * -----------------------------------------------------------------
             */

            /**
             * Prevent duplicate versions
             * within the same year.
             *
             * Example:
             * 2026 v1
             * 2026 v2
             */
            $table->unique([
                'year',
                'version',
            ]);

            /**
             * Improve searching.
             */
            $table->index([
                'year',
                'is_active',
            ]);

            $table->index([
                'effective_from',
                'effective_to',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tariff_versions');
    }
};