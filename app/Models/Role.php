<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    use SoftDeletes;

    /**
     * Spatie's base Role model already guards most attributes via its own
     * $fillable; we only need to add the columns this app introduced.
     */
    protected $fillable = [
        'name',
        'guard_name',
        'is_system',
        'description',

    ];

    protected $casts = [
        'is_system' => 'boolean',
    ];

    public function users(): MorphToMany
    {
        return $this->morphedByMany(
            config('permission.models.user'),
            'model',
            config('permission.table_names.model_has_roles'),
            'role_id',
            config('permission.column_names.model_morph_key')
        );
    }

    /**
     * System roles (Admin, Owner, etc.) are protected — they must not be
     * deleted or renamed through normal role management.
     */
    public function isSystem(): bool
    {
        return (bool) $this->is_system;
    }

    /**
     * Convenience accessor used by the delete guard — whether this role
     * currently has any users assigned to it.
     */
    public function hasAssignedUsers(): bool
    {
        return $this->users()->exists();
    }
}