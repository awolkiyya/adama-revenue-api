<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('files', function (Blueprint $table) {

            /*
            |--------------------------------------------------------------------------
            | Primary Key
            |--------------------------------------------------------------------------
            |
            | Internal database identifier.
            |
            */

            $table->uuid('id')->primary();


            /*
            |--------------------------------------------------------------------------
            | Public Identifier
            |--------------------------------------------------------------------------
            |
            | Safe identifier for API responses and external references.
            |
            | Example:
            |
            | 8d7d4f5c-7b1f-4f6a-ae91-2e4b8c1d9a21
            |
            */

            $table->uuid('uuid')
                ->unique();


            /*
            |--------------------------------------------------------------------------
            | Polymorphic Owner
            |--------------------------------------------------------------------------
            |
            | Allows this table to be reused by the entire system.
            |
            | Examples:
            |
            | User
            | Plan
            | Report
            | Assessment
            | AssessmentService
            | BusinessLicense
            | DriverLicense
            |
            | fileable_id   = owner's UUID
            | fileable_type = model class/type
            |
            */

            $table->uuid('fileable_id')
                ->nullable();

            $table->string('fileable_type', 150)
                ->nullable();

            $table->index([
                'fileable_type',
                'fileable_id',
            ]);


            /*
            |--------------------------------------------------------------------------
            | File Collection
            |--------------------------------------------------------------------------
            |
            | Describes the business purpose of the attachment without
            | coupling this generic table to a specific module.
            |
            | Examples:
            |
            | avatar
            | attachment
            | evidence
            | ownership_document
            | identity_document
            | business_license
            | permit_document
            | receipt
            |
            */

            $table->string('collection', 100)
                ->default('attachment')
                ->index();


            /*
            |--------------------------------------------------------------------------
            | Original File Name
            |--------------------------------------------------------------------------
            |
            | The name supplied by the uploader.
            |
            */

            $table->string('original_name', 255);


            /*
            |--------------------------------------------------------------------------
            | Stored File Name
            |--------------------------------------------------------------------------
            |
            | Internal/generated filename.
            |
            | Do not expose this as the public identifier.
            |
            */

            $table->string('stored_name', 255);


            /*
            |--------------------------------------------------------------------------
            | Storage Path
            |--------------------------------------------------------------------------
            |
            | Relative path inside the configured filesystem disk.
            |
            | Example:
            |
            | assessments/2026/08/uuid/document.pdf
            |
            */

            $table->text('path');


            /*
            |--------------------------------------------------------------------------
            | Storage Disk
            |--------------------------------------------------------------------------
            |
            | Laravel filesystem disk.
            |
            | Examples:
            |
            | public
            | local
            | s3
            |
            */

            $table->string('disk', 50)
                ->default('public')
                ->index();


            /*
            |--------------------------------------------------------------------------
            | MIME Type
            |--------------------------------------------------------------------------
            |
            | Example:
            |
            | application/pdf
            | image/jpeg
            | image/png
            |
            */

            $table->string('mime_type', 150)
                ->nullable();


            /*
            |--------------------------------------------------------------------------
            | File Extension
            |--------------------------------------------------------------------------
            |
            | Example:
            |
            | pdf
            | jpg
            | png
            |
            */

            $table->string('extension', 20)
                ->nullable();


            /*
            |--------------------------------------------------------------------------
            | File Size
            |--------------------------------------------------------------------------
            |
            | Stored in bytes.
            |
            */

            $table->unsignedBigInteger('size_bytes')
                ->default(0);


            /*
            |--------------------------------------------------------------------------
            | File Checksum
            |--------------------------------------------------------------------------
            |
            | SHA-256 checksum is recommended.
            |
            | Used for:
            |
            | - Integrity verification
            | - Duplicate detection
            | - Security auditing
            |
            */

            $table->string('checksum', 64)
                ->nullable();


            /*
            |--------------------------------------------------------------------------
            | Uploaded By
            |--------------------------------------------------------------------------
            |
            | User who uploaded the file.
            |
            */

            $table->foreignUuid('uploaded_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();


            /*
            |--------------------------------------------------------------------------
            | Upload Source
            |--------------------------------------------------------------------------
            |
            | Identifies how the file entered the system.
            |
            */

            $table->enum('source', [
                'USER',
                'OFFICER',
                'IMPORT',
                'EXTERNAL_SYSTEM',
                'SYSTEM',
            ])
            ->default('USER')
            ->index();


            /*
            |--------------------------------------------------------------------------
            | Visibility
            |--------------------------------------------------------------------------
            |
            | PUBLIC
            |   Can be accessed through a public URL.
            |
            | PRIVATE
            |   Requires authorization.
            |
            */

            $table->enum('visibility', [
                'PUBLIC',
                'PRIVATE',
            ])
            ->default('PRIVATE')
            ->index();


            /*
            |--------------------------------------------------------------------------
            | Processing Status
            |--------------------------------------------------------------------------
            |
            | PENDING
            |   File record created/upload processing is not complete.
            |
            | READY
            |   File is available and usable.
            |
            | FAILED
            |   File processing failed.
            |
            | REJECTED
            |   File was rejected by validation/moderation.
            |
            */

            $table->enum('status', [
                'PENDING',
                'READY',
                'FAILED',
                'REJECTED',
            ])
            ->default('PENDING')
            ->index();


            /*
            |--------------------------------------------------------------------------
            | Uploaded At
            |--------------------------------------------------------------------------
            */

            $table->timestamp('uploaded_at')
                ->nullable();


            /*
            |--------------------------------------------------------------------------
            | Extra Metadata
            |--------------------------------------------------------------------------
            |
            | Module-specific information can be stored here without
            | coupling the generic file table to a particular domain.
            |
            | Example:
            |
            | {
            |     "document_type": "OWNERSHIP_CERTIFICATE",
            |     "document_number": "DOC-12345",
            |     "issued_date": "2026-01-10"
            | }
            |
            */

            $table->json('metadata')
                ->nullable();


            /*
            |--------------------------------------------------------------------------
            | Audit
            |--------------------------------------------------------------------------
            */

            $table->timestamps();

            $table->softDeletes();


            /*
            |--------------------------------------------------------------------------
            | Storage Uniqueness
            |--------------------------------------------------------------------------
            |
            | The same stored filename may theoretically exist on different
            | disks, therefore uniqueness is enforced using disk + path.
            |
            */

            $table->unique([
                'disk',
                'path',
            ]);


            /*
            |--------------------------------------------------------------------------
            | Performance Indexes
            |--------------------------------------------------------------------------
            */

            $table->index([
                'uploaded_by',
                'created_at',
            ]);

            $table->index([
                'collection',
                'status',
            ]);

            $table->index([
                'fileable_type',
                'collection',
                'created_at',
            ]);

            $table->index('checksum');
        });
    }


    public function down(): void
    {
        Schema::dropIfExists('files');
    }
};