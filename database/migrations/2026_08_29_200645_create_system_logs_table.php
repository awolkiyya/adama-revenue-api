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
        Schema::create('system_logs', function (Blueprint $table) {

            /*
            |--------------------------------------------------------------------------
            | PRIMARY KEY
            |--------------------------------------------------------------------------
            |
            | The system log primary key can remain BIGINT.
            |
            */

            $table->id();


            /*
            |--------------------------------------------------------------------------
            | ACTOR
            |--------------------------------------------------------------------------
            |
            | The authenticated user who performed the action.
            |
            | users.id is UUID, therefore user_id MUST also be UUID.
            |
            | Nullable because some events can happen without an
            | authenticated user, for example:
            |
            | - Scheduled jobs
            | - Queue workers
            | - System processes
            | - Automated tasks
            |
            */

            $table->foreignUuid('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();


            /*
            |--------------------------------------------------------------------------
            | ACTION
            |--------------------------------------------------------------------------
            |
            | Examples:
            |
            | LOGIN
            | LOGOUT
            | CREATE
            | UPDATE
            | DELETE
            | VIEW
            | EXPORT
            | APPROVE
            | REJECT
            | PASSWORD_CHANGED
            | STATUS_CHANGED
            | PERMISSION_DENIED
            |
            */

            $table->string('action', 100);


            /*
            |--------------------------------------------------------------------------
            | MODULE
            |--------------------------------------------------------------------------
            |
            | Examples:
            |
            | Users
            | Administrative
            | Revenue
            | Tariff
            | Invoice
            | Payment
            | Reports
            | Authentication
            |
            */

            $table->string('module', 100)->nullable();


            /*
            |--------------------------------------------------------------------------
            | RESOURCE
            |--------------------------------------------------------------------------
            |
            | Polymorphic-style resource identification.
            |
            | Example:
            |
            | resource_type = App\Models\User
            | resource_id   = UUID
            |
            | resource_id is intentionally stored as string because
            | different resources may use different identifier types.
            |
            */

            $table->string('resource_type', 255)->nullable();

            $table->string('resource_id', 255)->nullable();


            /*
            |--------------------------------------------------------------------------
            | DESCRIPTION
            |--------------------------------------------------------------------------
            |
            | Human-readable description of the event.
            |
            */

            $table->text('description')->nullable();


            /*
            |--------------------------------------------------------------------------
            | OLD VALUES
            |--------------------------------------------------------------------------
            |
            | Stores values before an UPDATE/DELETE operation.
            |
            */

            $table->json('old_values')->nullable();


            /*
            |--------------------------------------------------------------------------
            | NEW VALUES
            |--------------------------------------------------------------------------
            |
            | Stores values after a CREATE/UPDATE operation.
            |
            */

            $table->json('new_values')->nullable();


            /*
            |--------------------------------------------------------------------------
            | REQUEST INFORMATION
            |--------------------------------------------------------------------------
            */

            $table->uuid('request_id')->nullable();

            $table->ipAddress('ip_address')->nullable();

            $table->text('user_agent')->nullable();


            /*
            |--------------------------------------------------------------------------
            | EXTRA CONTEXT
            |--------------------------------------------------------------------------
            |
            | Flexible metadata for additional information.
            |
            | Example:
            |
            | {
            |     "endpoint": "/api/v1/users",
            |     "method": "PUT",
            |     "reason": "Administrative transfer"
            | }
            |
            */

            $table->json('metadata')->nullable();


            /*
            |--------------------------------------------------------------------------
            | TIMESTAMP
            |--------------------------------------------------------------------------
            */

            $table->timestamp('created_at')->useCurrent();


            /*
            |--------------------------------------------------------------------------
            | INDEXES
            |--------------------------------------------------------------------------
            |
            | Audit logs will normally be queried by:
            |
            | - user
            | - action
            | - module
            | - resource
            | - request
            | - date
            |
            */

            $table->index('action');

            $table->index('module');

            $table->index([
                'resource_type',
                'resource_id',
            ]);

            $table->index('request_id');

            $table->index('created_at');

            $table->index([
                'user_id',
                'created_at',
            ]);
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_logs');
    }
};
