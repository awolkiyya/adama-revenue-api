<?php

namespace App\Modules\Taxpayer\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Taxpayer\Resources\TaxpayerInvoiceResource;
use App\Modules\Taxpayer\Services\TaxpayerInvoiceService;
use App\Services\ApiResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class TaxpayerInvoiceController extends Controller
{
    public function __construct(
        private readonly TaxpayerInvoiceService $invoiceService
    ) {
    }

    /**
     * Get invoices belonging to the authenticated taxpayer.
     *
     * GET /api/v1/taxpayer/invoices
     */
    public function index(Request $request)
    {
        $requestId = $request->header('X-Request-ID');

        Log::debug('============================================================');
        Log::debug('Taxpayer invoice list request received.', [
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
                'Taxpayer invoice request rejected: no authenticated user.',
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
                'Taxpayer invoice request rejected: authenticated user is not a citizen.',
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
        | Taxpayer Invoice Request
        |--------------------------------------------------------------------------
        */

        Log::info(
            'Authenticated taxpayer resolved successfully for invoices.',
            [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'citizen_account_id' => $citizenAccount->id,
                'citizen_id' => $citizen->id,
            ]
        );

        try {
            /*
            |--------------------------------------------------------------------------
            | Pagination
            |--------------------------------------------------------------------------
            */

            $perPage = min(
                max(
                    (int) $request->input('per_page', 15),
                    1
                ),
                100
            );

            $status = $request->input('status');

            Log::debug('Fetching taxpayer invoices.', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'citizen_id' => $citizen->id,
                'per_page' => $perPage,
                'status' => $status,
            ]);

            /*
            |--------------------------------------------------------------------------
            | Invoice Service
            |--------------------------------------------------------------------------
            */

            $invoices = $this->invoiceService->paginate(
                citizenId: $citizen->id,
                perPage: $perPage,
                status: $status
            );

            Log::debug('Taxpayer invoice service completed.', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'citizen_id' => $citizen->id,
                'returned_count' => $invoices->count(),
                'total' => $invoices->total(),
                'current_page' => $invoices->currentPage(),
                'last_page' => $invoices->lastPage(),
            ]);

            Log::info(
                'Taxpayer invoices retrieved successfully.',
                [
                    'request_id' => $requestId,
                    'user_id' => $user->id,
                    'citizen_id' => $citizen->id,
                    'returned_count' => $invoices->count(),
                    'total' => $invoices->total(),
                ]
            );

            return ApiResponse::success(
                data: TaxpayerInvoiceResource::collection($invoices),
                message: 'Taxpayer invoices retrieved successfully.'
            );
        } catch (Throwable $e) {
            Log::error(
                'Taxpayer invoice service failed.',
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

    /**
     * Get one invoice belonging to the authenticated taxpayer.
     *
     * GET /api/v1/taxpayer/invoices/{invoice}
     */
    public function show(
        Request $request,
        string $invoice
    ) {
        $requestId = $request->header('X-Request-ID');

        Log::debug('============================================================');
        Log::debug('Taxpayer invoice detail request received.', [
            'request_id' => $requestId,
            'method' => $request->method(),
            'path' => $request->path(),
            'url' => $request->fullUrl(),
            'invoice_id' => $invoice,
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
            'invoice_id' => $invoice,
        ]);

        if (! $user) {
            Log::warning(
                'Taxpayer invoice detail request rejected: no authenticated user.',
                [
                    'request_id' => $requestId,
                    'invoice_id' => $invoice,
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
                'Taxpayer invoice detail request rejected: authenticated user is not a citizen.',
                [
                    'request_id' => $requestId,
                    'user_id' => $user->id,
                    'user_type' => $user->user_type,
                    'invoice_id' => $invoice,
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
        */

        Log::debug('Resolving citizen account.', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'invoice_id' => $invoice,
        ]);

        $citizenAccount = $user->citizenAccount;

        Log::debug('Citizen account lookup completed.', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'invoice_id' => $invoice,
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
                    'invoice_id' => $invoice,
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
                    'invoice_id' => $invoice,
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
        */

        Log::debug('Resolving citizen profile.', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'citizen_account_id' => $citizenAccount->id,
            'citizen_id' => $citizenAccount->citizen_id,
            'invoice_id' => $invoice,
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
            'invoice_id' => $invoice,
        ]);

        if (! $citizen) {
            Log::warning(
                'Citizen account is not linked to a citizen profile.',
                [
                    'request_id' => $requestId,
                    'user_id' => $user->id,
                    'citizen_account_id' => $citizenAccount->id,
                    'citizen_id' => $citizenAccount->citizen_id,
                    'invoice_id' => $invoice,
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
                    'invoice_id' => $invoice,
                ]
            );

            return ApiResponse::forbidden(
                'Citizen profile is inactive.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Find Invoice
        |--------------------------------------------------------------------------
        */

        Log::info(
            'Authenticated taxpayer resolved successfully for invoice detail.',
            [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'citizen_account_id' => $citizenAccount->id,
                'citizen_id' => $citizen->id,
                'invoice_id' => $invoice,
            ]
        );

        try {
            Log::debug('Calling taxpayer invoice detail service.', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'citizen_id' => $citizen->id,
                'invoice_id' => $invoice,
            ]);

            $model = $this->invoiceService->findForTaxpayer(
                citizenId: $citizen->id,
                invoiceId: $invoice
            );

            Log::debug('Taxpayer invoice detail service completed.', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'citizen_id' => $citizen->id,
                'invoice_id' => $invoice,
                'invoice_number' => $model->invoice_number ?? null,
                'status' => $model->status ?? null,
                'total_amount' => $model->total_amount ?? null,
                'paid_amount' => $model->paid_amount ?? null,
                'balance_due' => $model->balance_due ?? null,
            ]);

            Log::info(
                'Taxpayer invoice retrieved successfully.',
                [
                    'request_id' => $requestId,
                    'user_id' => $user->id,
                    'citizen_id' => $citizen->id,
                    'invoice_id' => $invoice,
                    'invoice_number' => $model->invoice_number ?? null,
                ]
            );

            return ApiResponse::success(
                data: new TaxpayerInvoiceResource($model),
                message: 'Invoice retrieved successfully.'
            );
        } catch (ModelNotFoundException) {
            Log::warning(
                'Taxpayer invoice not found or does not belong to taxpayer.',
                [
                    'request_id' => $requestId,
                    'user_id' => $user->id,
                    'citizen_id' => $citizen->id,
                    'invoice_id' => $invoice,
                ]
            );

            /*
             * Important:
             *
             * The service should scope the invoice by citizen.
             * Therefore a missing invoice and an invoice belonging
             * to another taxpayer produce the same response.
             */
            return ApiResponse::notFound(
                'Invoice not found.'
            );
        } catch (Throwable $e) {
            Log::error(
                'Taxpayer invoice detail service failed.',
                [
                    'request_id' => $requestId,
                    'user_id' => $user->id,
                    'citizen_id' => $citizen->id,
                    'invoice_id' => $invoice,
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