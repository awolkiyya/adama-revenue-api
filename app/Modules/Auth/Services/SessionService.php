<?php

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Resources\AuthUserResource;
use Illuminate\Http\Request;

class SessionService
{
    /*
    |--------------------------------------------------------------------------
    | WEB
    |--------------------------------------------------------------------------
    */

    /**
     * Get authenticated web user.
     */
    public function webUser(Request $request): array
    {
        $user = $request->user('web');

        if (!$user) {
            return [
                'user' => null,
            ];
        }

        $this->loadUserRelations($user);

        return [
            'user' => new AuthUserResource($user),
        ];
    }

    /**
     * Get current web session information.
     */
    public function sessionInfo(Request $request): array
    {
        $user = $request->user('web');

        if (!$user) {
            return [
                'authenticated' => false,
                'user' => null,
            ];
        }

        $this->loadUserRelations($user);

        return [
            'authenticated' => true,
            'user' => new AuthUserResource($user),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | MOBILE
    |--------------------------------------------------------------------------
    */

    /**
     * Get authenticated mobile user.
     *
     * Authentication:
     *     Sanctum personal access token
     *
     * Header:
     *     Authorization: Bearer {token}
     */
    public function mobileUser(Request $request): array
    {
        $user = $request->user('sanctum');

        if (!$user) {
            return [
                'user' => null,
            ];
        }

        $this->loadUserRelations($user);

        return [
            'user' => new AuthUserResource($user),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | USER RELATIONS
    |--------------------------------------------------------------------------
    */

    /**
     * Load relations required by the authenticated user.
     */
    private function loadUserRelations($user): void
    {
        $relations = [
            'administrativeUnit.parent.parent',
            'avatar',
        ];

        if ($user->user_type === 'employee') {
            $relations[] = 'roles.permissions';
        }

        if ($user->user_type === 'citizen') {
            $relations[] = 'citizenAccount.citizen.avatar';
        }

        $user->load($relations);
    }
}
