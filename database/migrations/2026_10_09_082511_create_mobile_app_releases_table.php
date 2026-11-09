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
        Schema::create('mobile_app_releases', function (Blueprint $table) {

            /*
            |--------------------------------------------------------------------------
            | Primary Key
            |--------------------------------------------------------------------------
            */

            $table->uuid('id')->primary();

            /*
            |--------------------------------------------------------------------------
            | Application
            |--------------------------------------------------------------------------
            */

            $table->string('app_type', 50)
                ->default('field_collector');

            /*
            |--------------------------------------------------------------------------
            | Release Version
            |--------------------------------------------------------------------------
            */

            $table->string('version_name', 50);

            $table->unsignedBigInteger('version_code');

            /*
            |--------------------------------------------------------------------------
            | Release Information
            |--------------------------------------------------------------------------
            */

            $table->text('release_notes')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Update Policy
            |--------------------------------------------------------------------------
            */

            $table->boolean('is_latest')->default(false);

            $table->boolean('is_mandatory')->default(false);

            /*
            |--------------------------------------------------------------------------
            | Release Status
            |--------------------------------------------------------------------------
            */

            $table->enum('status', [
                'draft',
                'published',
                'withdrawn',
            ])->default('draft');

            $table->timestamp('published_at')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Administrator
            |--------------------------------------------------------------------------
            */

            $table->foreignUuid('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Timestamps
            |--------------------------------------------------------------------------
            */

            $table->timestamps();

            /*
            |--------------------------------------------------------------------------
            | Indexes and Constraints
            |--------------------------------------------------------------------------
            */

            // Each application maintains independent version codes.
            $table->unique(
                ['app_type', 'version_code'],
                'mobile_releases_app_version_unique'
            );

            // Efficient application-specific release queries.
            $table->index(
                ['app_type', 'status', 'is_latest'],
                'mobile_releases_app_status_latest_index'
            );

            // Published release lookup.
            $table->index('published_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mobile_app_releases');
    }
};
