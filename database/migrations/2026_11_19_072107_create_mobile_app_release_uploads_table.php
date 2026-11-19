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
        Schema::create('mobile_app_release_uploads', function (Blueprint $table) {
            $table->id();

            // Unique identifier exposed to the client.
            $table->uuid('uuid')->unique();

            // User who initiated the upload.
            $table->foreignUuid('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Application type: taxpayer or field_collector.
            $table->string('app_type', 50);

            // Original APK filename.
            $table->string('original_name');

            // Upload size and chunk configuration.
            $table->unsignedBigInteger('total_size');
            $table->unsignedInteger('chunk_size');
            $table->unsignedInteger('total_chunks');

            // Zero-based indexes of successfully uploaded chunks.
            // Example: [0, 1, 2, 3]
            $table->json('uploaded_chunks')->nullable();

            // UPLOADING, ASSEMBLING, COMPLETED, FAILED, EXPIRED.
            $table->string('status', 30)->default('UPLOADING');

            // Storage configuration.
            $table->string('disk', 100)->default('local');

            // Relative path of the assembled APK after completion.
            $table->string('assembled_path')->nullable();

            // Optional SHA-256 checksum supplied by the client.
            $table->char('expected_checksum', 64)->nullable();

            // Actual SHA-256 checksum after assembly.
            $table->char('checksum', 64)->nullable();

            // Error details for failed uploads.
            $table->text('failure_reason')->nullable();

            // Automatically expire abandoned upload sessions.
            $table->timestamp('expires_at')->nullable();

            // When the upload was successfully finalized.
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            // Query optimization.
            $table->index(['user_id', 'status']);
            $table->index(['app_type', 'status']);
            $table->index('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mobile_app_release_uploads');
    }
};
