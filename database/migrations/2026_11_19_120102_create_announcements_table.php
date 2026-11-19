<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table): void {

            /*
            |--------------------------------------------------------------------------
            | Primary Key
            |--------------------------------------------------------------------------
            */

            $table->id();

            $table->uuid('uuid')->unique();

            /*
            |--------------------------------------------------------------------------
            | Announcement Content
            |--------------------------------------------------------------------------
            */

            $table->string('title');

            $table->text('message');

            /*
            |--------------------------------------------------------------------------
            | Announcement Priority
            |--------------------------------------------------------------------------
            */

            $table->string('priority', 20)->default('normal');

            /*
            |--------------------------------------------------------------------------
            | Target Audience
            |--------------------------------------------------------------------------
            */

            $table->string('target_type', 30)->default('all');

            $table->json('target_data')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Announcement Status
            |--------------------------------------------------------------------------
            */

            $table->string('status', 20)->default('draft');

            /*
            |--------------------------------------------------------------------------
            | Publication Lifecycle
            |--------------------------------------------------------------------------
            */

            $table->timestamp('published_at')->nullable();

            $table->timestamp('expires_at')->nullable();

            $table->timestamp('archived_at')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Announcement Creator
            |--------------------------------------------------------------------------
            |
            | users.id uses UUID, not BIGINT.
            |
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

            $table->index(
                ['status', 'published_at'],
                'announcements_status_published_index'
            );

            $table->index(
                'expires_at',
                'announcements_expires_index'
            );

            $table->index(
                'created_by',
                'announcements_created_by_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
