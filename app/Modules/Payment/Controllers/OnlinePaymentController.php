<?php

declare(strict_types=1);

namespace App\Modules\Payment\Controllers;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Payment;
use App\Modules\Payment\DTOs\InitializePaymentData;
use App\Modules\Payment\Requests\InitializePaymentRequest;
use App\Modules\Payment\Services\OnlinePaymentService;
use App\Services\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class OnlinePaymentController extends Controller
{
    public function __construct(
        protected OnlinePaymentService $onlinePaymentService,
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | Initialize Online Payment
    |--------------------------------------------------------------------------
    |
    | Frontend sends only:
    |
    | {
    |     "invoice_id": "...",
    |     "amount": 29423.0475,
    |     "payment_provider": "TELEBIRR"
    | }
    |
    | The authenticated user may be:
    |
    | - the taxpayer
    | - an authorized employee/agent paying on behalf of the taxpayer
    |
    | The invoice determines the taxpayer through:
    |
    |     invoices.citizen_id
    |
    | This endpoint only initializes the payment.
    |
    | Successful initialization does NOT mean the payment is completed.
    |
    */

    public function initialize(
        InitializePaymentRequest $request
    ): JsonResponse {
        /*
        |--------------------------------------------------------------------------
        | Request ID
        |--------------------------------------------------------------------------
        */

        $requestId =
            $request->header('X-Request-ID')
            ?? (string) Str::uuid();

        /*
        |--------------------------------------------------------------------------
        | Validated Request Data
        |--------------------------------------------------------------------------
        */

        $validated = $request->validated();

        /*
        |--------------------------------------------------------------------------
        | Request Started
        |--------------------------------------------------------------------------
        */

        Log::info(
            'Online payment initialization request started.',
            [
                'request_id' =>
                    $requestId,

                /*
                 * The authenticated employee/user is the person
                 * initiating the payment.
                 *
                 * This may be the taxpayer or an authorized agent.
                 */
                'initiated_by_user_id' =>
                    $request->user()?->id,

                'authenticated' =>
                    $request->user() !== null,

                'route' =>
                    $request->path(),

                'http_method' =>
                    $request->method(),

                'ip' =>
                    $request->ip(),

                'user_agent' =>
                    $request->userAgent(),

                /*
                 * Only safe validated request fields are logged.
                 */
                'request_data' =>
                    $this->safeRequestData(
                        $validated
                    ),
            ]
        );

        try {
            /*
            |--------------------------------------------------------------------------
            | Resolve Authenticated User
            |--------------------------------------------------------------------------
            */

            $user = $request->user();

            Log::info(
                'Online payment authentication resolved.',
                [
                    'request_id' =>
                        $requestId,

                    'authenticated' =>
                        $user !== null,

                    'user_id' =>
                        $user?->id,

                    'user_type' =>
                        $user?->user_type,
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Authentication Required
            |--------------------------------------------------------------------------
            */

            if (! $user) {
                Log::warning(
                    'Online payment initialization rejected because the user is unauthenticated.',
                    [
                        'request_id' =>
                            $requestId,

                        'ip' =>
                            $request->ip(),
                    ]
                );

                return ApiResponse::error(
                    'Authentication is required to initialize a payment.',
                    401
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Resolve Invoice
            |--------------------------------------------------------------------------
            |
            | The invoice is the source of truth for the taxpayer.
            |
            | invoices.citizen_id -> taxpayers/citizens
            |
            */

            $invoice = Invoice::query()
                ->with('citizen')
                ->find(
                    $validated['invoice_id']
                );

            /*
            |--------------------------------------------------------------------------
            | Invoice Not Found
            |--------------------------------------------------------------------------
            */

            if (! $invoice) {
                Log::warning(
                    'Online payment initialization rejected because the invoice was not found.',
                    [
                        'request_id' =>
                            $requestId,

                        'initiated_by_user_id' =>
                            $user->id,

                        'invoice_id' =>
                            $validated['invoice_id'],
                    ]
                );

                return ApiResponse::error(
                    'The selected invoice does not exist.',
                    404
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Resolve Taxpayer
            |--------------------------------------------------------------------------
            */

            $taxpayerId =
                $invoice->citizen_id;

            /*
            |--------------------------------------------------------------------------
            | Taxpayer Required
            |--------------------------------------------------------------------------
            */

            if (! $taxpayerId) {
                Log::error(
                    'Online payment initialization rejected because the invoice has no taxpayer.',
                    [
                        'request_id' =>
                            $requestId,

                        'initiated_by_user_id' =>
                            $user->id,

                        'invoice_id' =>
                            $invoice->id,

                        'invoice_number' =>
                            $invoice->invoice_number,
                    ]
                );

                return ApiResponse::error(
                    'The invoice is not associated with a taxpayer.',
                    422
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Taxpayer Relationship Validation
            |--------------------------------------------------------------------------
            */

            if (! $invoice->citizen) {
                Log::error(
                    'Online payment initialization rejected because the invoice taxpayer record was not found.',
                    [
                        'request_id' =>
                            $requestId,

                        'initiated_by_user_id' =>
                            $user->id,

                        'invoice_id' =>
                            $invoice->id,

                        'taxpayer_id' =>
                            $taxpayerId,
                    ]
                );

                return ApiResponse::error(
                    'The taxpayer associated with this invoice could not be found.',
                    422
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Payment Authorization
            |--------------------------------------------------------------------------
            |
            | Authentication alone does NOT establish that the user
            | is allowed to initiate payment for this invoice.
            |
            | Your existing Policy / Gate / permission / business
            | authorization layer should determine whether this
            | authenticated user can:
            |
            | 1. pay their own invoice, or
            | 2. act as an authorized employee/agent for the taxpayer.
            |
            | Do NOT accept citizen_id/taxpayer_id from the frontend.
            |
            | The invoice determines the taxpayer.
            |
            */

            /*
            |--------------------------------------------------------------------------
            | Resolve Payment Amount
            |--------------------------------------------------------------------------
            */

            $amount =
                $validated['amount'];

            /*
            |--------------------------------------------------------------------------
            | Defensive Amount Validation
            |--------------------------------------------------------------------------
            */

            if (
                ! is_numeric($amount)
                ||
                (float) $amount <= 0
            ) {
                Log::warning(
                    'Online payment initialization rejected because the payment amount is invalid.',
                    [
                        'request_id' =>
                            $requestId,

                        'initiated_by_user_id' =>
                            $user->id,

                        'taxpayer_id' =>
                            $taxpayerId,

                        'invoice_id' =>
                            $invoice->id,

                        'amount' =>
                            $amount,
                    ]
                );

                return ApiResponse::error(
                    'Payment amount must be greater than zero.',
                    422
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Normalize Amount
            |--------------------------------------------------------------------------
            |
            | Provider boundary uses two decimal places.
            |
            | The PaymentService/domain layer must still verify that
            | the requested amount does not exceed the invoice balance.
            |
            */

            $amount =
                $this->normalizePaymentAmount(
                    $amount
                );

            /*
            |--------------------------------------------------------------------------
            | Server-Controlled Currency
            |--------------------------------------------------------------------------
            */

            $currency =
                $invoice->currency ?? 'ETB';

            /*
            |--------------------------------------------------------------------------
            | Payment Provider
            |--------------------------------------------------------------------------
            */

            $paymentProvider =
                $validated['payment_provider'];

            /*
            |--------------------------------------------------------------------------
            | Payment Method
            |--------------------------------------------------------------------------
            */

            $paymentMethod =
                PaymentMethod::ONLINE;

            /*
            |--------------------------------------------------------------------------
            | Log Financial Parameters
            |--------------------------------------------------------------------------
            */

            Log::info(
                'Online payment financial parameters resolved.',
                [
                    'request_id' =>
                        $requestId,

                    'initiated_by_user_id' =>
                        $user->id,

                    'taxpayer_id' =>
                        $taxpayerId,

                    'invoice_id' =>
                        $invoice->id,

                    'amount' =>
                        $amount,

                    'currency' =>
                        $currency,

                    'payment_method' =>
                        $this->safeEnumValue(
                            $paymentMethod
                        ),

                    'payment_provider' =>
                        $this->safeEnumValue(
                            $paymentProvider
                        ),
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Resolve Chapa Callback / Return URLs
            |--------------------------------------------------------------------------
            */

            $callbackUrl =
                config('services.chapa.callback_url');

            $returnUrl =
                config('services.chapa.return_url');

            /*
            |--------------------------------------------------------------------------
            | Validate Callback URL Configuration
            |--------------------------------------------------------------------------
            */

            if (! filled($callbackUrl)) {
                Log::critical(
                    'CHAPA_CALLBACK_URL is not configured.',
                    [
                        'request_id' =>
                            $requestId,

                        'initiated_by_user_id' =>
                            $user->id,

                        'invoice_id' =>
                            $invoice->id,

                        'provider' =>
                            $this->safeEnumValue(
                                $paymentProvider
                            ),
                    ]
                );

                return ApiResponse::error(
                    'Online payment callback URL is not configured.',
                    500
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Validate Return URL Configuration
            |--------------------------------------------------------------------------
            */

            if (! filled($returnUrl)) {
                Log::critical(
                    'CHAPA_RETURN_URL is not configured.',
                    [
                        'request_id' =>
                            $requestId,

                        'initiated_by_user_id' =>
                            $user->id,

                        'invoice_id' =>
                            $invoice->id,

                        'provider' =>
                            $this->safeEnumValue(
                                $paymentProvider
                            ),
                    ]
                );

                return ApiResponse::error(
                    'Online payment return URL is not configured.',
                    500
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Validate URL Format
            |--------------------------------------------------------------------------
            */

            if (! filter_var($callbackUrl, FILTER_VALIDATE_URL)) {
                Log::critical(
                    'Configured Chapa callback URL is invalid.',
                    [
                        'request_id' =>
                            $requestId,

                        'callback_url' =>
                            $callbackUrl,
                    ]
                );

                return ApiResponse::error(
                    'Online payment callback URL is invalid.',
                    500
                );
            }

            if (! filter_var($returnUrl, FILTER_VALIDATE_URL)) {
                Log::critical(
                    'Configured Chapa return URL is invalid.',
                    [
                        'request_id' =>
                            $requestId,

                        'return_url' =>
                            $returnUrl,
                    ]
                );

                return ApiResponse::error(
                    'Online payment return URL is invalid.',
                    500
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Log Provider URLs
            |--------------------------------------------------------------------------
            */

            Log::info(
                'Online payment provider URLs resolved from backend configuration.',
                [
                    'request_id' =>
                        $requestId,

                    'provider' =>
                        $this->safeEnumValue(
                            $paymentProvider
                        ),

                    'callback_url' =>
                        $callbackUrl,

                    'return_url' =>
                        $returnUrl,
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Generate Internal Transaction Reference
            |--------------------------------------------------------------------------
            */

            $paymentReference =
                'PAY-' .
                strtoupper(
                    Str::ulid()->toBase32()
                );

            Log::info(
                'Internal online payment transaction reference generated.',
                [
                    'request_id' =>
                        $requestId,

                    'payment_reference' =>
                        $paymentReference,

                    'invoice_id' =>
                        $invoice->id,

                    'taxpayer_id' =>
                        $taxpayerId,

                    'initiated_by_user_id' =>
                        $user->id,
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Prepare DTO Data
            |--------------------------------------------------------------------------
            */

            $dtoData = [
                'invoice_id' =>
                    $invoice->id,

                'citizen_id' =>
                    $taxpayerId,

                'payment_reference' =>
                    $paymentReference,

                'method' =>
                    $paymentMethod,

                'provider' =>
                    $paymentProvider,

                'amount' =>
                    $amount,

                'currency' =>
                    $currency,

                'initiated_by_user_id' =>
                    $user->id,

                'customer_name' =>
                    null,

                'customer_email' =>
                    null,

                'customer_phone' =>
                    null,

                'return_url' =>
                    $returnUrl,

                'callback_url' =>
                    $callbackUrl,

                'description' =>
                    'Payment for invoice ' .
                    $invoice->invoice_number,

                'metadata' => [
                    'invoice_id' =>
                        $invoice->id,

                    'taxpayer_id' =>
                        $taxpayerId,

                    'initiated_by_user_id' =>
                        $user->id,

                    'request_id' =>
                        $requestId,
                ],
            ];

            /*
            |--------------------------------------------------------------------------
            | Log DTO Preparation
            |--------------------------------------------------------------------------
            */

            Log::info(
                'Preparing online payment DTO.',
                [
                    'request_id' =>
                        $requestId,

                    'invoice_id' =>
                        $invoice->id,

                    'taxpayer_id' =>
                        $taxpayerId,

                    'initiated_by_user_id' =>
                        $user->id,

                    'payment_reference' =>
                        $paymentReference,

                    'amount' =>
                        $amount,

                    'currency' =>
                        $currency,

                    'provider' =>
                        $this->safeEnumValue(
                            $paymentProvider
                        ),

                    'method' =>
                        $this->safeEnumValue(
                            $paymentMethod
                        ),

                    'callback_url' =>
                        $callbackUrl,

                    'return_url' =>
                        $returnUrl,
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Create DTO
            |--------------------------------------------------------------------------
            */

            $data =
                InitializePaymentData::fromArray(
                    $dtoData
                );

            /*
            |--------------------------------------------------------------------------
            | DTO Created
            |--------------------------------------------------------------------------
            */

            Log::info(
                'Online payment DTO created.',
                [
                    'request_id' =>
                        $requestId,

                    'payment_reference' =>
                        $data->paymentReference,

                    'invoice_id' =>
                        $data->invoiceId,

                    'taxpayer_id' =>
                        $data->citizenId,

                    'provider' =>
                        $this->safeEnumValue(
                            $data->provider
                        ),

                    'method' =>
                        $this->safeEnumValue(
                            $data->method
                        ),

                    'amount' =>
                        $data->amount,

                    'currency' =>
                        $data->currency,

                    'callback_url' =>
                        $data->callbackUrl,

                    'return_url' =>
                        $data->returnUrl,

                    'metadata' =>
                        $data->metadata,
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Initialize Payment
            |--------------------------------------------------------------------------
            */

            Log::info(
                'Calling PaymentService::initialize().',
                [
                    'request_id' =>
                        $requestId,

                    'payment_reference' =>
                        $data->paymentReference,

                    'invoice_id' =>
                        $data->invoiceId,

                    'taxpayer_id' =>
                        $data->citizenId,

                    'initiated_by_user_id' =>
                        $user->id,

                    'amount' =>
                        $data->amount,

                    'currency' =>
                        $data->currency,

                    'provider' =>
                        $this->safeEnumValue(
                            $data->provider
                        ),

                    'method' =>
                        $this->safeEnumValue(
                            $data->method
                        ),
                ]
            );

            $result =
                $this->onlinePaymentService->initialize(
                    $data
                );

            /*
            |--------------------------------------------------------------------------
            | Provider Result
            |--------------------------------------------------------------------------
            */

            Log::info(
                'PaymentService::initialize() completed.',
                [
                    'request_id' =>
                        $requestId,

                    'payment_reference' =>
                        $data->paymentReference,

                    'success' =>
                        $result->isSuccessful(),

                    'message' =>
                        $result->message,

                    'provider' =>
                        $this->safeEnumValue(
                            $result->provider
                        ),

                    'provider_reference' =>
                        $result->providerReference,

                    'provider_transaction_id' =>
                        $result->providerTransactionId,

                    'amount' =>
                        $result->amount,

                    'currency' =>
                        $result->currency,

                    'checkout_url' =>
                        $result->checkoutUrl,
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Provider Initialization Failed
            |--------------------------------------------------------------------------
            */

            if (! $result->isSuccessful()) {
                Log::warning(
                    'Online payment provider initialization failed.',
                    [
                        'request_id' =>
                            $requestId,

                        'payment_reference' =>
                            $data->paymentReference,

                        'invoice_id' =>
                            $data->invoiceId,

                        'taxpayer_id' =>
                            $data->citizenId,

                        'initiated_by_user_id' =>
                            $user->id,

                        'provider' =>
                            $this->safeEnumValue(
                                $data->provider
                            ),

                        'message' =>
                            $result->message,
                    ]
                );

                return ApiResponse::success(
                    $result,
                    $result->message
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Initialization Successful
            |--------------------------------------------------------------------------
            |
            | Initialization success does NOT mean the payment
            | is completed.
            |
            | The payment remains PENDING/PROCESSING until the
            | provider is verified.
            |
            */

            Log::info(
                'Online payment initialized successfully.',
                [
                    'request_id' =>
                        $requestId,

                    'payment_reference' =>
                        $data->paymentReference,

                    'invoice_id' =>
                        $data->invoiceId,

                    'taxpayer_id' =>
                        $data->citizenId,

                    'initiated_by_user_id' =>
                        $user->id,

                    'provider_reference' =>
                        $result->providerReference,

                    'provider_transaction_id' =>
                        $result->providerTransactionId,

                    'amount' =>
                        $data->amount,

                    'currency' =>
                        $data->currency,

                    'message' =>
                        $result->message,
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Return Checkout Information
            |--------------------------------------------------------------------------
            */

            return ApiResponse::success(
                $result,
                $result->message
            );

        } catch (Throwable $exception) {

            /*
            |--------------------------------------------------------------------------
            | Unexpected Exception
            |--------------------------------------------------------------------------
            */

            Log::error(
                'Online payment initialization failed unexpectedly.',
                [
                    'request_id' =>
                        $requestId,

                    'user_id' =>
                        $request->user()?->id,

                    'invoice_id' =>
                        $validated['invoice_id']
                        ?? null,

                    'request_data' =>
                        $this->safeRequestData(
                            $validated
                        ),

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
                'Unable to initialize payment.',
                500
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Payment Result
    |--------------------------------------------------------------------------
    |
    | Returns the current authoritative payment status to the frontend.
    |
    | IMPORTANT:
    |
    | This method does NOT trust a status sent by the frontend.
    |
    | The source of truth is:
    |
    |     payments.status
    |
    | That status is updated by PaymentVerificationService after
    | provider verification.
    |
    */

    public function result(
        Request $request,
        Payment $payment
    ): JsonResponse {
        $requestId =
            $request->header('X-Request-ID')
            ?? (string) Str::uuid();

        Log::info(
            'Online payment result request started.',
            [
                'request_id' =>
                    $requestId,

                'payment_id' =>
                    $payment->id,

                'payment_number' =>
                    $payment->payment_number,

                'request_ip' =>
                    $request->ip(),
            ]
        );

        try {
            /*
            |--------------------------------------------------------------------------
            | Load Required Relationships
            |--------------------------------------------------------------------------
            |
            | onlineDetails contains provider-specific information.
            |
            */

            $payment->load([
                'onlineDetails',
            ]);

            /*
            |--------------------------------------------------------------------------
            | Return Payment Result
            |--------------------------------------------------------------------------
            */

            $result = $this->formatPaymentResult(
                $payment
            );

            Log::info(
                'Online payment result returned successfully.',
                [
                    'request_id' =>
                        $requestId,

                    'payment_id' =>
                        $payment->id,

                    'payment_number' =>
                        $payment->payment_number,

                    'status' =>
                        $result['status'],

                    'payment_provider' =>
                        $result['payment_provider'],
                ]
            );

            return ApiResponse::success(
                $result,
                'Payment status retrieved successfully.'
            );

        } catch (Throwable $exception) {

            Log::error(
                'Online payment result request failed unexpectedly.',
                [
                    'request_id' =>
                        $requestId,

                    'payment_id' =>
                        $payment->id,

                    'payment_number' =>
                        $payment->payment_number,

                    'exception' =>
                        $exception::class,

                    'message' =>
                        $exception->getMessage(),

                    'file' =>
                        $exception->getFile(),

                    'line' =>
                        $exception->getLine(),
                ]
            );

            return ApiResponse::error(
                'Unable to retrieve payment status.',
                500
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Format Payment Result
    |--------------------------------------------------------------------------
    |
    | Keep the response intentionally small.
    |
    | Do NOT expose:
    |
    | - provider_response
    | - payment metadata
    | - provider credentials
    | - internal failure details
    | - taxpayer private information
    |
    */

    protected function formatPaymentResult(
        Payment $payment
    ): array {
        return [
            'id' =>
                (string) $payment->id,

            'payment_number' =>
                (string) $payment->payment_number,

            'transaction_reference' =>
                $payment->transaction_reference
                !== null
                    ? (string) $payment->transaction_reference
                    : null,

            'amount' =>
                $payment->amount !== null
                    ? (string) $payment->amount
                    : '0.00',

            'currency' =>
                (string) (
                    $payment->currency
                    ?? 'ETB'
                ),

            'status' =>
                $payment->status?->value
                ?? (string) $payment->status,

            'payment_method' =>
                $payment->payment_method?->value
                ?? (string) $payment->payment_method,

            'payment_provider' =>
                $payment->payment_provider?->value
                ?? (
                    $payment->payment_provider !== null
                        ? (string) $payment->payment_provider
                        : null
                ),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Safe Request Data
    |--------------------------------------------------------------------------
    */

    protected function safeRequestData(
        array $validated
    ): array {
        return [
            'invoice_id' =>
                $validated['invoice_id']
                ?? null,

            'amount' =>
                $validated['amount']
                ?? null,

            'payment_provider' =>
                $this->safeEnumValue(
                    $validated['payment_provider']
                    ?? null
                ),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Safe Enum Value
    |--------------------------------------------------------------------------
    */

    protected function safeEnumValue(
        mixed $value
    ): mixed {
        if (
            is_object($value)
            &&
            method_exists(
                $value,
                'value'
            )
        ) {
            return $value->value;
        }

        return $value;
    }

    /*
    |--------------------------------------------------------------------------
    | Normalize Payment Amount
    |--------------------------------------------------------------------------
    */

    protected function normalizePaymentAmount(
        mixed $value
    ): string {
        if ($value === null || $value === '') {
            throw new InvalidArgumentException(
                'Payment amount is required.'
            );
        }

        if (
            ! is_string($value)
            && ! is_int($value)
            && ! is_float($value)
        ) {
            throw new InvalidArgumentException(
                'Invalid payment amount.'
            );
        }

        $value =
            trim((string) $value);

        if (
            ! preg_match(
                '/^\d+(?:\.\d+)?$/',
                $value
            )
        ) {
            throw new InvalidArgumentException(
                'Invalid payment amount.'
            );
        }

        return bcadd(
            $value,
            '0',
            2
        );
    }
}
