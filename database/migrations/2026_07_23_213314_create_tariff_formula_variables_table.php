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
        Schema::create('tariff_formula_variables', function (Blueprint $table) {

            $table->uuid('id')->primary();
        
        
            /*
            |--------------------------------------------------------------------------
            | Tariff Rule
            |--------------------------------------------------------------------------
            */
        
            $table->foreignUuid('tariff_rule_id')
                ->constrained('tariff_rules')
                ->cascadeOnDelete();
        
        
        
            /*
            |--------------------------------------------------------------------------
            | Variable Identity
            |--------------------------------------------------------------------------
            */
        
            $table->string('code',100);
        
            $table->string('variable_name',100);
        
            $table->string('label',150);
        
        
        
            /*
            |--------------------------------------------------------------------------
            | Source
            |--------------------------------------------------------------------------
            */
        
            $table->enum('source_type',[
                'BASE_FIELD',
                'CONSTANT',
            ]);
        
        
        
            $table->foreignUuid('base_field_id')
                ->nullable()
                ->constrained('base_fields')
                ->nullOnDelete();
        
        
        
            $table->decimal(
                'default_value',
                15,
                4
            )
            ->nullable();
        
        
        
            /*
            |--------------------------------------------------------------------------
            | Type
            |--------------------------------------------------------------------------
            */
        
            $table->enum('data_type',[
                'NUMBER',
                'DECIMAL',
                'PERCENTAGE',
                'MONEY',
            ])
            ->default('DECIMAL');
        
        
        
            /*
            |--------------------------------------------------------------------------
            | Behaviour
            |--------------------------------------------------------------------------
            */
        
            $table->boolean('is_required')
                ->default(true);
        
        
            $table->unsignedInteger('sort_order')
                ->default(0);
        
        
        
            /*
            |--------------------------------------------------------------------------
            | System
            |--------------------------------------------------------------------------
            */
        
            $table->timestamps();
        
            $table->softDeletes();
        
        
        
            /*
            |--------------------------------------------------------------------------
            | Indexes
            |--------------------------------------------------------------------------
            */
        
            $table->unique([
                'tariff_rule_id',
                'variable_name'
            ]);
        
        
            $table->index('source_type');
        
            $table->index('base_field_id');
        
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tariff_formula_variables');
    }
};