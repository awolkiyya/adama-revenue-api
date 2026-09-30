<?php

namespace App\Modules\Taxpayer\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Taxpayer\Resources\TaxpayerPaymentResource;
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
        | Resolve Pagination
        |--------------------------------------------------------------------------
        |
        | Laravel's paginator automatically reads the `page` query
        | parameter from the current HTTP request.
        |
        | Example:
        |
        | GET /api/v1/taxpayer/payments?page=2&per_page=100
        |
        | Therefore `page` is used for validation/logging here, but
        | it must NOT be passed to TaxpayerPaymentService::paginate()
        | unless that service explicitly accepts a page parameter.
        |
        */

        $perPage = min(
            max(
                (int) $request->input(
                    'per_page',
                    15
                ),
                1
            ),
            100
        );

        $page = max(
            (int) $request->input(
                'page',
                1
            ),
            1
        );

        Log::debug('Payment pagination parameters resolved.', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'citizen_id' => $citizen->id,
            'requested_page' => $request->input('page'),
            'resolved_page' => $page,
            'requested_per_page' => $request->input('per_page'),
            'resolved_per_page' => $perPage,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Payment History
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

        Log::debug(
            'Calling taxpayer payment service.',
            [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'citizen_id' => $citizen->id,
                'page' => $page,
                'per_page' => $perPage,
            ]
        );

        try {
            /*
             * IMPORTANT:
             *
             * Do not pass `page:` here.
             *
             * Laravel's paginator reads the current page directly
             * from the HTTP request.
             */
            $payments = $this->paymentService->paginate(
                citizenId: $citizen->id,
                perPage: $perPage
            );

            Log::debug(
                'Taxpayer payment service completed.',
                [
                    'request_id' => $requestId,
                    'user_id' => $user->id,
                    'citizen_id' => $citizen->id,
                    'payment_result_type' => is_object($payments)
                        ? get_class($payments)
                        : gettype($payments),
                    'payment_count' => method_exists(
                        $payments,
                        'count'
                    )
                        ? $payments->count()
                        : null,
                    'total' => method_exists(
                        $payments,
                        'total'
                    )
                        ? $payments->total()
                        : null,
                    'current_page' => method_exists(
                        $payments,
                        'currentPage'
                    )
                        ? $payments->currentPage()
                        : null,
                    'last_page' => method_exists(
                        $payments,
                        'lastPage'
                    )
                        ? $payments->lastPage()
                        : null,
                    'per_page' => method_exists(
                        $payments,
                        'perPage'
                    )
                        ? $payments->perPage()
                        : null,
                    'from' => method_exists(
                        $payments,
                        'firstItem'
                    )
                        ? $payments->firstItem()
                        : null,
                    'to' => method_exists(
                        $payments,
                        'lastItem'
                    )
                        ? $payments->lastItem()
                        : null,
                    'has_more' => method_exists(
                        $payments,
                        'hasMorePages'
                    )
                        ? $payments->hasMorePages()
                        : null,
                ]
            );

            Log::info(
                'Taxpayer payment history retrieved successfully.',
                [
                    'request_id' => $requestId,
                    'user_id' => $user->id,
                    'citizen_id' => $citizen->id,
                    'payment_count' => method_exists(
                        $payments,
                        'count'
                    )
                        ? $payments->count()
                        : null,
                    'total_payments' => method_exists(
                        $payments,
                        'total'
                    )
                        ? $payments->total()
                        : null,
                    'current_page' => method_exists(
                        $payments,
                        'currentPage'
                    )
                        ? $payments->currentPage()
                        : null,
                ]
            );

            return ApiResponse::success(
                data: TaxpayerPaymentResource::collection(
                    $payments
                ),
                message: 'Payment history retrieved successfully.'
            );
        } catch (Throwable $e) {
            Log::error(
                'Taxpayer payment service failed.',
                [
                    'request_id' => $requestId,
                    'user_id' => $user->id,
                    'citizen_id' => $citizen->id,
                    'page' => $page,
                    'per_page' => $perPage,
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
                data: new TaxpayerPaymentResource(
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
                data: new TaxpayerPaymentResource(
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