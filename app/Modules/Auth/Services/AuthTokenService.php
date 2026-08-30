<?php

namespace App\Modules\Auth\Services;

use App\Models\User;


class AuthTokenService
{


    /**
     * Create Sanctum personal access token.
     */
    public function createToken(
        User $user,
        string $device = 'web'
    ): string {

        return $user
            ->createToken($device)
            ->plainTextToken;

    }





    /**
     * Create authentication cookies.
     *
     * token:
     * - HttpOnly
     * - Secure
     *
     * role:
     * - Frontend permission display only
     */
    public function createCookies(
        string $token,
        ?string $role = null
    ): array {


        $cookies = [

            cookie(
                'token',
                $token,
                $this->cookieLifetime(),
                '/',
                config('session.domain'),
                config('session.secure'),
                true,
                false,
                'Strict'
            ),

        ];



        /**
         * Only create role cookie
         * when user has a role.
         */
        if ($role) {

            $cookies[] = cookie(
                'role',
                $role,
                $this->cookieLifetime(),
                '/',
                config('session.domain'),
                config('session.secure'),
                false,
                false,
                'Strict'
            );

        }


        return $cookies;

    }





    /**
     * Remove authentication cookies.
     */
    public function forgetCookies(): array
    {

        return [

            cookie()->forget('token'),

            cookie()->forget('role'),

        ];

    }





    /**
     * Revoke current Sanctum token.
     */
    public function revokeCurrentToken(
        User $user
    ): void {

        $user
            ->currentAccessToken()
            ?->delete();

    }





    /**
     * Revoke all user tokens.
     */
    public function revokeAllTokens(
        User $user
    ): void {

        $user
            ->tokens()
            ->delete();

    }





    /**
     * Cookie lifetime in minutes.
     */
    private function cookieLifetime(): int
    {

        return 60 * 24;

    }

}