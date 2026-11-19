<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MobileAppReleaseUpload extends Model
{
    /*
    |--------------------------------------------------------------------------
    | Mass assignment
    |--------------------------------------------------------------------------
    */

    protected $fillable = [
        'uuid',
        'user_id',
        'app_type',
        'original_name',
        'total_size',
        'chunk_size',
        'total_chunks',
        'uploaded_chunks',
        'status',
        'disk',
        'assembled_path',
        'expected_checksum',
        'checksum',
        'failure_reason',
        'expires_at',
        'completed_at',
    ];

    /*
    |--------------------------------------------------------------------------
    | Attribute casting
    |--------------------------------------------------------------------------
    */

    protected function casts(): array
    {
        return [
            'total_size' => 'integer',
            'chunk_size' => 'integer',
            'total_chunks' => 'integer',
            'uploaded_chunks' => 'array',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Status constants
    |--------------------------------------------------------------------------
    */

    public const STATUS_UPLOADING = 'UPLOADING';

    public const STATUS_ASSEMBLING = 'ASSEMBLING';

    public const STATUS_COMPLETED = 'COMPLETED';

    public const STATUS_FAILED = 'FAILED';

    public const STATUS_EXPIRED = 'EXPIRED';

    public const STATUS_CONSUMED = 'CONSUMED';

    /*
    |--------------------------------------------------------------------------
    | Application type constants
    |--------------------------------------------------------------------------
    */

    public const APP_TYPE_TAXPAYER = 'taxpayer';

    public const APP_TYPE_FIELD_COLLECTOR = 'field_collector';

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * User who initiated this APK upload.
     *
     * The users table uses UUID primary keys.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'user_id',
            'id',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Query scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Scope to uploads currently accepting chunks.
     */
    public function scopeUploading(Builder $query): Builder
    {
        return $query->where(
            'status',
            self::STATUS_UPLOADING,
        );
    }

    /**
     * Scope to expired, unfinished uploads.
     */
    public function scopeExpired(Builder $query): Builder
    {
        return $query
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->whereIn('status', [
                self::STATUS_UPLOADING,
                self::STATUS_FAILED,
            ]);
    }

    /**
     * Scope uploads belonging to a specific UUID user.
     */
    public function scopeOwnedBy(
        Builder $query,
        string $userId,
    ): Builder {
        return $query->where('user_id', $userId);
    }

    /*
    |--------------------------------------------------------------------------
    | Status helpers
    |--------------------------------------------------------------------------
    */

    public function isUploading(): bool
    {
        return $this->status === self::STATUS_UPLOADING;
    }

    public function isAssembling(): bool
    {
        return $this->status === self::STATUS_ASSEMBLING;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isExpired(): bool
    {
        return $this->status === self::STATUS_EXPIRED
            || (
                $this->expires_at !== null
                && $this->expires_at->isPast()
            );
    }

    public function isConsumed(): bool
    {
        return $this->status === self::STATUS_CONSUMED;
    }

    /**
     * Check whether the upload has reached a terminal state.
     */
    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
            self::STATUS_EXPIRED,
            self::STATUS_CONSUMED,
        ], true);
    }

    /*
    |--------------------------------------------------------------------------
    | Chunk helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Return successfully uploaded zero-based chunk indexes.
     *
     * @return array<int, int>
     */
    public function getUploadedChunkIndexes(): array
    {
        return array_values(array_unique(array_map(
            'intval',
            $this->uploaded_chunks ?? [],
        )));
    }

    /**
     * Check whether a specific chunk has been uploaded.
     */
    public function hasUploadedChunk(int $chunkIndex): bool
    {
        return in_array(
            $chunkIndex,
            $this->getUploadedChunkIndexes(),
            true,
        );
    }

    /**
     * Return the number of successfully uploaded chunks.
     */
    public function getUploadedChunksCount(): int
    {
        return count($this->getUploadedChunkIndexes());
    }

    /**
     * Return the number of chunks remaining.
     */
    public function getRemainingChunksCount(): int
    {
        return max(
            0,
            $this->total_chunks - $this->getUploadedChunksCount(),
        );
    }

    /**
     * Check whether all expected chunks have been uploaded.
     */
    public function hasAllChunksUploaded(): bool
    {
        if ($this->total_chunks <= 0) {
            return false;
        }

        return $this->getRemainingChunksCount() === 0;
    }

    /**
     * Return upload progress as a percentage.
     */
    public function getUploadProgressAttribute(): float
    {
        if ($this->total_chunks <= 0) {
            return 0.0;
        }

        return round(
            (
                $this->getUploadedChunksCount()
                / $this->total_chunks
            ) * 100,
            2,
        );
    }

    /**
     * Validate a zero-based chunk index.
     */
    public function isValidChunkIndex(int $chunkIndex): bool
    {
        return $chunkIndex >= 0
            && $chunkIndex < $this->total_chunks;
    }

    /**
     * Calculate the expected size of a specific chunk.
     *
     * The final chunk may be smaller than the configured chunk size.
     */
    public function getExpectedChunkSize(int $chunkIndex): int
    {
        if (!$this->isValidChunkIndex($chunkIndex)) {
            return 0;
        }

        if ($chunkIndex < $this->total_chunks - 1) {
            return $this->chunk_size;
        }

        return $this->total_size
            - (
                $this->chunk_size
                * ($this->total_chunks - 1)
            );
    }

    /**
     * Check whether the upload session can accept chunks.
     */
    public function canAcceptChunks(): bool
    {
        return $this->isUploading()
            && !$this->isExpired();
    }
}
