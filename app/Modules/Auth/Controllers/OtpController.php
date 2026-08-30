<?php

namespace App\Modules\Auth\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Requests\OtpRequest;
use App\Modules\Auth\Requests\VerifyOtpRequest;
use App\Modules\Auth\Services\AuthTokenService;
use App\Modules\Auth\Services\OtpService;
use App\Modules\Auth\Resources\AuthUserResource;
use App\Services\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Modules\Auth\Services\AuthSessionService;


class OtpController extends Controller
{
    public function __construct(
        private OtpService $otpService,
        private AuthSessionService $authSessionService
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | WEB OTP
    |--------------------------------------------------------------------------
    */

    /**
     * Send OTP for web authentication.
     *
     * POST:
     *
     * /api/v1/auth/web-otp/send
     */
    public function webSend(
        OtpRequest $request
    ): JsonResponse {
        $result = $this->otpService->send(
            $request->validated()
        );

        return ApiResponse::success(
            $result,
            'OTP sent successfully'
        );
    }

    /**
     * Verify OTP for web authentication.
     *
     * Authentication:
     *
     *     Laravel web session
     *
     * No Sanctum token is returned.
     *
     * POST:
     *
     * /api/v1/auth/web-otp/verify
     */
    public function webVerify(
        VerifyOtpRequest $request
    ): JsonResponse {
        Log::info('========== WEB OTP VERIFICATION START ==========');

        $result = $this->otpService->verify(
            $request->validated()
        );

        $user = $result['user'];

        /*
        |--------------------------------------------------------------------------
        | Authenticate Laravel Web Session
        |--------------------------------------------------------------------------
        */

        $this->authSessionService->authenticate(
            $request,
            $user
        );

        /*
        |--------------------------------------------------------------------------
        | Reload User
        |--------------------------------------------------------------------------
        */

        $this->loadUserRelations($user);

        /*
        |--------------------------------------------------------------------------
        | Web Resource
        |--------------------------------------------------------------------------
        |
        | No access token is returned.
        |
        */

        $resource = new AuthUserResource(
            $user
        );

        Log::info('Web OTP authentication successful', [
            'user_id' => $user->id,
            'user_type' => $user->user_type,
        ]);

        return ApiResponse::success(
            $resource,
            'OTP verified successfully'
        );
    }


    /**
     * Resend web OTP.
     *
     * POST:
     *
     * /api/v1/auth/web-otp/resend
     */
    public function webResend(
        OtpRequest $request
    ): JsonResponse {
        $result = $this->otpService->resend(
            $request->validated()
        );

        return ApiResponse::success(
            $result,
            'OTP resent successfully'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | HELPERS
    |--------------------------------------------------------------------------
    */

    /**
     * Load relations required by authenticated user.
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