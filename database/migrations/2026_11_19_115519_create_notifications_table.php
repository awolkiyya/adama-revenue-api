<?php

declare(strict_types=1);

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
        Schema::create('notifications', function (Blueprint $table): void {
            /*
            |--------------------------------------------------------------------------
            | Primary Key
            |--------------------------------------------------------------------------
            */

            $table->uuid('id')->primary();

            /*
            |--------------------------------------------------------------------------
            | Notification Type
            |--------------------------------------------------------------------------
            */

            $table->string('type');

            /*
            |--------------------------------------------------------------------------
            | Notification Recipient
            |--------------------------------------------------------------------------
            |
            | Uses UUID because users.id is UUID.
            | Supports polymorphic notification recipients.
            |
            */

            $table->uuidMorphs('notifiable');

            /*
            |--------------------------------------------------------------------------
            | Notification Payload
            |--------------------------------------------------------------------------
            */

            $table->json('data');

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
            | Notification Inbox Index
            |--------------------------------------------------------------------------
            */

            $table->index(
                ['notifiable_type', 'notifiable_id', 'read_at'],
                'notifications_notifiable_read_index'
            );

            /*
            |--------------------------------------------------------------------------
            | Notification Ordering Index
            |--------------------------------------------------------------------------
            */

            $table->index(
                ['notifiable_type', 'notifiable_id', 'created_at'],
                'notifications_notifiable_created_index'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
