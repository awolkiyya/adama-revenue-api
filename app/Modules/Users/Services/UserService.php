<?php

declare(strict_types=1);

namespace App\Modules\Users\Services;

use App\Models\File;
use App\Models\User;
use App\Services\Storage\StorageService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Throwable;

class UserService
{
    public function __construct(
        protected StorageService $storageService,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | LOGGING HELPERS
    |--------------------------------------------------------------------------
    */

    private function logContext(array $extra = []): array
    {
        $request = app()->bound('request') ? request() : null;

        return array_merge([
            'request_id' => $request
                ? (
                    $request->attributes->get('request_id')
                    ?? $request->header('X-Request-ID')
                )
                : null,
            'actor_id' => auth()->id(),
        ], $extra);
    }

    private function logFailure(
        string $operation,
        Throwable $exception,
        array $context = [],
    ): void {
        Log::error(
            "UserService operation failed: {$operation}",
            $this->logContext(array_merge($context, [
                'exception_class' => get_class($exception),
                'exception_message' => Str::limit(
                    $exception->getMessage(),
                    300,
                ),
                'exception_file' => $exception->getFile(),
                'exception_line' => $exception->getLine(),
            ])),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | BASE QUERY
    |--------------------------------------------------------------------------
    */

    public function query(): Builder
    {
        $filters = [
            'search_applied' => filled(request()->get('search')),
            'role' => request()->get('role'),
            'level' => request()->get('level'),
            'is_active' => request()->has('is_active')
                ? request()->boolean('is_active')
                : null,
            'sector_filter_applied' => filled(request()->get('sector_id')),
            'parent_unit_filter_applied' => filled(
                request()->get('parent_admin_unit_id'),
            ),
        ];

        Log::debug(
            'UserService query started',
            $this->logContext(['filters' => $filters]),
        );

        try {
            $query = User::query()
                ->with([
                    'roles',
                    'permissions',
                    'avatar',
                    'sector',
                    'administrativeUnit',
                ])
                ->where('user_type', 'employee')
                ->where('id', '!=', auth()->id())
                ->latest();

            if ($search = request()->get('search')) {
                $query->where(function (Builder $q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            }

            if ($role = request()->get('role')) {
                if ($role !== 'ALL') {
                    $query->role($role);
                }
            }

            if ($level = request()->get('level')) {
                if ($level !== 'ALL') {
                    $query->whereHas(
                        'administrativeUnit',
                        fn (Builder $q) => $q->where('level', $level),
                    );
                }
            }

            if (request()->has('is_active')) {
                $query->where(
                    'is_active',
                    request()->boolean('is_active'),
                );
            }

            if ($sectorId = request()->get('sector_id')) {
                $query->where('sector_id', $sectorId);
            }

            if ($parentAdminUnitId = request()->get('parent_admin_unit_id')) {
                $query->whereHas(
                    'administrativeUnit',
                    fn (Builder $q) => $q->where(
                        'parent_id',
                        $parentAdminUnitId,
                    ),
                );
            }

            Log::debug(
                'UserService query builder created',
                $this->logContext([
                    'user_type' => 'employee',
                    'current_user_excluded' => true,
                    'filters_applied' => $filters,
                ]),
            );

            return $query;
        } catch (Throwable $exception) {
            $this->logFailure('query', $exception, [
                'filters' => $filters,
            ]);

            throw $exception;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | CREATE USER
    |--------------------------------------------------------------------------
    */

    public function create(array $data): User
    {
        $operationId = (string) Str::uuid();
        $roleId = $data['role_id'] ?? null;

        Log::info(
            'User creation started',
            $this->logContext([
                'operation_id' => $operationId,
                'submitted_fields' => array_keys($data),
                'avatar_submitted' => (
                    $data['avatar'] ?? null
                ) instanceof UploadedFile,
                'requested_role_id' => $roleId,
            ]),
        );

        try {
            $user = DB::transaction(function () use (
                $data,
                $operationId,
                $roleId,
            ): User {
                $role = $this->resolveRole($roleId);

                if ($role) {
                    $data['label'] = Str::of($role->name)
                        ->replace('_', ' ')
                        ->title()
                        ->toString();
                }

                if (!empty($data['password'])) {
                    $data['password'] = Hash::make($data['password']);
                }

                $uploadedAvatar = $this->uploadAvatar(
                    $data,
                    $operationId,
                );

                unset($data['role_id'], $data['avatar']);

                $user = User::create($data);

                Log::info(
                    'User database record created',
                    $this->logContext([
                        'operation_id' => $operationId,
                        'user_id' => $user->getKey(),
                    ]),
                );

                if ($uploadedAvatar) {
                    $this->storageService->attachToModel(
                        $uploadedAvatar,
                        $user,
                    );

                    Log::info(
                        'Avatar attached to new user',
                        $this->logContext([
                            'operation_id' => $operationId,
                            'user_id' => $user->getKey(),
                            'avatar_id' => $uploadedAvatar->getKey(),
                            'collection' => $uploadedAvatar->collection,
                            'fileable_id' => $uploadedAvatar->fileable_id,
                            'fileable_type' => $uploadedAvatar->fileable_type,
                        ]),
                    );
                }

                if ($role) {
                    $user->syncRoles([$role]);
                } else {
                    Log::warning(
                        'User created without a resolved role',
                        $this->logContext([
                            'operation_id' => $operationId,
                            'user_id' => $user->getKey(),
                            'requested_role_id' => $roleId,
                        ]),
                    );
                }

                return $user;
            });

            $user->load([
                'roles',
                'permissions',
                'avatar',
                'sector',
                'administrativeUnit',
            ]);

            Log::info(
                'User creation completed',
                $this->logContext([
                    'operation_id' => $operationId,
                    'user_id' => $user->getKey(),
                    'avatar_loaded' => $user->avatar !== null,
                    'avatar_id' => $user->avatar?->getKey(),
                    'role_names' => $user->roles->pluck('name')->values()->all(),
                ]),
            );

            return $user;
        } catch (Throwable $exception) {
            $this->logFailure('create', $exception, [
                'operation_id' => $operationId,
                'requested_role_id' => $roleId,
            ]);

            throw $exception;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | UPDATE USER
    |--------------------------------------------------------------------------
    */

    public function update(User $user, array $data): User
    {
        $operationId = (string) Str::uuid();
        $userId = $user->getKey();

        $roleId = array_key_exists('role_id', $data)
            ? $data['role_id']
            : $user->roles()->first()?->getKey();

        Log::info(
            'User update started',
            $this->logContext([
                'operation_id' => $operationId,
                'target_user_id' => $userId,
                'submitted_fields' => array_keys($data),
                'avatar_submitted' => (
                    $data['avatar'] ?? null
                ) instanceof UploadedFile,
                'requested_role_id' => $roleId,
                'existing_avatar_id' => $user->avatar?->getKey(),
            ]),
        );

        $oldAvatar = null;
        $newAvatar = null;

        try {
            $user = DB::transaction(function () use (
                $user,
                $data,
                $operationId,
                $roleId,
                &$oldAvatar,
                &$newAvatar,
            ): User {
                $role = $this->resolveRole($roleId);

                if ($role) {
                    $data['label'] = Str::of($role->name)
                        ->replace('_', ' ')
                        ->title()
                        ->toString();
                }

                if (isset($data['password']) && $data['password'] !== '') {
                    $data['password'] = Hash::make($data['password']);
                } else {
                    unset($data['password']);
                }

                $avatarFile = $data['avatar'] ?? null;

                unset($data['avatar'], $data['role_id']);

                if ($avatarFile instanceof UploadedFile) {
                    $oldAvatar = $user->avatar()->first();

                    $newAvatar = $this->uploadAvatarFile(
                        $avatarFile,
                        $operationId,
                    );

                    if ($oldAvatar) {
                        $this->storageService->detach($oldAvatar);

                        Log::info(
                            'Previous avatar detached',
                            $this->logContext([
                                'operation_id' => $operationId,
                                'user_id' => $user->getKey(),
                                'old_avatar_id' => $oldAvatar->getKey(),
                            ]),
                        );
                    }

                    $this->storageService->attachToModel(
                        $newAvatar,
                        $user,
                    );

                    Log::info(
                        'New avatar attached during user update',
                        $this->logContext([
                            'operation_id' => $operationId,
                            'user_id' => $user->getKey(),
                            'new_avatar_id' => $newAvatar->getKey(),
                            'collection' => $newAvatar->collection,
                            'fileable_id' => $newAvatar->fileable_id,
                            'fileable_type' => $newAvatar->fileable_type,
                        ]),
                    );
                }

                $user->update($data);

                if ($role) {
                    $user->syncRoles([$role]);
                }

                return $user;
            });

            // Delete the old physical file only after the database commits.
            if (
                $oldAvatar
                && $newAvatar
                && (string) $oldAvatar->getKey()
                    !== (string) $newAvatar->getKey()
            ) {
                try {
                    $this->storageService->delete($oldAvatar);
                } catch (Throwable $cleanupException) {
                    $this->logFailure(
                        'deletePreviousAvatar',
                        $cleanupException,
                        [
                            'operation_id' => $operationId,
                            'user_id' => $userId,
                            'old_avatar_id' => $oldAvatar->getKey(),
                        ],
                    );
                }
            }

            $user->refresh()->load([
                'roles',
                'permissions',
                'avatar',
                'sector',
                'administrativeUnit',
            ]);

            Log::info(
                'User update completed',
                $this->logContext([
                    'operation_id' => $operationId,
                    'user_id' => $user->getKey(),
                    'avatar_loaded' => $user->avatar !== null,
                    'avatar_id' => $user->avatar?->getKey(),
                    'role_names' => $user->roles->pluck('name')->values()->all(),
                ]),
            );

            return $user;
        } catch (Throwable $exception) {
            $this->logFailure('update', $exception, [
                'operation_id' => $operationId,
                'target_user_id' => $userId,
                'requested_role_id' => $roleId,
            ]);

            throw $exception;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | DELETE USER
    |--------------------------------------------------------------------------
    */

    public function delete(User $user): bool
    {
        $operationId = (string) Str::uuid();
        $userId = $user->getKey();

        Log::info(
            'User deletion started',
            $this->logContext([
                'operation_id' => $operationId,
                'target_user_id' => $userId,
            ]),
        );

        try {
            $avatar = $user->avatar()->first();

            $deleted = DB::transaction(
                fn (): bool => (bool) $user->delete(),
            );

            if ($deleted && $avatar) {
                try {
                    $this->storageService->delete($avatar);
                } catch (Throwable $cleanupException) {
                    $this->logFailure(
                        'deleteUserAvatar',
                        $cleanupException,
                        [
                            'operation_id' => $operationId,
                            'user_id' => $userId,
                            'avatar_id' => $avatar->getKey(),
                        ],
                    );
                }
            }

            Log::info(
                'User deletion completed',
                $this->logContext([
                    'operation_id' => $operationId,
                    'user_id' => $userId,
                    'deleted' => $deleted,
                ]),
            );

            return $deleted;
        } catch (Throwable $exception) {
            $this->logFailure('delete', $exception, [
                'operation_id' => $operationId,
                'target_user_id' => $userId,
            ]);

            throw $exception;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | PASSWORD UPDATE
    |--------------------------------------------------------------------------
    */

    public function updatePassword(User $user, array $data): bool
    {
        $operationId = (string) Str::uuid();

        try {
            if (empty($data['password'])) {
                throw new RuntimeException('A password is required.');
            }

            $updated = $user->update([
                'password' => Hash::make($data['password']),
            ]);

            Log::info(
                'User password update completed',
                $this->logContext([
                    'operation_id' => $operationId,
                    'target_user_id' => $user->getKey(),
                    'updated' => $updated,
                ]),
            );

            return $updated;
        } catch (Throwable $exception) {
            $this->logFailure('updatePassword', $exception, [
                'operation_id' => $operationId,
                'target_user_id' => $user->getKey(),
            ]);

            throw $exception;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | TOGGLE STATUS
    |--------------------------------------------------------------------------
    */

    public function toggleStatus(User $user): User
    {
        $operationId = (string) Str::uuid();
        $oldStatus = (bool) $user->is_active;

        try {
            $user->update([
                'is_active' => !$oldStatus,
            ]);

            $user->refresh();

            Log::info(
                'User status toggle completed',
                $this->logContext([
                    'operation_id' => $operationId,
                    'target_user_id' => $user->getKey(),
                    'old_status' => $oldStatus,
                    'new_status' => (bool) $user->is_active,
                ]),
            );

            return $user;
        } catch (Throwable $exception) {
            $this->logFailure('toggleStatus', $exception, [
                'operation_id' => $operationId,
                'target_user_id' => $user->getKey(),
                'old_status' => $oldStatus,
            ]);

            throw $exception;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | RESOLVE ROLE
    |--------------------------------------------------------------------------
    */

    private function resolveRole(mixed $roleId): ?Role
    {
        if ($roleId === null || $roleId === '') {
            return null;
        }

        try {
            $role = Role::query()
                ->whereKey($roleId)
                ->where('guard_name', 'api')
                ->first();

            if (!$role) {
                Log::warning(
                    'Requested API role was not found',
                    $this->logContext([
                        'requested_role_id' => $roleId,
                        'expected_guard' => 'api',
                    ]),
                );
            }

            return $role;
        } catch (Throwable $exception) {
            $this->logFailure('resolveRole', $exception, [
                'requested_role_id' => $roleId,
            ]);

            throw $exception;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | UPLOAD AVATAR
    |--------------------------------------------------------------------------
    */

    private function uploadAvatar(
        array &$data,
        string $operationId,
    ): ?File {
        $file = $data['avatar'] ?? null;

        unset($data['avatar']);

        if (!$file instanceof UploadedFile) {
            Log::debug(
                'Avatar upload skipped',
                $this->logContext([
                    'operation_id' => $operationId,
                    'avatar_submitted' => false,
                ]),
            );

            return null;
        }

        return $this->uploadAvatarFile($file, $operationId);
    }

    private function uploadAvatarFile(
        UploadedFile $file,
        string $operationId,
    ): File {
        Log::info(
            'Avatar file received by UserService',
            $this->logContext([
                'operation_id' => $operationId,
                'extension' => $file->getClientOriginalExtension(),
                'mime_type' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
                'is_valid' => $file->isValid(),
                'upload_error_code' => $file->getError(),
            ]),
        );

        if (!$file->isValid()) {
            throw new RuntimeException(
                'The uploaded avatar file is invalid.',
            );
        }

        try {
            $checksum = hash_file('sha256', $file->getRealPath());

            Log::debug(
                'Calling StorageService::upload for avatar',
                $this->logContext([
                    'operation_id' => $operationId,
                    'folder' => 'avatars',
                    'collection' => 'AVATAR',
                    'visibility' => 'PRIVATE',
                    'size_bytes' => $file->getSize(),
                ]),
            );

            $avatar = $this->storageService->upload(
                uploadedFile: $file,
                folder: 'avatars',
                uploadedBy: auth()->id()
                    ? (string) auth()->id()
                    : null,
                category: 'AVATAR',
                visibility: 'private',
            );

            /*
             * StorageService is shared by other modules. Normalize the
             * returned record here for the File model's actual schema,
             * without changing upload behavior for other file categories.
             */
            $avatar->forceFill([
                'collection' => 'AVATAR',
                'size_bytes' => $file->getSize(),
                'checksum' => $checksum !== false ? $checksum : null,
                'visibility' => 'PRIVATE',
            ])->save();

            $avatar->refresh();

            Log::info(
                'StorageService avatar upload completed',
                $this->logContext([
                    'operation_id' => $operationId,
                    'file_id' => $avatar->getKey(),
                    'file_uuid' => $avatar->uuid,
                    'collection' => $avatar->collection,
                    'size_bytes' => $avatar->size_bytes,
                    'visibility' => $avatar->visibility,
                    'status' => $avatar->status,
                    'disk' => $avatar->disk,
                    'path' => $avatar->path,
                    'fileable_id' => $avatar->fileable_id,
                    'fileable_type' => $avatar->fileable_type,
                ]),
            );

            return $avatar;
        } catch (Throwable $exception) {
            $this->logFailure('uploadAvatarFile', $exception, [
                'operation_id' => $operationId,
                'size_bytes' => $file->getSize(),
            ]);

            throw $exception;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | RESOLVE LOCATION HIERARCHY
    |--------------------------------------------------------------------------
    */

    private function resolveLocationHierarchy(
        array $data,
        User $user,
    ): array {
        $roleId = array_key_exists('role_id', $data)
            ? $data['role_id']
            : $user->roles()->first()?->getKey();

        $role = $this->resolveRole($roleId);
        $roleName = $role?->name;

        $cityOnlyRoles = [
            'SYSTEM_ADMIN',
            'CITY_MAYOR',
            'CITY_PLAN_REPORT_MANAGER',
        ];

        if (
            $roleName
            && in_array($roleName, $cityOnlyRoles, true)
        ) {
            $data['subcity_id'] = null;
            $data['wereda_id'] = null;
        }

        if (empty($data['wereda_id'] ?? null)) {
            $data['wereda_id'] = null;
        }

        if (empty($data['subcity_id'] ?? null)) {
            $data['subcity_id'] = null;
        }

        return $data;
    }
}
