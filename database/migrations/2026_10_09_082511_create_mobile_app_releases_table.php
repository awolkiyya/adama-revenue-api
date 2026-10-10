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
            $table->uuid('id')->primary();
            /*
            |--------------------------------------------------------------------------
            | Release Version
            |--------------------------------------------------------------------------
            */

            $table->string('version_name', 50);
            $table->unsignedBigInteger('version_code')->unique();

            /*
            |--------------------------------------------------------------------------
            | APK File
            |--------------------------------------------------------------------------
            */

            $table->string('apk_path');
            $table->unsignedBigInteger('apk_size')->nullable();
            $table->string('apk_sha256', 64)->nullable();

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
            | Indexes
            |--------------------------------------------------------------------------
            */

            $table->index(['status', 'is_latest']);
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
