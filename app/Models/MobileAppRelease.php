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
        'app_type',
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
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * APK file associated with this release.
     */
    public function apkFile(): MorphOne
    {
        return $this->morphOne(
            File::class,
            'fileable'
        )->where('collection', 'mobile_app_release');
    }

    /*
    |--------------------------------------------------------------------------
    | Query Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Filter releases by application type.
     */
    public function scopeForApp(
        Builder $query,
        string $appType
    ): Builder {
        return $query->where('app_type', $appType);
    }

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
            ->published()
            ->where('is_latest', true);
    }

    /**
     * Latest published release for a specific application.
     */
    public function scopeLatestForApp(
        Builder $query,
        string $appType
    ): Builder {
        return $query
            ->forApp($appType)
            ->latestRelease();
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
