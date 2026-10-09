<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MobileAppRelease extends Model
{
    use HasFactory;

    /*
    |--------------------------------------------------------------------------
    | Table
    |--------------------------------------------------------------------------
    */

    protected $table = 'mobile_app_releases';

    /*
    |--------------------------------------------------------------------------
    | Mass Assignment
    |--------------------------------------------------------------------------
    */

    protected $fillable = [
        'version_name',
        'version_code',
        'apk_path',
        'apk_size',
        'apk_sha256',
        'release_notes',
        'is_latest',
        'is_mandatory',
        'status',
        'published_at',
        'created_by',
    ];

    /*
    |--------------------------------------------------------------------------
    | Attribute Casting
    |--------------------------------------------------------------------------
    */

    protected function casts(): array
    {
        return [
            'version_code' => 'integer',
            'apk_size' => 'integer',
            'is_latest' => 'boolean',
            'is_mandatory' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * Administrator who created the release.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /*
    |--------------------------------------------------------------------------
    | Query Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Scope to published releases.
     */
    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    /**
     * Scope to the latest published release.
     */
    public function scopeLatestRelease($query)
    {
        return $query
            ->where('status', 'published')
            ->where('is_latest', true);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Determine whether the release is published.
     */
    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    /**
     * Determine whether the release is downloadable.
     */
    public function isDownloadable(): bool
    {
        return $this->isPublished()
            && ! empty($this->apk_path);
    }
}
