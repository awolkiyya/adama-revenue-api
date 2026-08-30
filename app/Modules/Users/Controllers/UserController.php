<?php

namespace App\Modules\Users\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Users\Services\UserService;
use App\Modules\Users\Requests\StoreUserRequest;
use App\Modules\Users\Requests\UpdateUserRequest;
use App\Modules\Users\Requests\UpdatePasswordRequest;
use App\Modules\Users\Resources\UserResource;
use App\Services\ApiResponse;
use App\Services\SystemLogService;

class UserController extends Controller
{
    public function __construct(
        protected UserService $userService,
        protected SystemLogService $systemLogService,
    ) {}

    /**
     * =========================================================
     * GET ALL USERS
     * =========================================================
     */
    public function index()
    {
        $this->authorize('viewAny', User::class);

        $query = $this->userService->query();

        $users = $query->paginate(15);

        return ApiResponse::success(
            UserResource::collection($users),
            'Users retrieved successfully'
        );
    }

    /**
     * =========================================================
     * CREATE USER
     * =========================================================
     */
    public function store(StoreUserRequest $request)
    {
        $this->authorize('create', User::class);

        $validated = $request->validated();

        $user = $this->userService->create($validated);

        /*
        |--------------------------------------------------------------------------
        | AUDIT LOG
        |--------------------------------------------------------------------------
        |
        | Do not manually log passwords.
        | SystemLogService sanitizes sensitive fields as an
        | additional safety layer.
        |
        */
        $this->systemLogService->created(
            resource: $user,
            module: 'Users',
            description: 'User created successfully',
            newValues: $user->getAttributes(),
        );

        return ApiResponse::created(
            new UserResource($user),
            'User created successfully'
        );
    }

    /**
     * =========================================================
     * SHOW USER
     * =========================================================
     */
    public function show(User $user)
    {
        $this->authorize('view', $user);

        $user->load([
            'avatar',
            'roles',
            'administrativeUnit',
        ]);

        /*
        |--------------------------------------------------------------------------
        | AUDIT VIEW
        |--------------------------------------------------------------------------
        */
        $this->systemLogService->viewed(
            resource: $user,
            module: 'Users',
            description: 'User profile viewed',
        );

        return ApiResponse::success(
            new UserResource($user),
            'User retrieved successfully'
        );
    }

    /**
     * =========================================================
     * UPDATE USER
     * =========================================================
     */
    public function update(
        UpdateUserRequest $request,
        User $user
    ) {
        $this->authorize('update', $user);

        /*
        |--------------------------------------------------------------------------
        | Capture original values BEFORE update
        |--------------------------------------------------------------------------
        */
        $oldValues = $user->getAttributes();

        $validated = $request->validated();

        $updated = $this->userService->update(
            $user,
            $validated
        );

        /*
        |--------------------------------------------------------------------------
        | AUDIT LOG
        |--------------------------------------------------------------------------
        */
        $this->systemLogService->updated(
            resource: $updated,
            module: 'Users',
            oldValues: $oldValues,
            newValues: $updated->getAttributes(),
            description: 'User updated successfully',
        );

        return ApiResponse::success(
            new UserResource($updated),
            'User updated successfully'
        );
    }

    /**
     * =========================================================
     * DELETE USER
     * =========================================================
     */
    public function destroy(User $user)
    {
        $this->authorize('delete', $user);

        /*
        |--------------------------------------------------------------------------
        | Capture values BEFORE deletion
        |--------------------------------------------------------------------------
        */
        $oldValues = $user->getAttributes();

        $this->userService->delete($user);

        /*
        |--------------------------------------------------------------------------
        | AUDIT LOG
        |--------------------------------------------------------------------------
        */
        $this->systemLogService->deleted(
            resource: $user,
            module: 'Users',
            oldValues: $oldValues,
            description: 'User deleted successfully',
        );

        return ApiResponse::success(
            null,
            'User deleted successfully'
        );
    }

    /**
     * =========================================================
     * UPDATE PASSWORD
     * =========================================================
     */
    public function updatePassword(
        UpdatePasswordRequest $request,
        User $user
    ) {
        $this->authorize('updatePassword', $user);

        $this->userService->updatePassword(
            $user,
            $request->validated()
        );

        /*
        |--------------------------------------------------------------------------
        | SECURITY AUDIT
        |--------------------------------------------------------------------------
        |
        | Do NOT store the password or password request data.
        |
        */
        $this->systemLogService->security(
            action: 'PASSWORD_CHANGED',
            description: 'User password changed successfully',
            metadata: [
                'target_user_id' => $user->getKey(),
            ],
        );

        return ApiResponse::success(
            null,
            'Password updated successfully'
        );
    }

    /**
     * =========================================================
     * TOGGLE USER STATUS
     * =========================================================
     */
    public function updateStatus(User $user)
    {
        $this->authorize('updateStatus', $user);

        $oldStatus = (bool) $user->is_active;

        $updated = $this->userService->toggleStatus($user);

        $newStatus = (bool) $updated->is_active;

        /*
        |--------------------------------------------------------------------------
        | AUDIT STATUS CHANGE
        |--------------------------------------------------------------------------
        */
        $this->systemLogService->statusChanged(
            resource: $updated,
            module: 'Users',
            oldStatus: $oldStatus,
            newStatus: $newStatus,
            description: $newStatus
                ? 'User activated successfully'
                : 'User deactivated successfully',
        );

        return ApiResponse::success(
            new UserResource($updated),
            'User status updated successfully'
        );
    }
}