<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcement_recipients', function (Blueprint $table): void {

            /*
            |--------------------------------------------------------------------------
            | Primary Key
            |--------------------------------------------------------------------------
            */

            $table->id();

            /*
            |--------------------------------------------------------------------------
            | Announcement Relationship
            |--------------------------------------------------------------------------
            |
            | announcements.id uses BIGINT.
            |
            */

            $table->foreignId('announcement_id')
                ->constrained('announcements')
                ->cascadeOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Recipient Relationship
            |--------------------------------------------------------------------------
            |
            | users.id uses UUID.
            |
            */

            $table->foreignUuid('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Read Status
            |--------------------------------------------------------------------------
            */

            $table->timestamp('read_at')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Timestamps
            |--------------------------------------------------------------------------
            */

            $table->timestamps();

            /*
            |--------------------------------------------------------------------------
            | Prevent Duplicate Recipients
            |--------------------------------------------------------------------------
            */

            $table->unique(
                ['announcement_id', 'user_id'],
                'announcement_recipients_unique'
            );

            /*
            |--------------------------------------------------------------------------
            | Recipient Inbox Index
            |--------------------------------------------------------------------------
            */

            $table->index(
                ['user_id', 'read_at'],
                'announcement_recipients_user_read_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_recipients');
    }
};
