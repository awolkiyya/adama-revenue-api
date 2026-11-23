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
    | Frontend sends:
    |
    | {
    |     "invoice_id": "...",
    |     "amount": "29423.04",
    |     "payment_provider": "TELEBIRR"
    | }
    |
    | Supported providers:
    | - CHAPA
    | - TELEBIRR
    |
    | The authenticated user may be:
    | - The taxpayer
    | - An authorized municipal employee/agent
    |
    | The invoice determines the taxpayer through:
    |     invoices.citizen_id
    |
    | This endpoint initializes payment only.
    | Initialization success does NOT mean payment completion.
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

        $requestId = $request->header('X-Request-ID')
            ?? (string) Str::uuid();

        /*
        |--------------------------------------------------------------------------
        | Validated Request Data
        |--------------------------------------------------------------------------
        */

        $validated = $request->validated();

        /*
        |--------------------------------------------------------------------------
        | Resolve Authenticated User
        |--------------------------------------------------------------------------
        */

        $user = $request->user();

        /*
        |--------------------------------------------------------------------------
        | Request Started
        |--------------------------------------------------------------------------
        */

        Log::info(
            'Online payment initialization request started.',
            [
                'request_id' => $requestId,
                'initiated_by_user_id' => $user?->id,
                'authenticated' => $user !== null,
                'route' => $request->path(),
                'http_method' => $request->method(),
                'ip' => $request->ip(),
                'request_data' => $this->safeRequestData($validated),
            ]
        );

        try {

            /*
            |--------------------------------------------------------------------------
            | Authentication Required
            |--------------------------------------------------------------------------
            */

            if (! $user) {
                Log::warning(
                    'Online payment initialization rejected: unauthenticated user.',
                    [
                        'request_id' => $requestId,
                        'ip' => $request->ip(),
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
            */

            $invoice = Invoice::query()
                ->with('citizen')
                ->find($validated['invoice_id']);

            if (! $invoice) {
                Log::warning(
                    'Online payment initialization rejected: invoice not found.',
                    [
                        'request_id' => $requestId,
                        'initiated_by_user_id' => $user->id,
                        'invoice_id' => $validated['invoice_id'],
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

            $taxpayerId = $invoice->citizen_id;

            if (! $taxpayerId || ! $invoice->citizen) {
                Log::error(
                    'Online payment initialization rejected: invoice taxpayer is missing.',
                    [
                        'request_id' => $requestId,
                        'initiated_by_user_id' => $user->id,
                        'invoice_id' => $invoice->id,
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
            | InvoicePolicy::pay() must verify that the authenticated
            | user is allowed to pay this invoice.
            |
            | It should support:
            | - Taxpayer self-payment.
            | - Authorized municipal employee/agent payment.
            |
            */

            // $this->authorize('pay', $invoice);

            /*
            |--------------------------------------------------------------------------
            | Resolve Payment Amount
            |--------------------------------------------------------------------------
            |
            | The frontend amount is a requested payment amount.
            | It is NOT the authoritative invoice balance.
            |
            | OnlinePaymentService must independently verify the
            | outstanding balance before creating the payment.
            |
            */

            $amount = $validated['amount'] ?? null;

            /*
            |--------------------------------------------------------------------------
            | Validate Amount Type
            |--------------------------------------------------------------------------
            |
            | Prefer decimal strings to avoid floating-point precision
            | problems.
            |
            */

            if (! is_string($amount) && ! is_int($amount)) {
                Log::warning(
                    'Online payment initialization rejected: invalid amount type.',
                    [
                        'request_id' => $requestId,
                        'invoice_id' => $invoice->id,
                        'initiated_by_user_id' => $user->id,
                    ]
                );

                return ApiResponse::error(
                    'Payment amount must be supplied as a decimal string.',
                    422
                );
            }

            $amount = trim((string) $amount);

            /*
            |--------------------------------------------------------------------------
            | Validate Decimal Format
            |--------------------------------------------------------------------------
            |
            | Accepts:
            | 29423
            | 29423.0
            | 29423.04
            |
            | Rejects:
            | - Negative values
            | - More than two decimal places
            | - Scientific notation
            | - Empty values
            | - Non-numeric values
            |
            */

            if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $amount)) {
                Log::warning(
                    'Online payment initialization rejected: invalid amount format.',
                    [
                        'request_id' => $requestId,
                        'invoice_id' => $invoice->id,
                        'initiated_by_user_id' => $user->id,
                    ]
                );

                return ApiResponse::error(
                    'Payment amount must be a positive decimal amount with no more than two decimal places.',
                    422
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Normalize Amount
            |--------------------------------------------------------------------------
            |
            | Normalize using string operations rather than floating-point
            | arithmetic or a dependency on the BCMath extension.
            |
            */

            [$integerPart, $fractionPart] = array_pad(
                explode('.', $amount, 2),
                2,
                '0'
            );

            $integerPart = ltrim($integerPart, '0');

            if ($integerPart === '') {
                $integerPart = '0';
            }

            $fractionPart = str_pad(
                $fractionPart,
                2,
                '0'
            );

            $amount = $integerPart . '.' . $fractionPart;

            /*
            |--------------------------------------------------------------------------
            | Reject Zero Amount
            |--------------------------------------------------------------------------
            */

            if ($amount === '0.00') {
                return ApiResponse::error(
                    'Payment amount must be greater than zero.',
                    422
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Resolve Server-Controlled Currency
            |--------------------------------------------------------------------------
            */

            $currency = $invoice->currency ?: 'ETB';

            /*
            |--------------------------------------------------------------------------
            | Resolve Payment Provider
            |--------------------------------------------------------------------------
            */

            $paymentProvider = $validated['payment_provider'];

            $providerValue = $this->safeEnumValue(
                $paymentProvider
            );

            /*
            |--------------------------------------------------------------------------
            | Validate Supported Provider
            |--------------------------------------------------------------------------
            */

            $providerKey = match (
                strtoupper((string) $providerValue)
            ) {
                'CHAPA' => 'chapa',
                'TELEBIRR' => 'telebirr',
                default => null,
            };

            if ($providerKey === null) {
                Log::warning(
                    'Online payment initialization rejected: unsupported provider.',
                    [
                        'request_id' => $requestId,
                        'invoice_id' => $invoice->id,
                        'provider' => $providerValue,
                    ]
                );

                return ApiResponse::error(
                    'The selected payment provider is not supported.',
                    422
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Resolve Payment Method
            |--------------------------------------------------------------------------
            */

            $paymentMethod = PaymentMethod::ONLINE;

            /*
            |--------------------------------------------------------------------------
            | Resolve Provider-Specific Configuration
            |--------------------------------------------------------------------------
            |
            | Chapa:
            | services.chapa.callback_url
            | services.chapa.return_url
            |
            | Telebirr:
            | services.telebirr.callback_url
            | services.telebirr.return_url
            |
            */

            $callbackUrl = config(
                "services.{$providerKey}.callback_url"
            );

            $returnUrl = config(
                "services.{$providerKey}.return_url"
            );

            /*
            |--------------------------------------------------------------------------
            | Validate Callback URL
            |--------------------------------------------------------------------------
            */

            if (
                ! is_string($callbackUrl)
                || ! filter_var($callbackUrl, FILTER_VALIDATE_URL)
            ) {
                Log::critical(
                    'Online payment callback URL is missing or invalid.',
                    [
                        'request_id' => $requestId,
                        'provider' => $providerKey,
                        'invoice_id' => $invoice->id,
                    ]
                );

                return ApiResponse::error(
                    'Online payment callback URL is not correctly configured.',
                    500
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Validate Return URL
            |--------------------------------------------------------------------------
            */

            if (
                ! is_string($returnUrl)
                || ! filter_var($returnUrl, FILTER_VALIDATE_URL)
            ) {
                Log::critical(
                    'Online payment return URL is missing or invalid.',
                    [
                        'request_id' => $requestId,
                        'provider' => $providerKey,
                        'invoice_id' => $invoice->id,
                    ]
                );

                return ApiResponse::error(
                    'Online payment return URL is not correctly configured.',
                    500
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Log Resolved Payment Parameters
            |--------------------------------------------------------------------------
            */

            Log::info(
                'Online payment parameters resolved.',
                [
                    'request_id' => $requestId,
                    'initiated_by_user_id' => $user->id,
                    'invoice_id' => $invoice->id,
                    'taxpayer_id' => $taxpayerId,
                    'amount' => $amount,
                    'currency' => $currency,
                    'payment_method' => $this->safeEnumValue(
                        $paymentMethod
                    ),
                    'payment_provider' => $providerKey,
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Generate Internal Payment Reference
            |--------------------------------------------------------------------------
            */

            $paymentReference = 'PAY-' . strtoupper(
                (string) Str::ulid()
            );

            /*
            |--------------------------------------------------------------------------
            | Prepare Payment DTO
            |--------------------------------------------------------------------------
            */

            $dtoData = [
                'invoice_id' => $invoice->id,

                'citizen_id' => $taxpayerId,

                'payment_reference' => $paymentReference,

                'method' => $paymentMethod,

                'provider' => $paymentProvider,

                'amount' => $amount,

                'currency' => $currency,

                'initiated_by_user_id' => $user->id,

                'customer_name' => null,

                'customer_email' => null,

                'customer_phone' => $user->phone,

                'return_url' => $returnUrl,

                'callback_url' => $callbackUrl,

                'description' => 'Payment for invoice '
                    . $invoice->invoice_number,

                'metadata' => [
                    'invoice_id' => $invoice->id,
                    'taxpayer_id' => $taxpayerId,
                    'initiated_by_user_id' => $user->id,
                    'request_id' => $requestId,
                ],
            ];

            /*
            |--------------------------------------------------------------------------
            | Create Payment DTO
            |--------------------------------------------------------------------------
            */

            $data = InitializePaymentData::fromArray(
                $dtoData
            );

            /*
            |--------------------------------------------------------------------------
            | Log Payment Initialization
            |--------------------------------------------------------------------------
            */

            Log::info(
                'Initializing online payment through payment service.',
                [
                    'request_id' => $requestId,
                    'payment_reference' => $data->paymentReference,
                    'invoice_id' => $data->invoiceId,
                    'taxpayer_id' => $data->citizenId,
                    'initiated_by_user_id' => $user->id,
                    'amount' => $data->amount,
                    'currency' => $data->currency,
                    'provider' => $providerKey,
                    'method' => $this->safeEnumValue(
                        $data->method
                    ),
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Initialize Payment
            |--------------------------------------------------------------------------
            |
            | OnlinePaymentService is responsible for:
            |
            | 1. Validating the authoritative outstanding invoice balance.
            | 2. Preventing duplicate active payment initialization.
            | 3. Creating the internal payment record.
            | 4. Resolving the correct provider implementation.
            | 5. Calling the provider initialization API.
            | 6. Persisting provider references and checkout details.
            |
            */

            $result = $this->onlinePaymentService->initialize(
                $data
            );

            /*
            |--------------------------------------------------------------------------
            | Log Initialization Result
            |--------------------------------------------------------------------------
            */

            Log::info(
                'Online payment initialization service completed.',
                [
                    'request_id' => $requestId,
                    'payment_reference' => $data->paymentReference,
                    'success' => $result->isSuccessful(),
                    'provider' => $providerKey,
                    'checkout_url_generated' => filled(
                        $result->checkoutUrl
                    ),
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
                        'request_id' => $requestId,
                        'payment_reference' => $data->paymentReference,
                        'invoice_id' => $data->invoiceId,
                        'initiated_by_user_id' => $user->id,
                        'provider' => $providerKey,
                    ]
                );

                return ApiResponse::error(
                    'Unable to initialize payment with the selected provider.',
                    502
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Initialization Successful
            |--------------------------------------------------------------------------
            |
            | The provider accepted the initialization request.
            |
            | This does NOT mean that the taxpayer has paid.
            |
            | Payment verification must happen separately.
            |
            */

            Log::info(
                'Online payment initialized successfully.',
                [
                    'request_id' => $requestId,
                    'payment_reference' => $data->paymentReference,
                    'invoice_id' => $data->invoiceId,
                    'taxpayer_id' => $data->citizenId,
                    'initiated_by_user_id' => $user->id,
                    'provider' => $providerKey,
                    'amount' => $data->amount,
                    'currency' => $data->currency,
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
                    ?? 'Online payment initialized successfully.'
            );

        } catch (AuthorizationException $exception) {

            /*
            |--------------------------------------------------------------------------
            | Preserve Laravel Authorization Response
            |--------------------------------------------------------------------------
            |
            | Do not convert authorization failures into HTTP 500.
            |
            */

            throw $exception;

        } catch (Throwable $exception) {

            /*
            |--------------------------------------------------------------------------
            | Unexpected Exception
            |--------------------------------------------------------------------------
            */

            Log::error(
                'Online payment initialization failed unexpectedly.',
                [
                    'request_id' => $requestId,
                    'user_id' => $user?->id,
                    'invoice_id' => $validated['invoice_id'] ?? null,
                    'exception' => $exception::class,
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
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
