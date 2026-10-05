<?php

namespace App\Modules\Payment\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Modules\Payment\Resources\PaymentResource;
use App\Modules\Payment\Services\PaymentReceiptPdfService;
use App\Modules\Payment\Services\PaymentReceiptService;
use App\Services\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class PaymentManagementController extends Controller
{
    public function __construct(
        protected PaymentReceiptService $receiptService,
        protected PaymentReceiptPdfService $receiptPdfService,
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | List Payments
    |--------------------------------------------------------------------------
    |
    | Administrative payment listing.
    |
    | Payment is the common transaction record.
    | Method-specific fields are stored in:
    |
    | - cash_payment_details
    | - bank_transfer_details
    | - online_payment_details
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
            | Authorization
            |--------------------------------------------------------------------------
            |
            | Only users authorized to view payment records should
            | access the administrative payment listing.
            |
            */

            // $this->authorize(
            //     'viewAny',
            //     Payment::class
            // );

            /*
            |--------------------------------------------------------------------------
            | Build Query
            |--------------------------------------------------------------------------
            */

            $query = Payment::query();

            /*
            |--------------------------------------------------------------------------
            | Search
            |--------------------------------------------------------------------------
            |
            | Common identifiers:
            |
            | - payment_number
            | - transaction_reference
            |
            | Method-specific references:
            |
            | BANK:
            | - transfer_reference
            | - sender_name
            | - sender_account
            |
            | ONLINE:
            | - checkout_reference
            | - provider_transaction_id
            |
            */

            if ($request->filled('search')) {
                $search = $request
                    ->string('search')
                    ->toString();

                $query->where(function ($q) use ($search) {
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
                        ->orWhereHas(
                            'bankTransferDetails',
                            function ($bankQuery) use ($search) {
                                $bankQuery
                                    ->where(
                                        'transfer_reference',
                                        'like',
                                        "%{$search}%"
                                    )
                                    ->orWhere(
                                        'sender_name',
                                        'like',
                                        "%{$search}%"
                                    )
                                    ->orWhere(
                                        'sender_account',
                                        'like',
                                        "%{$search}%"
                                    );
                            }
                        )
                        ->orWhereHas(
                            'onlineDetails',
                            function ($onlineQuery) use ($search) {
                                $onlineQuery
                                    ->where(
                                        'checkout_reference',
                                        'like',
                                        "%{$search}%"
                                    )
                                    ->orWhere(
                                        'provider_transaction_id',
                                        'like',
                                        "%{$search}%"
                                    );
                            }
                        );
                });
            }

            /*
            |--------------------------------------------------------------------------
            | Transaction Reference
            |--------------------------------------------------------------------------
            */

            if ($request->filled('transaction_reference')) {
                $query->where(
                    'transaction_reference',
                    $request->input(
                        'transaction_reference'
                    )
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Invoice
            |--------------------------------------------------------------------------
            */

            if ($request->filled('invoice_id')) {
                $query->where(
                    'invoice_id',
                    $request->input('invoice_id')
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Citizen
            |--------------------------------------------------------------------------
            */

            if ($request->filled('citizen_id')) {
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

            if ($request->filled('payment_method')) {
                $query->where(
                    'payment_method',
                    $request->input('payment_method')
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Payment Source
            |--------------------------------------------------------------------------
            */

            if ($request->filled('payment_source')) {
                $query->where(
                    'payment_source',
                    $request->input('payment_source')
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Payment Status
            |--------------------------------------------------------------------------
            */

            if ($request->filled('status')) {
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

            if ($request->filled('currency')) {
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

            if ($request->filled('amount_from')) {
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

            if ($request->filled('amount_to')) {
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
            |
            | There is intentionally no generic payment_date.
            |
            | The actual transaction date depends on the payment
            | method:
            |
            | CASH:
            |     cash_received_at
            |
            | BANK:
            |     transfer_date
            |
            | ONLINE:
            |     paid_at
            |
            */

            if ($request->filled('payment_date_from')) {
                $dateFrom =
                    $request->input(
                        'payment_date_from'
                    );

                $query->where(function ($q) use ($dateFrom) {
                    $q
                        ->whereHas(
                            'cashDetails',
                            function ($cashQuery) use ($dateFrom) {
                                $cashQuery->whereDate(
                                    'cash_received_at',
                                    '>=',
                                    $dateFrom
                                );
                            }
                        )
                        ->orWhereHas(
                            'bankTransferDetails',
                            function ($bankQuery) use ($dateFrom) {
                                $bankQuery->whereDate(
                                    'transfer_date',
                                    '>=',
                                    $dateFrom
                                );
                            }
                        )
                        ->orWhereHas(
                            'onlineDetails',
                            function ($onlineQuery) use ($dateFrom) {
                                $onlineQuery->whereDate(
                                    'paid_at',
                                    '>=',
                                    $dateFrom
                                );
                            }
                        );
                });
            }

            /*
            |--------------------------------------------------------------------------
            | Payment Date To
            |--------------------------------------------------------------------------
            */

            if ($request->filled('payment_date_to')) {
                $dateTo =
                    $request->input(
                        'payment_date_to'
                    );

                $query->where(function ($q) use ($dateTo) {
                    $q
                        ->whereHas(
                            'cashDetails',
                            function ($cashQuery) use ($dateTo) {
                                $cashQuery->whereDate(
                                    'cash_received_at',
                                    '<=',
                                    $dateTo
                                );
                            }
                        )
                        ->orWhereHas(
                            'bankTransferDetails',
                            function ($bankQuery) use ($dateTo) {
                                $bankQuery->whereDate(
                                    'transfer_date',
                                    '<=',
                                    $dateTo
                                );
                            }
                        )
                        ->orWhereHas(
                            'onlineDetails',
                            function ($onlineQuery) use ($dateTo) {
                                $onlineQuery->whereDate(
                                    'paid_at',
                                    '<=',
                                    $dateTo
                                );
                            }
                        );
                });
            }

            /*
            |--------------------------------------------------------------------------
            | Verification Date From
            |--------------------------------------------------------------------------
            */

            if ($request->filled('verified_from')) {
                $query->whereDate(
                    'verified_at',
                    '>=',
                    $request->input('verified_from')
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Verification Date To
            |--------------------------------------------------------------------------
            */

            if ($request->filled('verified_to')) {
                $query->whereDate(
                    'verified_at',
                    '<=',
                    $request->input('verified_to')
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Sorting
            |--------------------------------------------------------------------------
            */

            $allowedSorts = [
                'created_at',
                'amount',
                'status',
                'payment_method',
                'payment_source',
                'currency',
                'verified_at',
                'payment_number',
            ];

            $sortBy = $request->input(
                'sort_by',
                'created_at'
            );

            if (! in_array(
                $sortBy,
                $allowedSorts,
                true
            )) {
                $sortBy = 'created_at';
            }

            $sortDirection = $request->input(
                'sort_direction',
                'desc'
            );

            if (! in_array(
                $sortDirection,
                [
                    'asc',
                    'desc',
                ],
                true
            )) {
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

            $perPage = (int) $request->input(
                'per_page',
                25
            );

            $perPage = min(
                max($perPage, 1),
                100
            );

            /*
            |--------------------------------------------------------------------------
            | Eager Loading
            |--------------------------------------------------------------------------
            */

            $query->with([
                'invoice',
                'citizen',

                'processedBy',
                'verifiedBy',

                'cashDetails.receivedBy',

                'bankTransferDetails.bankAccount',
                'bankTransferDetails.verifiedBy',
                'bankTransferDetails.files',

                'onlineDetails.paymentProvider',

                'receipt.issuedBy',
            ]);

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

    /*
    |--------------------------------------------------------------------------
    | Show Payment
    |--------------------------------------------------------------------------
    |
    | Returns the complete administrative view of a single payment.
    |
    */

    public function show(
        Request $request,
        string $payment
    ): JsonResponse {
        $requestId =
            $request->header('X-Request-ID')
            ?? (string) Str::uuid();

        Log::info(
            'Payment management detail request started.',
            [
                'request_id' =>
                    $requestId,

                'user_id' =>
                    $request->user()?->id,

                'payment_id' =>
                    $payment,

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
                    'Payment management detail rejected because the user is unauthenticated.',
                    [
                        'request_id' =>
                            $requestId,

                        'payment_id' =>
                            $payment,

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
            | Retrieve Payment
            |--------------------------------------------------------------------------
            |
            | Use the common Payment model and load all relationships
            | needed by the administrative detail/resource response.
            |
            */

            $paymentModel = Payment::query()
                ->with([
                    'invoice',
                    'citizen',
                    'processedBy',
                    'verifiedBy',
            
                    // Cash payment
                    'cashDetails.receivedBy',
            
                    // Bank transfer payment
                    'bankTransferDetails.bankAccount',
                    'bankTransferDetails.verifiedBy',
                    'bankTransferDetails.files',
            
                    // Online payment
                    'onlineDetails.paymentProvider',
            
                    // Receipt
                    'receipt.issuedBy',
                ])
                ->find($payment);
            
            

            if (! $paymentModel) {
                Log::warning(
                    'Payment management detail failed because the payment was not found.',
                    [
                        'request_id' =>
                            $requestId,

                        'user_id' =>
                            $user->id,

                        'payment_id' =>
                            $payment,
                    ]
                );

                return ApiResponse::error(
                    'Payment not found.',
                    404
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Authorization
            |--------------------------------------------------------------------------
            */

            // $this->authorize(
            //     'view',
            //     $paymentModel
            // );

            /*
            |--------------------------------------------------------------------------
            | Response
            |--------------------------------------------------------------------------
            */

            Log::info(
                'Payment management detail retrieved successfully.',
                [
                    'request_id' =>
                        $requestId,

                    'user_id' =>
                        $user->id,

                    'payment_id' =>
                        $paymentModel->id,

                    'payment_number' =>
                        $paymentModel->payment_number,

                    'status' =>
                        $this->safeEnumValue(
                            $paymentModel->status
                        ),
                ]
            );

            return ApiResponse::success(
                new PaymentResource(
                    $paymentModel
                ),
                'Payment retrieved successfully.'
            );
        } catch (Throwable $exception) {
            Log::error(
                'Payment management detail retrieval failed.',
                [
                    'request_id' =>
                        $requestId,

                    'user_id' =>
                        $request->user()?->id,

                    'payment_id' =>
                        $payment,

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
                'Unable to retrieve payment.',
                500
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Retrieve Payment Receipt
    |--------------------------------------------------------------------------
    |
    | Returns the official receipt record for a completed payment.
    |
    | This endpoint does NOT create a receipt.
    |
    */

    public function receipt(
        Request $request,
        string $payment
    ): JsonResponse {
        $requestId =
            $request->header('X-Request-ID')
            ?? (string) Str::uuid();

        Log::info(
            'Payment receipt retrieval request started.',
            [
                'request_id' =>
                    $requestId,

                'user_id' =>
                    $request->user()?->id,

                'payment_id' =>
                    $payment,

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
                    'Payment receipt retrieval rejected because the user is unauthenticated.',
                    [
                        'request_id' =>
                            $requestId,

                        'payment_id' =>
                            $payment,

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
            | Retrieve Payment
            |--------------------------------------------------------------------------
            */

            $paymentModel =
                Payment::query()
                    ->with([
                        'receipt.issuedBy',
                    ])
                    ->find($payment);

            if (! $paymentModel) {
                Log::warning(
                    'Payment receipt retrieval failed because the payment was not found.',
                    [
                        'request_id' =>
                            $requestId,

                        'user_id' =>
                            $user->id,

                        'payment_id' =>
                            $payment,
                    ]
                );

                return ApiResponse::error(
                    'Payment not found.',
                    404
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Authorization
            |--------------------------------------------------------------------------
            */

            // $this->authorize(
            //     'view',
            //     $paymentModel
            // );

            /*
            |--------------------------------------------------------------------------
            | Payment Status
            |--------------------------------------------------------------------------
            */

            if (! $paymentModel->isSuccessful()) {
                Log::info(
                    'Payment receipt unavailable because the payment is not completed.',
                    [
                        'request_id' =>
                            $requestId,

                        'user_id' =>
                            $user->id,

                        'payment_id' =>
                            $paymentModel->id,

                        'payment_number' =>
                            $paymentModel->payment_number,

                        'status' =>
                            $this->safeEnumValue(
                                $paymentModel->status
                            ),
                    ]
                );

                return ApiResponse::error(
                    'A receipt is only available for a completed payment.',
                    422
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Retrieve Existing Receipt
            |--------------------------------------------------------------------------
            |
            | getReceipt() intentionally does not create a receipt.
            | A missing receipt for a completed payment is a data
            | integrity problem.
            |
            */

            $receipt =
                $this->receiptService->getReceipt(
                    $paymentModel
                );

            /*
            |--------------------------------------------------------------------------
            | Response
            |--------------------------------------------------------------------------
            */

            Log::info(
                'Payment receipt retrieved successfully.',
                [
                    'request_id' =>
                        $requestId,

                    'user_id' =>
                        $user->id,

                    'payment_id' =>
                        $paymentModel->id,

                    'payment_number' =>
                        $paymentModel->payment_number,

                    'receipt_id' =>
                        $receipt->id,

                    'receipt_number' =>
                        $receipt->receipt_number,

                    'issued_at' =>
                        $receipt->issued_at,
                ]
            );

            return ApiResponse::success(
                $receipt,
                'Payment receipt retrieved successfully.'
            );
        } catch (Throwable $exception) {
            Log::error(
                'Payment receipt retrieval failed.',
                [
                    'request_id' =>
                        $requestId,

                    'user_id' =>
                        $request->user()?->id,

                    'payment_id' =>
                        $payment,

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
                'Unable to retrieve payment receipt.',
                500
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Download Payment Receipt PDF
    |--------------------------------------------------------------------------
    |
    | Downloads the official receipt as an A4 PDF document.
    |
    | The PDF service does not create the receipt.
    |
    */

    public function receiptPdf(
        Request $request,
        string $payment
    ): Response {
        $requestId =
            $request->header('X-Request-ID')
            ?? (string) Str::uuid();

        Log::info(
            'Payment receipt PDF download request started.',
            [
                'request_id' =>
                    $requestId,

                'user_id' =>
                    $request->user()?->id,

                'payment_id' =>
                    $payment,

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
                    'Payment receipt PDF download rejected because the user is unauthenticated.',
                    [
                        'request_id' =>
                            $requestId,

                        'payment_id' =>
                            $payment,

                        'ip' =>
                            $request->ip(),
                    ]
                );

                abort(
                    401,
                    'Authentication is required.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Retrieve Payment
            |--------------------------------------------------------------------------
            */

            $paymentModel =
                Payment::query()
                    ->with([
                        'receipt.issuedBy',
                        'invoice',
                        'citizen',
                        'bankTransferDetails',
                        'onlineDetails',
                    ])
                    ->find($payment);

            if (! $paymentModel) {
                Log::warning(
                    'Payment receipt PDF download failed because the payment was not found.',
                    [
                        'request_id' =>
                            $requestId,

                        'user_id' =>
                            $user->id,

                        'payment_id' =>
                            $payment,
                    ]
                );

                abort(
                    404,
                    'Payment not found.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Authorization
            |--------------------------------------------------------------------------
            */

            // $this->authorize(
            //     'view',
            //     $paymentModel
            // );

            /*
            |--------------------------------------------------------------------------
            | Generate and Download
            |--------------------------------------------------------------------------
            */

            Log::info(
                'Payment receipt PDF download started.',
                [
                    'request_id' =>
                        $requestId,

                    'user_id' =>
                        $user->id,

                    'payment_id' =>
                        $paymentModel->id,

                    'payment_number' =>
                        $paymentModel->payment_number,

                    'receipt_number' =>
                        $paymentModel->receipt?->receipt_number,
                ]
            );

            return $this->receiptPdfService->download(
                $paymentModel
            );
        } catch (Throwable $exception) {
            Log::error(
                'Payment receipt PDF download failed.',
                [
                    'request_id' =>
                        $requestId,

                    'user_id' =>
                        $request->user()?->id,

                    'payment_id' =>
                        $payment,

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

            abort(
                500,
                'Unable to generate payment receipt PDF.'
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Stream Payment Receipt PDF
    |--------------------------------------------------------------------------
    |
    | Opens the official receipt PDF in the browser.
    |
    */

    public function receiptPdfStream(
        Request $request,
        string $payment
    ): Response {
        $requestId =
            $request->header('X-Request-ID')
            ?? (string) Str::uuid();

        Log::info(
            'Payment receipt PDF stream request started.',
            [
                'request_id' =>
                    $requestId,

                'user_id' =>
                    $request->user()?->id,

                'payment_id' =>
                    $payment,

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
                    'Payment receipt PDF stream rejected because the user is unauthenticated.',
                    [
                        'request_id' =>
                            $requestId,

                        'payment_id' =>
                            $payment,

                        'ip' =>
                            $request->ip(),
                    ]
                );

                abort(
                    401,
                    'Authentication is required.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Retrieve Payment
            |--------------------------------------------------------------------------
            */

            $paymentModel =
                Payment::query()
                    ->with([
                        'receipt.issuedBy',
                        'invoice',
                        'citizen',
                        'bankTransferDetails',
                        'onlineDetails',
                    ])
                    ->find($payment);

            if (! $paymentModel) {
                Log::warning(
                    'Payment receipt PDF stream failed because the payment was not found.',
                    [
                        'request_id' =>
                            $requestId,

                        'user_id' =>
                            $user->id,

                        'payment_id' =>
                            $payment,
                    ]
                );

                abort(
                    404,
                    'Payment not found.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Authorization
            |--------------------------------------------------------------------------
            */

            // $this->authorize(
            //     'view',
            //     $paymentModel
            // );

            /*
            |--------------------------------------------------------------------------
            | Stream PDF
            |--------------------------------------------------------------------------
            */

            Log::info(
                'Payment receipt PDF stream started.',
                [
                    'request_id' =>
                        $requestId,

                    'user_id' =>
                        $user->id,

                    'payment_id' =>
                        $paymentModel->id,

                    'payment_number' =>
                        $paymentModel->payment_number,

                    'receipt_number' =>
                        $paymentModel->receipt?->receipt_number,
                ]
            );

            return $this->receiptPdfService->stream(
                $paymentModel
            );
        } catch (Throwable $exception) {
            Log::error(
                'Payment receipt PDF stream failed.',
                [
                    'request_id' =>
                        $requestId,

                    'user_id' =>
                        $request->user()?->id,

                    'payment_id' =>
                        $payment,

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

            abort(
                500,
                'Unable to generate payment receipt PDF.'
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Safe Enum Value
    |--------------------------------------------------------------------------
    |
    | Converts backed/unit enums to their API/log-friendly values.
    |
    */

    protected function safeEnumValue(
        mixed $value
    ): ?string {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if ($value instanceof \UnitEnum) {
            return $value->name;
        }

        return (string) $value;
    }
}