<?php

namespace App\Modules\Taxpayer\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Taxpayer\Resources\TaxpayerDashboardResource;
use App\Modules\Taxpayer\Services\TaxpayerDashboardService;
use App\Services\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class TaxpayerDashboardController extends Controller
{
    public function __construct(
        private readonly TaxpayerDashboardService $dashboardService
    ) {
    }

    /**
     * Get the authenticated taxpayer dashboard.
     *
     * GET /api/v1/taxpayer/dashboard
     */
    public function index(Request $request)
    {
        $requestId = $request->header('X-Request-ID');

        Log::debug('============================================================');
        Log::debug('Taxpayer dashboard request received.', [
            'request_id' => $requestId,
            'method' => $request->method(),
            'path' => $request->path(),
            'url' => $request->fullUrl(),
        ]);
        Log::debug('============================================================');

        /*
        |--------------------------------------------------------------------------
        | Authenticated User
        |--------------------------------------------------------------------------
        */

        $user = $request->user();

        Log::debug('Authenticated taxpayer lookup.', [
            'request_id' => $requestId,
            'authenticated' => $user !== null,
            'user_id' => $user?->id,
            'user_type' => $user?->user_type,
            'user_email' => $user?->email,
            'user_phone' => $user?->phone,
            'is_active' => $user?->is_active,
        ]);

        if (! $user) {
            Log::warning(
                'Taxpayer dashboard request rejected: no authenticated user.',
                [
                    'request_id' => $requestId,
                ]
            );

            return ApiResponse::unauthorized(
                'Authentication is required.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Validate User Type
        |--------------------------------------------------------------------------
        */

        if (! $user->isCitizen()) {
            Log::warning(
                'Taxpayer dashboard request rejected: authenticated user is not a citizen.',
                [
                    'request_id' => $requestId,
                    'user_id' => $user->id,
                    'user_type' => $user->user_type,
                ]
            );

            return ApiResponse::forbidden(
                'Authenticated account is not a citizen account.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Citizen Account
        |--------------------------------------------------------------------------
        |
        | users.id
        |     ↓
        | citizen_accounts.user_id
        |
        */

        Log::debug('Resolving citizen account.', [
            'request_id' => $requestId,
            'user_id' => $user->id,
        ]);

        $citizenAccount = $user->citizenAccount;

        Log::debug('Citizen account lookup completed.', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'citizen_account_exists' => $citizenAccount !== null,
            'citizen_account_id' => $citizenAccount?->id,
            'citizen_id' => $citizenAccount?->citizen_id,
            'login_type' => $citizenAccount?->login_type,
            'is_active' => $citizenAccount?->is_active,
            'last_login_at' => $citizenAccount?->last_login_at,
        ]);

        if (! $citizenAccount) {
            Log::warning(
                'Authenticated citizen does not have a citizen account.',
                [
                    'request_id' => $requestId,
                    'user_id' => $user->id,
                ]
            );

            return ApiResponse::forbidden(
                'Authenticated taxpayer does not have a citizen account.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Citizen Account Status
        |--------------------------------------------------------------------------
        */

        if (! $citizenAccount->is_active) {
            Log::warning(
                'Citizen account is inactive.',
                [
                    'request_id' => $requestId,
                    'user_id' => $user->id,
                    'citizen_account_id' => $citizenAccount->id,
                    'citizen_id' => $citizenAccount->citizen_id,
                ]
            );

            return ApiResponse::forbidden(
                'Citizen account is inactive.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Citizen Profile
        |--------------------------------------------------------------------------
        |
        | citizen_accounts.citizen_id
        |     ↓
        | citizens.id
        |
        */

        Log::debug('Resolving citizen profile.', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'citizen_account_id' => $citizenAccount->id,
            'citizen_id' => $citizenAccount->citizen_id,
        ]);

        $citizen = $citizenAccount->citizen;

        Log::debug('Citizen profile lookup completed.', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'citizen_account_id' => $citizenAccount->id,
            'citizen_exists' => $citizen !== null,
            'citizen_id' => $citizen?->id,
            'citizen_uid' => $citizen?->citizen_uid,
            'citizen_name' => $citizen?->full_name,
            'citizen_phone' => $citizen?->phone,
            'citizen_email' => $citizen?->email,
            'citizen_active' => $citizen?->is_active,
        ]);

        if (! $citizen) {
            Log::warning(
                'Citizen account is not linked to a citizen profile.',
                [
                    'request_id' => $requestId,
                    'user_id' => $user->id,
                    'citizen_account_id' => $citizenAccount->id,
                    'citizen_id' => $citizenAccount->citizen_id,
                ]
            );

            return ApiResponse::forbidden(
                'Citizen account is not linked to a citizen profile.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Citizen Status
        |--------------------------------------------------------------------------
        */

        if (! $citizen->is_active) {
            Log::warning(
                'Citizen profile is inactive.',
                [
                    'request_id' => $requestId,
                    'user_id' => $user->id,
                    'citizen_account_id' => $citizenAccount->id,
                    'citizen_id' => $citizen->id,
                ]
            );

            return ApiResponse::forbidden(
                'Citizen profile is inactive.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Dashboard Service
        |--------------------------------------------------------------------------
        */

        Log::info(
            'Authenticated taxpayer resolved successfully.',
            [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'citizen_account_id' => $citizenAccount->id,
                'citizen_id' => $citizen->id,
            ]
        );

        Log::debug('Calling taxpayer dashboard service.', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'citizen_id' => $citizen->id,
        ]);

        try {
            $dashboard = $this->dashboardService->getDashboard(
                citizenId: $citizen->id
            );

            Log::debug('Taxpayer dashboard service completed.', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'citizen_id' => $citizen->id,
                'dashboard_type' => is_object($dashboard)
                    ? get_class($dashboard)
                    : gettype($dashboard),
            ]);

            Log::info(
                'Taxpayer dashboard retrieved successfully.',
                [
                    'request_id' => $requestId,
                    'user_id' => $user->id,
                    'citizen_id' => $citizen->id,
                ]
            );

            return ApiResponse::success(
                data: new TaxpayerDashboardResource($dashboard),
                message: 'Taxpayer dashboard retrieved successfully.'
            );
        } catch (Throwable $e) {
            Log::error(
                'Taxpayer dashboard service failed.',
                [
                    'request_id' => $requestId,
                    'user_id' => $user->id,
                    'citizen_id' => $citizen->id,
                    'exception_class' => get_class($e),
                    'exception_message' => $e->getMessage(),
                    'exception_file' => $e->getFile(),
                    'exception_line' => $e->getLine(),
                    'exception_trace' => $e->getTraceAsString(),
                ]
            );

            report($e);

            return ApiResponse::serverError(
                exception: $e
            );
        }
    }
}