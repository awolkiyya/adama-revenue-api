<?php

namespace App\Services\Storage;

use App\Models\File;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;

class StorageService
{
    /**
     * Storage disk used by the application.
     */
    protected string $disk;

    /**
     * Default signed URL lifetime.
     */
    protected int $temporaryUrlMinutes;

    public function __construct()
    {
        $this->disk = config(
            'filesystems.default',
            'local'
        );

        $this->temporaryUrlMinutes = (int) config(
            'storage.temporary_url_minutes',
            10
        );
    }

    /*
    |--------------------------------------------------------------------------
    | UPLOAD FILE
    |--------------------------------------------------------------------------
    |
    | Stores an UploadedFile and creates the corresponding File registry
    | record.
    |
    | The physical file is stored first.
    | The database record is created second.
    |
    | If database creation fails, the physical file is removed so that
    | orphaned storage objects are not left behind.
    |
    */

    public function upload(
        UploadedFile $uploadedFile,
        string $folder,
        ?string $uploadedBy = null,
        string $category = 'GENERAL',
        string $visibility = 'private'
    ): File {

        $this->validateVisibility($visibility);

        $disk = Storage::disk($this->disk);

        $extension = strtolower(
            $uploadedFile->extension()
                ?: $uploadedFile->getClientOriginalExtension()
        );

        $extension = $this->sanitizeExtension(
            $extension
        );

        $filename =
            (string) Str::uuid()
            . ($extension !== ''
                ? ".{$extension}"
                : '');

        $datePath = now()->format('Y/m/d');

        $directory = $this->normalizePath(
            "{$folder}/{$datePath}"
        );

        $path = null;

        try {

            /*
            |--------------------------------------------------------------------------
            | Calculate hash before moving the uploaded file.
            |--------------------------------------------------------------------------
            */

            $hash = hash_file(
                'sha256',
                $uploadedFile->getRealPath()
            );

            if ($hash === false) {

                throw new RuntimeException(
                    'Unable to calculate file hash.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Store physical file.
            |--------------------------------------------------------------------------
            */

            $path = $uploadedFile->storeAs(
                $directory,
                $filename,
                $this->disk
            );

            if (!$path) {

                throw new RuntimeException(
                    'Unable to store uploaded file.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Create file registry.
            |--------------------------------------------------------------------------
            */

            return File::create([

                /*
                |--------------------------------------------------------------------------
                | Identity
                |--------------------------------------------------------------------------
                */

                'uuid' =>
                    (string) Str::uuid(),

                /*
                |--------------------------------------------------------------------------
                | File information
                |--------------------------------------------------------------------------
                */

                'original_name' =>
                    $this->sanitizeOriginalName(
                        $uploadedFile->getClientOriginalName()
                    ),

                'stored_name' =>
                    $filename,

                'path' =>
                    $path,

                /*
                |--------------------------------------------------------------------------
                | Storage
                |--------------------------------------------------------------------------
                */

                'disk' =>
                    $this->disk,

                /*
                |--------------------------------------------------------------------------
                | Metadata
                |--------------------------------------------------------------------------
                */

                'mime_type' =>
                    $uploadedFile->getMimeType(),

                'extension' =>
                    $extension !== ''
                        ? $extension
                        : null,

                'size' =>
                    $uploadedFile->getSize(),

                /*
                |--------------------------------------------------------------------------
                | Business
                |--------------------------------------------------------------------------
                */

                'category' =>
                    strtoupper(
                        trim($category)
                    ),

                /*
                |--------------------------------------------------------------------------
                | Security
                |--------------------------------------------------------------------------
                */

                'hash' =>
                    $hash,

                /*
                |--------------------------------------------------------------------------
                | Ownership
                |--------------------------------------------------------------------------
                */

                'uploaded_by' =>
                    $uploadedBy,

                /*
                |--------------------------------------------------------------------------
                | Access
                |--------------------------------------------------------------------------
                */

                'is_public' =>
                    $visibility === 'public',

                /*
                |--------------------------------------------------------------------------
                | Lifecycle
                |--------------------------------------------------------------------------
                |
                | A successfully stored file is READY.
                |
                */

                'status' =>
                    'READY',
            ]);

        } catch (Exception $e) {

            /*
            |--------------------------------------------------------------------------
            | Compensating cleanup
            |--------------------------------------------------------------------------
            |
            | Storage is not part of the database transaction.
            | If DB creation fails, remove the physical file.
            |
            */

            if ($path !== null) {

                try {

                    $disk->delete($path);

                } catch (Exception $cleanupException) {

                    report(
                        $cleanupException
                    );
                }
            }

            report($e);

            throw $e;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | STORE RAW CONTENT
    |--------------------------------------------------------------------------
    |
    | Used for generated PDFs, reports, exports, CSV files, etc.
    |
    */

    public function storeRaw(
        string $content,
        string $folder,
        string $filename,
        string $mimeType,
        string $extension,
        string $category = 'GENERAL',
        string $visibility = 'private',
        ?string $uploadedBy = null
    ): File {

        $this->validateVisibility(
            $visibility
        );

        $extension = $this->sanitizeExtension(
            $extension
        );

        $filename = $this->sanitizeStoredFilename(
            $filename,
            $extension
        );

        $datePath = now()->format(
            'Y/m/d'
        );

        $directory = $this->normalizePath(
            "{$folder}/{$datePath}"
        );

        $path =
            $directory .
            '/' .
            $filename;

        $disk = Storage::disk(
            $this->disk
        );

        try {

            /*
            |--------------------------------------------------------------------------
            | Calculate hash.
            |--------------------------------------------------------------------------
            */

            $hash = hash(
                'sha256',
                $content
            );

            /*
            |--------------------------------------------------------------------------
            | Store content.
            |--------------------------------------------------------------------------
            */

            $stored = $disk->put(
                $path,
                $content
            );

            if (!$stored) {

                throw new RuntimeException(
                    'Unable to store raw file content.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Determine actual size from storage.
            |--------------------------------------------------------------------------
            */

            $size = $disk->size(
                $path
            );

            /*
            |--------------------------------------------------------------------------
            | Create registry.
            |--------------------------------------------------------------------------
            */

            return File::create([

                'uuid' =>
                    (string) Str::uuid(),

                'original_name' =>
                    $filename,

                'stored_name' =>
                    $filename,

                'path' =>
                    $path,

                'disk' =>
                    $this->disk,

                'mime_type' =>
                    $mimeType,

                'extension' =>
                    $extension !== ''
                        ? $extension
                        : null,

                'size' =>
                    $size,

                'category' =>
                    strtoupper(
                        trim($category)
                    ),

                'hash' =>
                    $hash,

                'uploaded_by' =>
                    $uploadedBy,

                'is_public' =>
                    $visibility === 'public',

                'status' =>
                    'READY',
            ]);

        } catch (Exception $e) {

            /*
            |--------------------------------------------------------------------------
            | Cleanup physical object if DB creation fails.
            |--------------------------------------------------------------------------
            */

            try {

                if ($disk->exists($path)) {

                    $disk->delete($path);
                }

            } catch (Exception $cleanupException) {

                report(
                    $cleanupException
                );
            }

            report($e);

            throw $e;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | DELETE FILE
    |--------------------------------------------------------------------------
    |
    | Deletes both the physical object and registry record.
    |
    */

    public function delete(
        ?File $file
    ): void {

        if (!$file) {
            return;
        }

        try {

            Storage::disk(
                $file->disk
            )->delete(
                $file->path
            );

            $file->delete();

        } catch (Exception $e) {

            report($e);

            throw $e;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | DELETE PHYSICAL FILE ONLY
    |--------------------------------------------------------------------------
    |
    | Useful when you need storage cleanup without deleting the DB record.
    |
    */

    public function deletePhysical(
        ?File $file
    ): void {

        if (!$file) {
            return;
        }

        try {

            Storage::disk(
                $file->disk
            )->delete(
                $file->path
            );

        } catch (Exception $e) {

            report($e);

            throw $e;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | FILE URL
    |--------------------------------------------------------------------------
    */

    public function url(
        ?File $file
    ): ?string {

        if (!$file) {
            return null;
        }

        if (
            $file->status !== 'READY'
        ) {

            return null;
        }

        if ($file->is_public) {

            return Storage::disk(
                $file->disk
            )->url(
                $file->path
            );
        }

        return $this->temporaryUrl(
            $file
        );
    }

    /*
    |--------------------------------------------------------------------------
    | TEMPORARY PRIVATE URL
    |--------------------------------------------------------------------------
    */

    public function temporaryUrl(
        File $file,
        ?int $minutes = null
    ): ?string {

        if (!$file) {
            return null;
        }

        if (
            $file->status !== 'READY'
        ) {

            return null;
        }

        $minutes ??=
            $this->temporaryUrlMinutes;

        return URL::temporarySignedRoute(
            'files.private',
            now()->addMinutes(
                max(1, $minutes)
            ),
            [
                'uuid' => $file->uuid,
            ]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | FIND FILE
    |--------------------------------------------------------------------------
    |
    | Accepts either:
    |
    | - public UUID
    | - database primary key
    |
    */

    public function find(
        string $reference
    ): ?File {

        $reference = trim(
            $reference
        );

        if ($reference === '') {
            return null;
        }

        return File::query()
            ->where(
                'uuid',
                $reference
            )
            ->orWhere(
                'id',
                $reference
            )
            ->first();
    }

    /*
    |--------------------------------------------------------------------------
    | FIND FILE OR FAIL
    |--------------------------------------------------------------------------
    */

    public function findOrFail(
        string $reference
    ): File {

        $file = $this->find(
            $reference
        );

        if (!$file) {

            throw new RuntimeException(
                "File [{$reference}] was not found."
            );
        }

        return $file;
    }

    /*
    |--------------------------------------------------------------------------
    | ATTACH FILE TO MODEL
    |--------------------------------------------------------------------------
    |
    | Generic polymorphic attachment.
    |
    | Example:
    |
    | $storage->attachToModel(
    |     $file,
    |     $assessmentValue
    | );
    |
    */

    public function attachToModel(
        File $file,
        Model $model
    ): File {

        /*
        |--------------------------------------------------------------------------
        | File must be ready.
        |--------------------------------------------------------------------------
        */

        if (
            strtoupper(
                (string) $file->status
            ) !== 'READY'
        ) {

            throw new RuntimeException(
                "File [{$file->uuid}] is not ready for attachment."
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Already attached somewhere.
        |--------------------------------------------------------------------------
        */

        if (
            $file->fileable_id !== null ||
            $file->fileable_type !== null
        ) {

            $sameOwner =
                $file->fileable_type ===
                    $model->getMorphClass()
                &&
                (string) $file->fileable_id ===
                    (string) $model->getKey();

            if (!$sameOwner) {

                throw new RuntimeException(
                    "File [{$file->uuid}] is already attached to another record."
                );
            }

            return $file;
        }

        /*
        |--------------------------------------------------------------------------
        | Attach.
        |--------------------------------------------------------------------------
        */

        $file->fileable()->associate(
            $model
        );

        $file->save();

        return $file->refresh();
    }

    /*
    |--------------------------------------------------------------------------
    | ATTACH BY REFERENCE
    |--------------------------------------------------------------------------
    */

    public function attachReferenceToModel(
        string $reference,
        Model $model
    ): File {

        $file = $this->findOrFail(
            $reference
        );

        return $this->attachToModel(
            $file,
            $model
        );
    }

    /*
    |--------------------------------------------------------------------------
    | DETACH FILES FROM MODEL
    |--------------------------------------------------------------------------
    |
    | Physical files are intentionally NOT deleted.
    |
    */

    public function detachFromModel(
        Model $model
    ): int {

        return File::query()
            ->where(
                'fileable_type',
                $model->getMorphClass()
            )
            ->where(
                'fileable_id',
                $model->getKey()
            )
            ->update([
                'fileable_type' => null,
                'fileable_id' => null,
            ]);
    }

    /*
    |--------------------------------------------------------------------------
    | DETACH SINGLE FILE
    |--------------------------------------------------------------------------
    */

    public function detach(
        File $file
    ): void {

        $file->fileable_id = null;
        $file->fileable_type = null;

        $file->save();
    }

    /*
    |--------------------------------------------------------------------------
    | VALIDATE VISIBILITY
    |--------------------------------------------------------------------------
    */

    protected function validateVisibility(
        string $visibility
    ): void {

        if (
            !in_array(
                $visibility,
                [
                    'public',
                    'private',
                ],
                true
            )
        ) {

            throw new RuntimeException(
                'File visibility must be either public or private.'
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | NORMALIZE PATH
    |--------------------------------------------------------------------------
    */

    protected function normalizePath(
        string $path
    ): string {

        $path = str_replace(
            '\\',
            '/',
            $path
        );

        $path = trim(
            $path,
            '/'
        );

        /*
        |--------------------------------------------------------------------------
        | Prevent path traversal.
        |--------------------------------------------------------------------------
        */

        if (
            str_contains($path, '..')
        ) {

            throw new RuntimeException(
                'Invalid storage path.'
            );
        }

        return $path;
    }

    /*
    |--------------------------------------------------------------------------
    | SANITIZE EXTENSION
    |--------------------------------------------------------------------------
    */

    protected function sanitizeExtension(
        string $extension
    ): string {

        $extension = strtolower(
            trim($extension)
        );

        return preg_replace(
            '/[^a-z0-9]/',
            '',
            $extension
        ) ?? '';
    }

    /*
    |--------------------------------------------------------------------------
    | SANITIZE ORIGINAL NAME
    |--------------------------------------------------------------------------
    |
    | Original filename is metadata only.
    | Never use it as the physical storage filename.
    |
    */

    protected function sanitizeOriginalName(
        string $filename
    ): string {

        $filename = trim(
            $filename
        );

        $filename = str_replace(
            [
                "\0",
                "\r",
                "\n",
            ],
            '',
            $filename
        );

        return mb_substr(
            $filename,
            0,
            255
        );
    }

    /*
    |--------------------------------------------------------------------------
    | SANITIZE STORED FILENAME
    |--------------------------------------------------------------------------
    */

    protected function sanitizeStoredFilename(
        string $filename,
        string $extension
    ): string {

        $filename = basename(
            trim($filename)
        );

        $filename = preg_replace(
            '/[^A-Za-z0-9._-]/',
            '_',
            $filename
        ) ?? '';

        /*
        |--------------------------------------------------------------------------
        | Ensure extension is correct.
        |--------------------------------------------------------------------------
        */

        if (
            $extension !== '' &&
            !str_ends_with(
                strtolower($filename),
                ".{$extension}"
            )
        ) {

            $filename .= ".{$extension}";
        }

        if ($filename === '') {

            $filename =
                (string) Str::uuid()
                . (
                    $extension !== ''
                        ? ".{$extension}"
                        : ''
                );
        }

        return $filename;
    }
}