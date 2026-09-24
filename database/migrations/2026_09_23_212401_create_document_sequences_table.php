<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table) {
            /*
            |--------------------------------------------------------------------------
            | Primary Key
            |--------------------------------------------------------------------------
            |
            | Internal database identifier for the sequence record.
            |
            */
            $table->id();

            /*
            |--------------------------------------------------------------------------
            | Sequence Type
            |--------------------------------------------------------------------------
            |
            | Identifies what kind of document this sequence belongs to.
            |
            | Examples:
            |
            | assessment
            | invoice
            | payment
            | receipt
            | certificate
            | permit
            |
            */
            $table->string('sequence_type', 100);

            /*
            |--------------------------------------------------------------------------
            | Year
            |--------------------------------------------------------------------------
            |
            | Each document type has an independent sequence for each year.
            |
            | Example:
            |
            | assessment / 2026
            | assessment / 2027
            | invoice    / 2026
            | invoice    / 2027
            |
            */
            $table->unsignedInteger('year');

            /*
            |--------------------------------------------------------------------------
            | Current Sequence Value
            |--------------------------------------------------------------------------
            |
            | Stores the last number that was issued.
            |
            | Initial:
            | 0
            |
            | First generated number:
            | 1
            |
            | Second generated number:
            | 2
            |
            */
            $table->unsignedBigInteger('current_value')
                ->default(0);

            /*
            |--------------------------------------------------------------------------
            | Timestamps
            |--------------------------------------------------------------------------
            */
            $table->timestamps();

            /*
            |--------------------------------------------------------------------------
            | Unique Sequence
            |--------------------------------------------------------------------------
            |
            | There can only be one sequence record for a specific
            | document type in a specific year.
            |
            | Example:
            |
            | assessment + 2026  -> allowed once
            | assessment + 2026  -> duplicate NOT allowed
            | invoice + 2026     -> allowed
            | assessment + 2027  -> allowed
            |
            */
            $table->unique(
                ['sequence_type', 'year'],
                'document_sequences_type_year_unique'
            );

            /*
            |--------------------------------------------------------------------------
            | Index
            |--------------------------------------------------------------------------
            |
            | Useful when looking up sequences by type.
            |
            */
            $table->index(
                'sequence_type',
                'document_sequences_type_index'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
    }
};
