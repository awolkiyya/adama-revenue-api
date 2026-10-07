<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lease_amendments', function (Blueprint $table) {

            /*
            |--------------------------------------------------------------------------
            | Amendment Status
            |--------------------------------------------------------------------------
            |
            | DRAFT
            |   Amendment is being prepared.
            |
            | PENDING_APPROVAL
            |   Amendment has been submitted for approval.
            |
            | APPROVED
            |   Amendment has been approved but not yet applied.
            |
            | REJECTED
            |   Amendment was rejected.
            |
            | CANCELLED
            |   Amendment was cancelled.
            |
            | APPLIED
            |   Amendment has been applied and the independent
            |   replacement assessment has been created/linked.
            |
            */

            $table->enum('status_new', [
                'DRAFT',
                'PENDING_APPROVAL',
                'APPROVED',
                'REJECTED',
                'CANCELLED',
                'APPLIED',
            ])
                ->default('DRAFT')
                ->after('reason');


            /*
            |--------------------------------------------------------------------------
            | Applied Information
            |--------------------------------------------------------------------------
            */

            $table->foreignUuid('applied_by')
                ->nullable()
                ->after('rejected_at')
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('applied_at')
                ->nullable()
                ->after('applied_by');


            /*
            |--------------------------------------------------------------------------
            | Replace Old Status
            |--------------------------------------------------------------------------
            |
            | The old status column is replaced with the new lifecycle.
            |
            */

            $table->index(
                ['status_new'],
                'lease_amendments_status_new_index'
            );
        });


        /*
        |--------------------------------------------------------------------------
        | Remove Existing Status
        |--------------------------------------------------------------------------
        */

        Schema::table('lease_amendments', function (Blueprint $table) {

            // Drop the old status index if it exists in your schema.
            $table->dropIndex(
                'lease_amendments_status_index'
            );

            $table->dropColumn('status');
        });


        /*
        |--------------------------------------------------------------------------
        | Rename New Status
        |--------------------------------------------------------------------------
        */

        Schema::table('lease_amendments', function (Blueprint $table) {

            $table->renameColumn(
                'status_new',
                'status'
            );
        });
    }

    public function down(): void
    {
        Schema::table('lease_amendments', function (Blueprint $table) {

            /*
            |--------------------------------------------------------------------------
            | Restore Original Status
            |--------------------------------------------------------------------------
            */

            $table->enum('status_old', [
                'DRAFT',
                'PENDING_APPROVAL',
                'APPROVED',
                'REJECTED',
                'CANCELLED',
            ])
                ->default('DRAFT')
                ->after('reason');
        });


        Schema::table('lease_amendments', function (Blueprint $table) {

            $table->dropIndex(
                'lease_amendments_status_new_index'
            );

            $table->dropColumn('status');

            $table->renameColumn(
                'status_old',
                'status'
            );


            /*
            |--------------------------------------------------------------------------
            | Remove Applied Information
            |--------------------------------------------------------------------------
            */

            $table->dropForeign([
                'applied_by',
            ]);

            $table->dropColumn([
                'applied_by',
                'applied_at',
            ]);
        });
    }
};