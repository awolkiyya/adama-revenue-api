<?php

namespace App\Modules\Taxpayer\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payment\Resources\PaymentResource;
use App\Modules\Taxpayer\Services\TaxpayerPaymentService;
use App\Services\ApiResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class TaxpayerPaymentController extends Controller
{
    public function __construct(
        private readonly TaxpayerPaymentService $paymentService
    ) {
    }

    
/**
 * Get authenticated taxpayer payment history.
 *
 * GET /api/v1/taxpayer/payments
 *
 * Supported query parameters:
 * - page: integer, minimum 1
 * - per_page: integer, between 1 and 100
 * - search: payment number, transaction reference, invoice number, etc.
 * - status: PENDING, PROCESSING, COMPLETED, FAILED, CANCELLED, EXPIRED
 */
public function index(Request $request)
{
    $requestId = $request->header('X-Request-ID');

    Log::debug('============================================================');
    Log::debug('Taxpayer payment history request received.', [
        'request_id' => $requestId,
        'method' => $request->method(),
        'path' => $request->path(),
        'url' => $request->fullUrl(),
        'ip' => $request->ip(),
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
            'Taxpayer payment history request rejected: no authenticated user.',
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
            'Taxpayer payment history request rejected: authenticated user is not a citizen.',
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
    | Resolve Citizen Account
    |--------------------------------------------------------------------------
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
    | Validate Citizen Account Status
    |--------------------------------------------------------------------------
    */

    if (! $citizenAccount->is_active) {
        Log::warning('Citizen account is inactive.', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'citizen_account_id' => $citizenAccount->id,
            'citizen_id' => $citizenAccount->citizen_id,
        ]);

        return ApiResponse::forbidden(
            'Citizen account is inactive.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Resolve Citizen Profile
    |--------------------------------------------------------------------------
    |
    | users.id
    |     -> citizen_accounts.user_id
    |     -> citizen_accounts.citizen_id
    |     -> citizens.id
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
    | Validate Citizen Profile Status
    |--------------------------------------------------------------------------
    */

    if (! $citizen->is_active) {
        Log::warning('Citizen profile is inactive.', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'citizen_account_id' => $citizenAccount->id,
            'citizen_id' => $citizen->id,
        ]);

        return ApiResponse::forbidden(
            'Citizen profile is inactive.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Validate Query Parameters
    |--------------------------------------------------------------------------
    */

    $validated = $request->validate([
        'page' => [
            'sometimes',
            'integer',
            'min:1',
        ],
        'per_page' => [
            'sometimes',
            'integer',
            'min:1',
            'max:100',
        ],
        'search' => [
            'sometimes',
            'nullable',
            'string',
            'max:100',
        ],
        'status' => [
            'sometimes',
            'nullable',
            'string',
            'in:PENDING,PROCESSING,COMPLETED,FAILED,CANCELLED,EXPIRED',
        ],
    ]);

    $page = (int) ($validated['page'] ?? 1);

    $perPage = (int) ($validated['per_page'] ?? 15);

    $search = isset($validated['search'])
        ? trim($validated['search'])
        : null;

    $status = $validated['status'] ?? null;

    if ($search === '') {
        $search = null;
    }

    if ($status === '') {
        $status = null;
    }

    Log::debug('Taxpayer payment filters resolved.', [
        'request_id' => $requestId,
        'user_id' => $user->id,
        'citizen_id' => $citizen->id,
        'requested_page' => $request->input('page'),
        'resolved_page' => $page,
        'requested_per_page' => $request->input('per_page'),
        'resolved_per_page' => $perPage,
        'search' => $search,
        'status' => $status,
    ]);

    /*
    |--------------------------------------------------------------------------
    | Retrieve Filtered Payment History
    |--------------------------------------------------------------------------
    */

    Log::info(
        'Authenticated taxpayer resolved successfully for payment history.',
        [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'citizen_account_id' => $citizenAccount->id,
            'citizen_id' => $citizen->id,
        ]
    );

    Log::debug('Calling taxpayer payment service.', [
        'request_id' => $requestId,
        'user_id' => $user->id,
        'citizen_id' => $citizen->id,
        'page' => $page,
        'per_page' => $perPage,
        'search' => $search,
        'status' => $status,
    ]);

    try {
        $payments = $this->paymentService->paginate(
            citizenId: $citizen->id,
            perPage: $perPage,
            search: $search,
            status: $status,
            page: $page,
        );

        /*
        |--------------------------------------------------------------------------
        | Log Pagination Metadata
        |--------------------------------------------------------------------------
        */

        Log::debug('Taxpayer payment service completed.', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'citizen_id' => $citizen->id,
            'payment_result_type' => get_class($payments),
            'payment_count' => $payments->count(),
            'total' => $payments->total(),
            'current_page' => $payments->currentPage(),
            'last_page' => $payments->lastPage(),
            'per_page' => $payments->perPage(),
            'from' => $payments->firstItem(),
            'to' => $payments->lastItem(),
            'has_more' => $payments->hasMorePages(),
        ]);

        Log::info(
            'Taxpayer payment history retrieved successfully.',
            [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'citizen_id' => $citizen->id,
                'result_count' => $payments->count(),
                'total_payments' => $payments->total(),
                'current_page' => $payments->currentPage(),
                'last_page' => $payments->lastPage(),
                'per_page' => $payments->perPage(),
                'search' => $search,
                'status' => $status,
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Return Standardized API Response
        |--------------------------------------------------------------------------
        */

        return ApiResponse::success(
            data: PaymentResource::collection($payments),
            message: 'Payment history retrieved successfully.'
        );
    } catch (Throwable $e) {
        Log::error('Taxpayer payment history retrieval failed.', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'citizen_id' => $citizen->id,
            'page' => $page,
            'per_page' => $perPage,
            'search' => $search,
            'status' => $status,
            'exception_class' => get_class($e),
            'exception_message' => $e->getMessage(),
            'exception_file' => $e->getFile(),
            'exception_line' => $e->getLine(),
        ]);

        report($e);

        return ApiResponse::serverError(
            exception: $e
        );
    }
}

    /**
     * Get a single taxpayer payment.
     *
     * GET /api/v1/taxpayer/payments/{payment}
     */
    public function show(
        Request $request,
        string $payment
    ) {
        $requestId = $request->header('X-Request-ID');

        Log::debug('============================================================');
        Log::debug('Taxpayer payment detail request received.', [
            'request_id' => $requestId,
            'method' => $request->method(),
            'path' => $request->path(),
            'url' => $request->fullUrl(),
            'ip' => $request->ip(),
            'payment_id' => $payment,
        ]);
        Log::debug('============================================================');

        /*
        |--------------------------------------------------------------------------
        | Authenticated User
        |--------------------------------------------------------------------------
        */

        $user = $request->user();

        Log::debug('Authenticated taxpayer lookup for payment detail.', [
            'request_id' => $requestId,
            'authenticated' => $user !== null,
            'user_id' => $user?->id,
            'user_type' => $user?->user_type,
            'user_email' => $user?->email,
            'user_phone' => $user?->phone,
            'is_active' => $user?->is_active,
            'payment_id' => $payment,
        ]);

        if (! $user) {
            Log::warning(
                'Taxpayer payment detail request rejected: no authenticated user.',
                [
                    'request_id' => $requestId,
                    'payment_id' => $payment,
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
                'Taxpayer payment detail request rejected: authenticated user is not a citizen.',
                [
                    'request_id' => $requestId,
                    'user_id' => $user->id,
                    'user_type' => $user->user_type,
                    'payment_id' => $payment,
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

        Log::debug('Resolving citizen account for payment detail.', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'payment_id' => $payment,
        ]);

        $citizenAccount = $user->citizenAccount;

        Log::debug('Citizen account lookup completed.', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'payment_id' => $payment,
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
                    'payment_id' => $payment,
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
                    'payment_id' => $payment,
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

        Log::debug('Resolving citizen profile for payment detail.', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'citizen_account_id' => $citizenAccount->id,
            'citizen_id' => $citizenAccount->citizen_id,
            'payment_id' => $payment,
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
            'payment_id' => $payment,
        ]);

        if (! $citizen) {
            Log::warning(
                'Citizen account is not linked to a citizen profile.',
                [
                    'request_id' => $requestId,
                    'user_id' => $user->id,
                    'citizen_account_id' => $citizenAccount->id,
                    'citizen_id' => $citizenAccount->citizen_id,
                    'payment_id' => $payment,
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
                    'payment_id' => $payment,
                ]
            );

            return ApiResponse::forbidden(
                'Citizen profile is inactive.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Payment Lookup
        |--------------------------------------------------------------------------
        */

        Log::debug(
            'Calling taxpayer payment service for payment detail.',
            [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'citizen_id' => $citizen->id,
                'payment_id' => $payment,
            ]
        );

        try {
            $model = $this->paymentService->findForTaxpayer(
                citizenId: $citizen->id,
                paymentId: $payment
            );

            Log::info(
                'Taxpayer payment retrieved successfully.',
                [
                    'request_id' => $requestId,
                    'user_id' => $user->id,
                    'citizen_id' => $citizen->id,
                    'payment_id' => $model->id,
                    'payment_number' => $model->payment_number,
                    'invoice_id' => $model->invoice_id,
                    'status' => $model->status,
                    'amount' => $model->amount,
                    'currency' => $model->currency,
                ]
            );

            return ApiResponse::success(
                data: new PaymentResource(
                    $model
                ),
                message: 'Payment retrieved successfully.'
            );
        } catch (ModelNotFoundException) {
            Log::warning(
                'Taxpayer payment not found or does not belong to taxpayer.',
                [
                    'request_id' => $requestId,
                    'user_id' => $user->id,
                    'citizen_id' => $citizen->id,
                    'payment_id' => $payment,
                ]
            );

            return ApiResponse::notFound(
                'Payment not found.'
            );
        } catch (Throwable $e) {
            Log::error(
                'Taxpayer payment detail service failed.',
                [
                    'request_id' => $requestId,
                    'user_id' => $user->id,
                    'citizen_id' => $citizen->id,
                    'payment_id' => $payment,
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
     * Get payment receipt.
     *
     * GET /api/v1/taxpayer/payments/{payment}/receipt
     */
    public function receipt(
        Request $request,
        string $payment
    ) {
        $requestId = $request->header('X-Request-ID');

        Log::debug('============================================================');
        Log::debug('Taxpayer payment receipt request received.', [
            'request_id' => $requestId,
            'method' => $request->method(),
            'path' => $request->path(),
            'url' => $request->fullUrl(),
            'ip' => $request->ip(),
            'payment_id' => $payment,
        ]);
        Log::debug('============================================================');

        /*
        |--------------------------------------------------------------------------
        | Authenticated User
        |--------------------------------------------------------------------------
        */

        $user = $request->user();

        Log::debug('Authenticated taxpayer lookup for receipt.', [
            'request_id' => $requestId,
            'authenticated' => $user !== null,
            'user_id' => $user?->id,
            'user_type' => $user?->user_type,
            'user_email' => $user?->email,
            'user_phone' => $user?->phone,
            'is_active' => $user?->is_active,
            'payment_id' => $payment,
        ]);

        if (! $user) {
            Log::warning(
                'Taxpayer payment receipt request rejected: no authenticated user.',
                [
                    'request_id' => $requestId,
                    'payment_id' => $payment,
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
                'Taxpayer payment receipt request rejected: authenticated user is not a citizen.',
                [
                    'request_id' => $requestId,
                    'user_id' => $user->id,
                    'user_type' => $user->user_type,
                    'payment_id' => $payment,
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

        Log::debug('Resolving citizen account for receipt.', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'payment_id' => $payment,
        ]);

        $citizenAccount = $user->citizenAccount;

        Log::debug('Citizen account lookup completed.', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'payment_id' => $payment,
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
                    'payment_id' => $payment,
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
                    'payment_id' => $payment,
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

        Log::debug('Resolving citizen profile for receipt.', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'citizen_account_id' => $citizenAccount->id,
            'citizen_id' => $citizenAccount->citizen_id,
            'payment_id' => $payment,
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
            'payment_id' => $payment,
        ]);

        if (! $citizen) {
            Log::warning(
                'Citizen account is not linked to a citizen profile.',
                [
                    'request_id' => $requestId,
                    'user_id' => $user->id,
                    'citizen_account_id' => $citizenAccount->id,
                    'citizen_id' => $citizenAccount->citizen_id,
                    'payment_id' => $payment,
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
                    'payment_id' => $payment,
                ]
            );

            return ApiResponse::forbidden(
                'Citizen profile is inactive.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Payment Lookup
        |--------------------------------------------------------------------------
        */

        Log::debug(
            'Calling taxpayer payment service for receipt.',
            [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'citizen_id' => $citizen->id,
                'payment_id' => $payment,
            ]
        );

        try {
            $model = $this->paymentService->findForTaxpayer(
                citizenId: $citizen->id,
                paymentId: $payment
            );

            Log::debug(
                'Payment found for receipt request.',
                [
                    'request_id' => $requestId,
                    'user_id' => $user->id,
                    'citizen_id' => $citizen->id,
                    'payment_id' => $model->id,
                    'payment_number' => $model->payment_number,
                    'invoice_id' => $model->invoice_id,
                    'status' => $model->status,
                    'amount' => $model->amount,
                    'currency' => $model->currency,
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Receipt Availability
            |--------------------------------------------------------------------------
            */

            if ($model->status !== 'SUCCESS') {
                Log::warning(
                    'Payment receipt unavailable because payment is not successful.',
                    [
                        'request_id' => $requestId,
                        'user_id' => $user->id,
                        'citizen_id' => $citizen->id,
                        'payment_id' => $model->id,
                        'payment_number' => $model->payment_number,
                        'status' => $model->status,
                    ]
                );

                return ApiResponse::error(
                    message: 'Receipt is available only for successful payments.',
                    status: Response::HTTP_UNPROCESSABLE_ENTITY,
                    errorCode: 'RECEIPT_NOT_AVAILABLE'
                );
            }

            Log::info(
                'Taxpayer payment receipt retrieved successfully.',
                [
                    'request_id' => $requestId,
                    'user_id' => $user->id,
                    'citizen_id' => $citizen->id,
                    'payment_id' => $model->id,
                    'payment_number' => $model->payment_number,
                ]
            );

            return ApiResponse::success(
                data: new PaymentResource(
                    $model
                ),
                message: 'Payment receipt retrieved successfully.'
            );
        } catch (ModelNotFoundException) {
            Log::warning(
                'Taxpayer payment not found or does not belong to taxpayer for receipt.',
                [
                    'request_id' => $requestId,
                    'user_id' => $user->id,
                    'citizen_id' => $citizen->id,
                    'payment_id' => $payment,
                ]
            );

            return ApiResponse::notFound(
                'Payment not found.'
            );
        } catch (Throwable $e) {
            Log::error(
                'Taxpayer payment receipt service failed.',
                [
                    'request_id' => $requestId,
                    'user_id' => $user->id,
                    'citizen_id' => $citizen->id,
                    'payment_id' => $payment,
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