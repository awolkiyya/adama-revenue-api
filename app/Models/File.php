<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class File extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    /*
    |--------------------------------------------------------------------------
    | Table
    |--------------------------------------------------------------------------
    */

    protected $table = 'files';

    /*
    |--------------------------------------------------------------------------
    | Primary Key
    |--------------------------------------------------------------------------
    */

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    /*
    |--------------------------------------------------------------------------
    | Mass Assignment
    |--------------------------------------------------------------------------
    */

    protected $fillable = [

        'id',

        'uuid',

        'fileable_id',
        'fileable_type',

        'collection',

        'original_name',
        'stored_name',
        'path',

        'disk',

        'mime_type',
        'extension',

        'size_bytes',

        'checksum',

        'uploaded_by',

        'source',

        'visibility',

        'status',

        'uploaded_at',

        'metadata',
    ];

    /*
    |--------------------------------------------------------------------------
    | Casts
    |--------------------------------------------------------------------------
    */

    protected function casts(): array
    {
        return [

            'size_bytes' =>
                'integer',

            'uploaded_at' =>
                'datetime',

            'metadata' =>
                'array',

            'created_at' =>
                'datetime',

            'updated_at' =>
                'datetime',

            'deleted_at' =>
                'datetime',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | UUID
    |--------------------------------------------------------------------------
    */

    public function uniqueIds(): array
    {
        return [
            'id',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Polymorphic Owner
    |--------------------------------------------------------------------------
    */

    public function fileable(): MorphTo
    {
        return $this->morphTo();
    }

    /*
    |--------------------------------------------------------------------------
    | Uploaded By
    |--------------------------------------------------------------------------
    */

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'uploaded_by'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeReady(
        Builder $query
    ): Builder {

        return $query->where(
            'status',
            'READY'
        );
    }

    public function scopePending(
        Builder $query
    ): Builder {

        return $query->where(
            'status',
            'PENDING'
        );
    }

    public function scopeFailed(
        Builder $query
    ): Builder {

        return $query->where(
            'status',
            'FAILED'
        );
    }

    public function scopeRejected(
        Builder $query
    ): Builder {

        return $query->where(
            'status',
            'REJECTED'
        );
    }

    public function scopePublic(
        Builder $query
    ): Builder {

        return $query->where(
            'visibility',
            'PUBLIC'
        );
    }

    public function scopePrivate(
        Builder $query
    ): Builder {

        return $query->where(
            'visibility',
            'PRIVATE'
        );
    }

    public function scopeByCollection(
        Builder $query,
        string $collection
    ): Builder {

        return $query->where(
            'collection',
            $collection
        );
    }

    public function scopeUploadedBy(
        Builder $query,
        string $userId
    ): Builder {

        return $query->where(
            'uploaded_by',
            $userId
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    public function isReady(): bool
    {
        return $this->status === 'READY';
    }

    public function isPending(): bool
    {
        return $this->status === 'PENDING';
    }

    public function isFailed(): bool
    {
        return $this->status === 'FAILED';
    }

    public function isRejected(): bool
    {
        return $this->status === 'REJECTED';
    }

    public function isPublic(): bool
    {
        return $this->visibility === 'PUBLIC';
    }

    public function isPrivate(): bool
    {
        return $this->visibility === 'PRIVATE';
    }

    public function getSizeInKbAttribute(): float
    {
        return round(
            $this->size_bytes / 1024,
            2
        );
    }

    public function getSizeInMbAttribute(): float
    {
        return round(
            $this->size_bytes / 1024 / 1024,
            2
        );
    }
}