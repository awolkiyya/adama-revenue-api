<?php

namespace App\Modules\Payment\Controllers;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Modules\Payment\DTOs\InitializePaymentData;
use App\Modules\Payment\Requests\InitializePaymentRequest;
use App\Modules\Payment\Services\OnlinePaymentService;
use App\Services\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
            |
            | invoices.citizen_id identifies the taxpayer who owns
            | the municipal obligation.
            |
            | The authenticated user is NOT automatically the taxpayer.
            |
            | The authenticated user may be an authorized employee/agent.
            |
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
            |
            | The foreign key exists, but the related citizen record
            | should also exist.
            |
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
             * IMPORTANT:
             *
             * Do not call a method such as:
             *
             * $this->paymentService->authorizePaymentInitiation(...)
             *
             * unless that method already exists in your project.
             *
             * Use your existing authorization mechanism here.
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
            |
            | InitializePaymentRequest already validates the amount.
            |
            | This additional check protects the financial boundary.
            |
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
                number_format(
                    (float) $amount,
                    2,
                    '.',
                    ''
                );

            /*
            |--------------------------------------------------------------------------
            | Server-Controlled Currency
            |--------------------------------------------------------------------------
            |
            | Currency comes from the invoice.
            |
            | The frontend does not control the currency.
            |
            */

            $currency =
                $invoice->currency ?? 'ETB';

            /*
            |--------------------------------------------------------------------------
            | Payment Provider
            |--------------------------------------------------------------------------
            |
            | The provider is selected by the frontend and validated
            | by InitializePaymentRequest.
            |
            */

            $paymentProvider =
                $validated['payment_provider'];

            /*
            |--------------------------------------------------------------------------
            | Payment Method
            |--------------------------------------------------------------------------
            |
            | This endpoint initializes ONLINE payments only.
            |
            | Therefore the backend determines the payment method.
            |
            | Frontend does NOT send payment_method.
            |
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
            |
            | IMPORTANT:
            |
            | These URLs are SERVER CONTROLLED.
            |
            | They must NEVER come from the frontend.
            |
            | callback_url:
            |     Chapa -> Laravel backend
            |
            | return_url:
            |     Chapa -> frontend/browser result page
            |
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
            |
            | These are safe configuration values and do not contain
            | payment credentials.
            |
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
            |
            | This identifies this payment attempt.
            |
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
            |
            | IMPORTANT:
            |
            | InitializePaymentData requires:
            |
            |     citizen_id
            |
            | NOT:
            |
            |     customer_id
            |
            | citizen_id comes from the trusted invoice relationship.
            |
            */

            $dtoData = [

                /*
                |--------------------------------------------------------------------------
                | Invoice
                |--------------------------------------------------------------------------
                */

                'invoice_id' =>
                    $invoice->id,

                /*
                |--------------------------------------------------------------------------
                | Taxpayer / Citizen
                |--------------------------------------------------------------------------
                |
                | This is the taxpayer who owns the invoice.
                |
                | Source:
                |
                |     invoices.citizen_id
                |
                | It is NOT the authenticated employee/agent ID.
                |
                */

                'citizen_id' =>
                    $taxpayerId,

                /*
                |--------------------------------------------------------------------------
                | Internal Transaction Reference
                |--------------------------------------------------------------------------
                */

                'payment_reference' =>
                    $paymentReference,

                /*
                |--------------------------------------------------------------------------
                | Payment Method
                |--------------------------------------------------------------------------
                */

                'method' =>
                    $paymentMethod,

                /*
                |--------------------------------------------------------------------------
                | Payment Provider
                |--------------------------------------------------------------------------
                */

                'provider' =>
                    $paymentProvider,

                /*
                |--------------------------------------------------------------------------
                | Payment Amount
                |--------------------------------------------------------------------------
                */

                'amount' =>
                    $amount,

                /*
                |--------------------------------------------------------------------------
                | Currency
                |--------------------------------------------------------------------------
                */

                'currency' =>
                    $currency,

                /*
                |--------------------------------------------------------------------------
                | Customer / Taxpayer Provider Information
                |--------------------------------------------------------------------------
                |
                | These are intentionally NOT accepted from the frontend.
                |
                | If the provider requires taxpayer information,
                | the service/provider adapter should resolve it from
                | the trusted Citizen record.
                |
                */

                'customer_name' =>
                    null,

                'customer_email' =>
                    null,

                'customer_phone' =>
                    null,

                /*
                |--------------------------------------------------------------------------
                | Provider URLs
                |--------------------------------------------------------------------------
                |
                | IMPORTANT:
                |
                | These now come from Laravel configuration.
                |
                | callback_url:
                |     Chapa -> Laravel
                |
                | return_url:
                |     Chapa -> frontend
                |
                */

                'return_url' =>
                    $returnUrl,

                'callback_url' =>
                    $callbackUrl,

                /*
                |--------------------------------------------------------------------------
                | Description
                |--------------------------------------------------------------------------
                */

                'description' =>
                    'Payment for invoice ' .
                    $invoice->invoice_number,

                /*
                |--------------------------------------------------------------------------
                | Metadata
                |--------------------------------------------------------------------------
                |
                | Internal metadata:
                |
                | - invoice
                | - taxpayer
                | - payment initiator
                | - request
                |
                */

                'metadata' => [
                    'invoice_id' =>
                        $invoice->id,

                    'taxpayer_id' =>
                        $taxpayerId,

                    /*
                     * Authenticated employee/user who initiated
                     * this payment attempt.
                     *
                     * This may be the taxpayer or an authorized agent.
                     */
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

                    /*
                     * Do not log sensitive provider credentials.
                     * URLs themselves are okay to log.
                     */
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

                    /*
                     * Metadata contains only internal identifiers.
                     */
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
            | IMPORTANT:
            |
            | Provider initialization success does NOT mean:
            |
            |     payment = COMPLETED
            |
            | It only means that the payment attempt was successfully
            | initialized and the taxpayer can continue the checkout.
            |
            | The payment should remain PENDING/PROCESSING until the
            | provider confirms the actual transaction.
            |
            | Your Telebirr/Chapa webhook or callback flow should be
            | responsible for confirming the transaction and transitioning
            | the payment to COMPLETED.
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

                    /*
                     * Full stack trace remains in server logs only.
                     */
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
    | Safe Request Data
    |--------------------------------------------------------------------------
    |
    | Only fields expected from the frontend are included.
    |
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
}