<?php

namespace App\Modules\Payment\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Modules\Payment\Resources\PaymentResource;
use App\Services\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class PaymentManagementController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | List Payments
    |--------------------------------------------------------------------------
    |
    | Administrative payment listing.
    |
    | This endpoint is intended for authorized municipal staff.
    |
    | Unlike PaymentController::show(), this endpoint is NOT limited
    | to the authenticated user's own payments.
    |
    */

    public function index(
        Request $request
    ): JsonResponse {

        $requestId =
            $request->header('X-Request-ID')
            ?? (string) Str::uuid();

        Log::info(
            'Payment management listing request started.',
            [
                'request_id' =>
                    $requestId,

                'user_id' =>
                    $request->user()?->id,

                'filters' =>
                    $request->query(),

                'ip' =>
                    $request->ip(),

                'user_agent' =>
                    $request->userAgent(),
            ]
        );

        try {

            /*
            |--------------------------------------------------------------------------
            | Authentication
            |--------------------------------------------------------------------------
            */

            $user = $request->user();

            if (! $user) {

                Log::warning(
                    'Payment management listing rejected because the user is unauthenticated.',
                    [
                        'request_id' =>
                            $requestId,

                        'ip' =>
                            $request->ip(),
                    ]
                );

                return ApiResponse::error(
                    'Authentication is required.',
                    401
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Build Query
            |--------------------------------------------------------------------------
            */

            $query =
                Payment::query();

            /*
            |--------------------------------------------------------------------------
            | Search
            |--------------------------------------------------------------------------
            |
            | Searches payment identifiers.
            |
            | payment_number
            | transaction_reference
            | provider_reference
            |
            */

            if ($request->filled('search')) {

                $search =
                    $request
                        ->string('search')
                        ->toString();

                $query->where(
                    function ($q) use ($search) {

                        $q
                            ->where(
                                'payment_number',
                                'like',
                                "%{$search}%"
                            )
                            ->orWhere(
                                'transaction_reference',
                                'like',
                                "%{$search}%"
                            )
                            ->orWhere(
                                'provider_reference',
                                'like',
                                "%{$search}%"
                            );
                    }
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Transaction Reference
            |--------------------------------------------------------------------------
            */

            if (
                $request->filled(
                    'transaction_reference'
                )
            ) {

                $query->where(
                    'transaction_reference',
                    $request->input(
                        'transaction_reference'
                    )
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Provider Reference
            |--------------------------------------------------------------------------
            */

            if (
                $request->filled(
                    'provider_reference'
                )
            ) {

                $query->where(
                    'provider_reference',
                    $request->input(
                        'provider_reference'
                    )
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Invoice
            |--------------------------------------------------------------------------
            */

            if (
                $request->filled('invoice_id')
            ) {

                $query->where(
                    'invoice_id',
                    $request->input('invoice_id')
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Assessment
            |--------------------------------------------------------------------------
            */

            if (
                $request->filled('assessment_id')
            ) {

                $query->where(
                    'assessment_id',
                    $request->input('assessment_id')
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Citizen
            |--------------------------------------------------------------------------
            */

            if (
                $request->filled('citizen_id')
            ) {

                $query->where(
                    'citizen_id',
                    $request->input('citizen_id')
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Payment Method
            |--------------------------------------------------------------------------
            */

            if (
                $request->filled('payment_method')
            ) {

                $query->where(
                    'payment_method',
                    $request->input(
                        'payment_method'
                    )
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Payment Provider
            |--------------------------------------------------------------------------
            */

            if (
                $request->filled('payment_provider')
            ) {

                $query->where(
                    'payment_provider',
                    $request->input(
                        'payment_provider'
                    )
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Payment Status
            |--------------------------------------------------------------------------
            */

            if (
                $request->filled('status')
            ) {

                $query->where(
                    'status',
                    $request->input('status')
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Currency
            |--------------------------------------------------------------------------
            */

            if (
                $request->filled('currency')
            ) {

                $query->where(
                    'currency',
                    $request->input('currency')
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Amount From
            |--------------------------------------------------------------------------
            */

            if (
                $request->filled('amount_from')
            ) {

                $query->where(
                    'amount',
                    '>=',
                    $request->input('amount_from')
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Amount To
            |--------------------------------------------------------------------------
            */

            if (
                $request->filled('amount_to')
            ) {

                $query->where(
                    'amount',
                    '<=',
                    $request->input('amount_to')
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Payment Date From
            |--------------------------------------------------------------------------
            */

            if (
                $request->filled(
                    'payment_date_from'
                )
            ) {

                $query->whereDate(
                    'payment_date',
                    '>=',
                    $request->input(
                        'payment_date_from'
                    )
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Payment Date To
            |--------------------------------------------------------------------------
            */

            if (
                $request->filled(
                    'payment_date_to'
                )
            ) {

                $query->whereDate(
                    'payment_date',
                    '<=',
                    $request->input(
                        'payment_date_to'
                    )
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Verification Date From
            |--------------------------------------------------------------------------
            */

            if (
                $request->filled(
                    'verified_from'
                )
            ) {

                $query->whereDate(
                    'verified_at',
                    '>=',
                    $request->input(
                        'verified_from'
                    )
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Verification Date To
            |--------------------------------------------------------------------------
            */

            if (
                $request->filled(
                    'verified_to'
                )
            ) {

                $query->whereDate(
                    'verified_at',
                    '<=',
                    $request->input(
                        'verified_to'
                    )
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Sorting
            |--------------------------------------------------------------------------
            |
            | Never allow arbitrary column names from the request.
            |
            */

            $allowedSorts = [
                'created_at',
                'payment_date',
                'amount',
                'status',
                'payment_method',
                'payment_provider',
                'verified_at',
            ];

            $sortBy =
                $request->input(
                    'sort_by',
                    'created_at'
                );

            if (
                ! in_array(
                    $sortBy,
                    $allowedSorts,
                    true
                )
            ) {
                $sortBy = 'created_at';
            }

            /*
            |--------------------------------------------------------------------------
            | Sort Direction
            |--------------------------------------------------------------------------
            */

            $sortDirection =
                $request->input(
                    'sort_direction',
                    'desc'
                );

            if (
                ! in_array(
                    $sortDirection,
                    [
                        'asc',
                        'desc',
                    ],
                    true
                )
            ) {
                $sortDirection = 'desc';
            }

            $query->orderBy(
                $sortBy,
                $sortDirection
            );

            /*
            |--------------------------------------------------------------------------
            | Pagination
            |--------------------------------------------------------------------------
            */

            $perPage =
                (int) $request->input(
                    'per_page',
                    25
                );

            /*
            |--------------------------------------------------------------------------
            | Protect API From Excessive Page Size
            |--------------------------------------------------------------------------
            */

            $perPage =
                min(
                    max($perPage, 1),
                    100
                );

            /*
            |--------------------------------------------------------------------------
            | Execute Query
            |--------------------------------------------------------------------------
            */

            $payments =
                $query->paginate(
                    $perPage
                );

            /*
            |--------------------------------------------------------------------------
            | Transform Resources
            |--------------------------------------------------------------------------
            */

            $paymentData =
                PaymentResource::collection(
                    $payments
                );

            /*
            |--------------------------------------------------------------------------
            | Response
            |--------------------------------------------------------------------------
            */

            Log::info(
                'Payment management listing completed successfully.',
                [
                    'request_id' =>
                        $requestId,

                    'user_id' =>
                        $user->id,

                    'current_page' =>
                        $payments->currentPage(),

                    'per_page' =>
                        $payments->perPage(),

                    'last_page' =>
                        $payments->lastPage(),

                    'total' =>
                        $payments->total(),
                ]
            );

            return response()->json([
                'success' => true,

                'message' =>
                    'Payments retrieved successfully.',

                'data' =>
                    $paymentData->collection,

                'meta' => [
                    'current_page' =>
                        $payments->currentPage(),

                    'per_page' =>
                        $payments->perPage(),

                    'last_page' =>
                        $payments->lastPage(),

                    'total' =>
                        $payments->total(),

                    'timestamp' =>
                        now()->toIso8601String(),

                    'request_id' =>
                        $requestId,

                    'version' =>
                        'v1',
                ],
            ]);

        } catch (Throwable $exception) {

            Log::error(
                'Payment management listing failed.',
                [
                    'request_id' =>
                        $requestId,

                    'user_id' =>
                        $request->user()?->id,

                    'filters' =>
                        $request->query(),

                    'exception' =>
                        $exception::class,

                    'message' =>
                        $exception->getMessage(),

                    'file' =>
                        $exception->getFile(),

                    'line' =>
                        $exception->getLine(),

                    'trace' =>
                        $exception->getTraceAsString(),
                ]
            );

            return ApiResponse::error(
                'Unable to retrieve payments.',
                500
            );
        }
    }
}