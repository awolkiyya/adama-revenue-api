<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class MobileAppRelease extends Model
{
    use HasFactory;
    use HasUuids;

    /*
    |--------------------------------------------------------------------------
    | Table
    |--------------------------------------------------------------------------
    */

    protected $table = 'mobile_app_releases';

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
        'version_name',
        'version_code',
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
            'is_latest' => 'boolean',
            'is_mandatory' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | UUID
    |--------------------------------------------------------------------------
    */

    public function uniqueIds(): array
    {
        return ['id'];
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
        return $this->belongsTo(
            User::class,
            'created_by'
        );
    }

    /**
     * APK file associated with this release.
     *
     * The file must use the mobile_app_release collection.
     */
    public function apkFile(): MorphOne
    {
        return $this->morphOne(
            File::class,
            'fileable'
        )->where(
            'collection',
            'mobile_app_release'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Query Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Scope to published releases.
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }

    /**
     * Scope to the latest published release.
     */
    public function scopeLatestRelease(Builder $query): Builder
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
     * Determine whether the associated APK is ready to download.
     */
    public function isDownloadable(): bool
    {
        if (! $this->isPublished()) {
            return false;
        }

        $file = $this->apkFile;

        return $file !== null
            && $file->status === 'READY'
            && $file->extension !== null
            && strtolower($file->extension) === 'apk'
            && ! empty($file->path)
            && ! empty($file->disk)
            && $file->exists;
    }
}
