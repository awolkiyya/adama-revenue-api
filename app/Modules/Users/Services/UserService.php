<?php

namespace App\Modules\Users\Services;

use App\Models\User;
use App\Services\Storage\ImageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class UserService
{
    public function __construct(
        protected ImageService $imageService
    ) {}

    /*
    |--------------------------------------------------------------------------
    | BASE QUERY
    |--------------------------------------------------------------------------
    */

    public function query()
    {
        $query = User::with([
            'roles',
            'permissions',
            'avatar',
            'sector',
            'administrativeUnit',
        ])
            ->where('user_type', 'employee')
            ->where('id', '!=', auth()->id())
            ->latest();

        /*
        |--------------------------------------------------------------------------
        | SEARCH
        |--------------------------------------------------------------------------
        */

        if ($search = request()->get('search')) {

            $query->where(function ($q) use ($search) {

                $q->where(
                    'name',
                    'like',
                    "%{$search}%"
                )
                    ->orWhere(
                        'email',
                        'like',
                        "%{$search}%"
                    )
                    ->orWhere(
                        'phone',
                        'like',
                        "%{$search}%"
                    );
            });
        }

        /*
        |--------------------------------------------------------------------------
        | ROLE FILTER
        |--------------------------------------------------------------------------
        |
        | The list endpoint may receive either:
        |
        | role=SYSTEM_ADMIN
        |
        | or another role name.
        |
        | Spatie's role() scope works with the role name.
        |
        */

        if ($role = request()->get('role')) {

            if ($role !== 'ALL') {

                $query->role($role);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | LEVEL FILTER
        |--------------------------------------------------------------------------
        */

        if ($level = request()->get('level')) {

            if ($level !== 'ALL') {

                $query->whereHas(
                    'administrativeUnit',
                    function ($q) use ($level) {

                        $q->where(
                            'level',
                            $level
                        );
                    }
                );
            }
        }

        /*
        |--------------------------------------------------------------------------
        | ACTIVE FILTER
        |--------------------------------------------------------------------------
        */

        if (!is_null(
            request()->get('is_active')
        )) {

            $query->where(
                'is_active',
                request()->boolean('is_active')
            );
        }

        /*
        |--------------------------------------------------------------------------
        | SECTOR FILTER
        |--------------------------------------------------------------------------
        */

        if ($sectorId = request()->get('sector_id')) {

            $query->where(
                'sector_id',
                $sectorId
            );
        }

        /*
        |--------------------------------------------------------------------------
        | PARENT ADMINISTRATIVE UNIT FILTER
        |--------------------------------------------------------------------------
        */

        if ($parentAdminUnitId = request()->get(
            'parent_admin_unit_id'
        )) {

            $query->whereHas(
                'administrativeUnit',
                function ($q) use (
                    $parentAdminUnitId
                ) {

                    $q->where(
                        'parent_id',
                        $parentAdminUnitId
                    );
                }
            );
        }

        return $query;
    }

    /*
    |--------------------------------------------------------------------------
    | CREATE USER
    |--------------------------------------------------------------------------
    */

    public function create(array $data): User
    {
        return DB::transaction(function () use ($data) {

            /*
            |--------------------------------------------------------------------------
            | RESOLVE ROLE BY ROLE ID
            |--------------------------------------------------------------------------
            |
            | Frontend sends:
            |
            | role_id: 2
            |
            | We resolve the actual Spatie Role here.
            |
            */

            $roleId = $data['role_id'] ?? null;

            $role = $this->resolveRole(
                $roleId
            );

            /*
            |--------------------------------------------------------------------------
            | PASSWORD
            |--------------------------------------------------------------------------
            */

            if (!empty($data['password'])) {

                $data['password'] = Hash::make(
                    $data['password']
                );
            }

            /*
            |--------------------------------------------------------------------------
            | USER LABEL
            |--------------------------------------------------------------------------
            |
            | Example:
            |
            | SYSTEM_ADMIN
            |      ↓
            | System Admin
            |
            */

            if ($role) {

                $data['label'] = Str::of(
                    $role->name
                )
                    ->replace('_', ' ')
                    ->title()
                    ->toString();
            }

            /*
            |--------------------------------------------------------------------------
            | AVATAR
            |--------------------------------------------------------------------------
            */

            $this->handleAvatarUpload(
                $data
            );

            /*
            |--------------------------------------------------------------------------
            | REMOVE RAW AVATAR
            |--------------------------------------------------------------------------
            */

            unset(
                $data['avatar']
            );

            /*
            |--------------------------------------------------------------------------
            | REMOVE ROLE_ID
            |--------------------------------------------------------------------------
            |
            | IMPORTANT:
            |
            | role_id is NOT stored in users table.
            |
            | Spatie stores the relationship in:
            |
            | model_has_roles
            |
            */

            unset(
                $data['role_id']
            );

            /*
            |--------------------------------------------------------------------------
            | CREATE USER
            |--------------------------------------------------------------------------
            */

            $user = User::create(
                $data
            );

            /*
            |--------------------------------------------------------------------------
            | ASSIGN ROLE
            |--------------------------------------------------------------------------
            */

            if ($role) {

                $user->syncRoles([
                    $role,
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | RETURN USER
            |--------------------------------------------------------------------------
            */

            return $user->load([
                'roles',
                'permissions',
                'avatar',
                'sector',
                'administrativeUnit',
            ]);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | UPDATE USER
    |--------------------------------------------------------------------------
    */

    public function update(
        User $user,
        array $data
    ): User {

        return DB::transaction(
            function () use (
                $user,
                $data
            ) {

                /*
                |--------------------------------------------------------------------------
                | RESOLVE ROLE
                |--------------------------------------------------------------------------
                |
                | If role_id was supplied, use the new role.
                |
                | If role_id was not supplied, preserve
                | the user's current role.
                |
                */

                $roleId =
                    array_key_exists(
                        'role_id',
                        $data
                    )
                        ? $data['role_id']
                        : $user->roles->first()?->id;

                $role =
                    $this->resolveRole(
                        $roleId
                    );

                /*
                |--------------------------------------------------------------------------
                | UPDATE LABEL
                |--------------------------------------------------------------------------
                */

                if ($role) {

                    $data['label'] = Str::of(
                        $role->name
                    )
                        ->replace('_', ' ')
                        ->title()
                        ->toString();
                }

                /*
                |--------------------------------------------------------------------------
                | PASSWORD
                |--------------------------------------------------------------------------
                */

                if (
                    isset($data['password']) &&
                    $data['password'] !== ''
                ) {

                    $data['password'] =
                        Hash::make(
                            $data['password']
                        );
                } else {

                    unset(
                        $data['password']
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | AVATAR
                |--------------------------------------------------------------------------
                */

                $this->handleAvatarUpload(
                    $data,
                    $user
                );

                /*
                |--------------------------------------------------------------------------
                | REMOVE RAW AVATAR
                |--------------------------------------------------------------------------
                */

                unset(
                    $data['avatar']
                );

                /*
                |--------------------------------------------------------------------------
                | REMOVE ROLE_ID
                |--------------------------------------------------------------------------
                |
                | Again, role_id does not belong
                | in the users table.
                |
                */

                unset(
                    $data['role_id']
                );

                /*
                |--------------------------------------------------------------------------
                | UPDATE USER
                |--------------------------------------------------------------------------
                */

                $user->update(
                    $data
                );

                /*
                |--------------------------------------------------------------------------
                | SYNC ROLE
                |--------------------------------------------------------------------------
                */

                if ($role) {

                    $user->syncRoles([
                        $role,
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | RETURN UPDATED USER
                |--------------------------------------------------------------------------
                */

                return $user
                    ->refresh()
                    ->load([
                        'roles',
                        'permissions',
                        'avatar',
                        'sector',
                        'administrativeUnit',
                    ]);
            }
        );
    }

    /*
    |--------------------------------------------------------------------------
    | DELETE USER
    |--------------------------------------------------------------------------
    */

    public function delete(
        User $user
    ): bool {

        return DB::transaction(
            function () use ($user) {

                /*
                |--------------------------------------------------------------------------
                | DELETE AVATAR
                |--------------------------------------------------------------------------
                */

                if ($user->avatar_file_id) {

                    $this->imageService->deleteById(
                        $user->avatar_file_id
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | DELETE USER
                |--------------------------------------------------------------------------
                */

                return $user->delete();
            }
        );
    }

    /*
    |--------------------------------------------------------------------------
    | PASSWORD UPDATE
    |--------------------------------------------------------------------------
    */

    public function updatePassword(
        User $user,
        array $data
    ): bool {

        return $user->update([
            'password' => Hash::make(
                $data['password']
            ),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | TOGGLE STATUS
    |--------------------------------------------------------------------------
    */

    public function toggleStatus(
        User $user
    ): User {

        $user->update([
            'is_active' => !$user->is_active,
        ]);

        return $user->refresh();
    }

    /*
    |--------------------------------------------------------------------------
    | RESOLVE ROLE
    |--------------------------------------------------------------------------
    |
    | Frontend:
    |
    | role_id = 2
    |
    | Backend:
    |
    | Role::where('id', 2)
    |
    | IMPORTANT:
    |
    | The role ID is an integer/bigint.
    |
    */

    private function resolveRole(
        mixed $roleId
    ): ?Role {

        if (
            is_null($roleId) ||
            $roleId === ''
        ) {
            return null;
        }

        return Role::query()
            ->whereKey(
                (int) $roleId
            )
            ->where(
                'guard_name',
                'api'
            )
            ->first();
    }

    /*
    |--------------------------------------------------------------------------
    | HANDLE AVATAR UPLOAD
    |--------------------------------------------------------------------------
    */

    private function handleAvatarUpload(
        array &$data,
        ?User $user = null
    ): void {

        /*
        |--------------------------------------------------------------------------
        | NO FILE
        |--------------------------------------------------------------------------
        */

        if (
            empty($data['avatar']) ||
            !(
                $data['avatar']
                instanceof UploadedFile
            )
        ) {

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | UPLOAD NEW IMAGE
        |--------------------------------------------------------------------------
        */

        $uploaded =
            $this->imageService
                ->uploadProfileImage(
                    file: $data['avatar'],
                    uploadedBy: auth()->id()
                );

        if (!$uploaded) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | UPDATE EXISTING USER AVATAR
        |--------------------------------------------------------------------------
        */

        if ($user) {

            /*
            |--------------------------------------------------------------------------
            | DELETE OLD AVATAR
            |--------------------------------------------------------------------------
            */

            $oldAvatar =
                $user->avatar;

            if ($oldAvatar) {

                $this->imageService->delete(
                    $oldAvatar
                );
            }

            /*
            |--------------------------------------------------------------------------
            | ATTACH NEW AVATAR
            |--------------------------------------------------------------------------
            */

            $user->avatar()->save(
                $uploaded
            );
        }

        /*
        |--------------------------------------------------------------------------
        | REMOVE RAW UPLOAD DATA
        |--------------------------------------------------------------------------
        */

        unset(
            $data['avatar']
        );
    }

    /*
    |--------------------------------------------------------------------------
    | RESOLVE LOCATION HIERARCHY
    |--------------------------------------------------------------------------
    |
    | Kept as a separate helper if another part
    | of the module uses it.
    |
    | NOTE:
    |
    | This method no longer reads $data['role'].
    | It uses role_id and resolves the role safely.
    |
    */

    private function resolveLocationHierarchy(
        array $data,
        User $user
    ): array {

        /*
        |--------------------------------------------------------------------------
        | RESOLVE ROLE ID
        |--------------------------------------------------------------------------
        */

        $roleId =
            array_key_exists(
                'role_id',
                $data
            )
                ? $data['role_id']
                : $user->roles->first()?->id;

        $role =
            $this->resolveRole(
                $roleId
            );

        $roleName =
            $role?->name;

        /*
        |--------------------------------------------------------------------------
        | CITY-ONLY ROLES
        |--------------------------------------------------------------------------
        */

        $cityOnlyRoles = [
            'SYSTEM_ADMIN',
            'CITY_MAYOR',
            'CITY_PLAN_REPORT_MANAGER',
        ];

        if (
            $roleName &&
            in_array(
                $roleName,
                $cityOnlyRoles,
                true
            )
        ) {

            $data['subcity_id'] = null;

            $data['wereda_id'] = null;
        }

        /*
        |--------------------------------------------------------------------------
        | REQUEST VALUES ONLY
        |--------------------------------------------------------------------------
        */

        $cityId =
            $data['city_id'] ?? null;

        $subcityId =
            $data['subcity_id'] ?? null;

        $weredaId =
            $data['wereda_id'] ?? null;

        /*
        |--------------------------------------------------------------------------
        | WEREDA
        |--------------------------------------------------------------------------
        */

        if (empty($weredaId)) {

            $data['wereda_id'] = null;
        }

        /*
        |--------------------------------------------------------------------------
        | SUBCITY
        |--------------------------------------------------------------------------
        */

        if (empty($subcityId)) {

            $data['subcity_id'] = null;
        }

        /*
        |--------------------------------------------------------------------------
        | NO DATABASE FALLBACK
        |--------------------------------------------------------------------------
        */

        return $data;
    }
}