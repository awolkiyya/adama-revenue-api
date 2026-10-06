<?php

namespace App\Modules\Payment\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Citizen;
use App\Models\CitizenAccount;
use App\Modules\Payment\DTOs\InitializePaymentData;
use App\Modules\Payment\DTOs\PaymentVerificationResult;
use App\Modules\Payment\Requests\InitializePaymentRequest;
use App\Modules\Payment\Requests\VerifyPaymentRequest;
use App\Modules\Payment\Resources\PaymentResource;
use App\Modules\Payment\Services\PaymentService;
use App\Modules\Payment\Services\PaymentVerificationService;
use App\Services\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;
use App\Modules\Payment\Services\PaymentReceiptService;
use App\Models\Payment;

class OnlinePaymentController extends Controller
{
    public function __construct(
        protected PaymentService $paymentService,
        protected PaymentVerificationService $verificationService,
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | Initialize Payment
    |--------------------------------------------------------------------------
    */

    public function initialize(
        InitializePaymentRequest $request
    ): JsonResponse {

        $requestId =
            $request->header('X-Request-ID')
            ?? (string) Str::uuid();

        $validated = $request->validated();

        /*
        |--------------------------------------------------------------------------
        | Request Started
        |--------------------------------------------------------------------------
        */

        Log::info(
            'Payment initialization request started.',
            [
                'request_id' =>
                    $requestId,

                'user_id' =>
                    $request->user()?->id, // payer that initiate the payment 1. agent 2. taxpayer itself

                'authenticated' =>
                    $request->user() !== null,

                'method' =>
                    $request->method(), // online , cash , banktransfer

                'route' =>
                    $request->path(),

                'ip' =>
                    $request->ip(),

                'user_agent' =>
                    $request->userAgent(),

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
            |
            | Payment initialization is a taxpayer financial operation.
            |
            | An authenticated taxpayer is mandatory.
            |
            | Never use a hard-coded citizen ID in production.
            |
            */

            $user = $request->user();

            Log::info(
                'Payment initialization authentication resolved.',
                [
                    'request_id' =>
                        $requestId,

                    'authenticated' =>
                        $user !== null,

                    'user_id' =>
                        $user?->id,

                    'user_type' =>
                        $user?->user_type, // agent , employee , tasxpayer
                ]
            );

            if (! $user) {

                Log::warning(
                    'Payment initialization rejected because the user is unauthenticated.',
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


            // here this payment initiation may be not only citizen

            /*
            |--------------------------------------------------------------------------
            | Resolve Citizen Account
            |--------------------------------------------------------------------------
            |
            | Authenticated user
            |       ↓
            | citizen_accounts
            |       ↓
            | citizen_id
            |
            | Never trust citizen_id from the frontend.
            |
            */

            $citizenAccount =
                CitizenAccount::query()
                    ->where(
                        'user_id',
                        $user->id
                    )
                    ->where(
                        'is_active',
                        true
                    )
                    ->first();

            Log::info(
                'Citizen account lookup completed.',
                [
                    'request_id' =>
                        $requestId,

                    'user_id' =>
                        $user->id,

                    'citizen_account_found' =>
                        $citizenAccount !== null,

                    'citizen_account_id' =>
                        $citizenAccount?->id,

                    'citizen_id' =>
                        $citizenAccount?->citizen_id,
                ]
            );

            if (! $citizenAccount) {

                Log::warning(
                    'Payment initialization rejected because no active citizen account exists.',
                    [
                        'request_id' =>
                            $requestId,

                        'user_id' =>
                            $user->id,
                    ]
                );

                return ApiResponse::error(
                    'No active citizen account is associated with this user.',
                    403
                );
            }

            $citizenId =
                $citizenAccount->citizen_id;

            /*
            |--------------------------------------------------------------------------
            | Validate Citizen
            |--------------------------------------------------------------------------
            */

            $citizenExists =
                Citizen::query()
                    ->whereKey($citizenId)
                    ->where(
                        'is_active',
                        true
                    )
                    ->exists();

            Log::info(
                'Citizen validation completed.',
                [
                    'request_id' =>
                        $requestId,

                    'citizen_id' =>
                        $citizenId,

                    'citizen_exists' =>
                        $citizenExists,
                ]
            );

            if (! $citizenExists) {

                Log::error(
                    'Payment initialization rejected because citizen does not exist or is inactive.',
                    [
                        'request_id' =>
                            $requestId,

                        'user_id' =>
                            $user->id,

                        'citizen_id' =>
                            $citizenId,
                    ]
                );

                return ApiResponse::error(
                    'Citizen account could not be resolved.',
                    403
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Resolve Required Payment Fields
            |--------------------------------------------------------------------------
            */

            $invoiceId =
                $validated['invoice_id'];

            $amount =
                $validated['amount'];

            $paymentMethod =
                $validated['payment_method'];

            $paymentProvider =
                $validated['payment_provider'];

            /*
            |--------------------------------------------------------------------------
            | Defensive Amount Validation
            |--------------------------------------------------------------------------
            |
            | InitializePaymentRequest should already validate these values.
            |
            | These checks are intentionally repeated here because this is
            | a financial operation and we do not want an accidental null,
            | zero, negative, or non-numeric amount reaching the payment
            | service/provider.
            |
            */

            if (
                ! is_numeric($amount)
                ||
                (float) $amount <= 0
            ) {

                Log::warning(
                    'Payment initialization rejected because amount is invalid.',
                    [
                        'request_id' =>
                            $requestId,

                        'user_id' =>
                            $user->id,

                        'citizen_id' =>
                            $citizenId,

                        'invoice_id' =>
                            $invoiceId,

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
            | Keep two decimal places for the DTO/provider boundary.
            |
            | The authoritative invoice balance must still be checked by
            | the payment request/service/domain layer.
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
            | The municipal revenue system currently operates in ETB.
            |
            | Do not allow the taxpayer to select another currency.
            |
            */

            $currency = 'ETB';

            /*
            |--------------------------------------------------------------------------
            | Log Financial Request Values
            |--------------------------------------------------------------------------
            */

            Log::info(
                'Payment financial parameters resolved.',
                [
                    'request_id' =>
                        $requestId,

                    'user_id' => 
                        $user->id,

                    'citizen_id' =>
                        $citizenId,

                    'invoice_id' =>
                        $invoiceId,

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
            | Generate Internal Transaction Reference
            |--------------------------------------------------------------------------
            |
            | This is NOT the human-readable payment_number.
            |
            | transaction_reference:
            |
            | PAY-01M3QR...
            |
            | payment_number:
            |
            | PAY-2019-000001
            |
            | PaymentService generates payment_number through
            | DocumentSequenceService.
            |
            */

            $paymentReference =
                'PAY-' .
                strtoupper(
                    Str::ulid()->toBase32()
                );

            Log::info(
                'Internal payment reference generated.',
                [
                    'request_id' =>
                        $requestId,

                    'payment_reference' =>
                        $paymentReference,

                    'invoice_id' =>
                        $invoiceId,

                    'citizen_id' =>
                        $citizenId,
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Prepare Customer Name
            |--------------------------------------------------------------------------
            |
            | The request may provide first/last name.
            |
            | These values are informational provider fields.
            |
            */

            $customerName =
                trim(
                    implode(
                        ' ',
                        array_filter([
                            $validated['customer_first_name'] ?? null,
                            $validated['customer_last_name'] ?? null,
                        ])
                    )
                );  // this must be from the payment initiation data account

            $customerName =
                $customerName !== ''
                    ? $customerName
                    : null;

            /*
            |--------------------------------------------------------------------------
            | Prepare DTO Data
            |--------------------------------------------------------------------------
            */

            $dtoData = [

                /*
                |--------------------------------------------------------------------------
                | Invoice
                |--------------------------------------------------------------------------
                */

                'invoice_id' =>
                    $invoiceId,

                /*
                |--------------------------------------------------------------------------
                | Trusted Backend Identity this must be any authenthicated user
                |--------------------------------------------------------------------------
                */

                'citizen_id' =>
                    $citizenId,

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
                | Requested Payment Amount
                |--------------------------------------------------------------------------
                |
                | IMPORTANT:
                |
                | There is intentionally NO fallback such as:
                |
                | $validated['amount'] ?? 100
                |
                | A financial request must never silently become another
                | amount.
                |
                */

                'amount' =>
                    $amount,

                /*
                |--------------------------------------------------------------------------
                | Server-Controlled Currency
                |--------------------------------------------------------------------------
                */

                'currency' =>
                    $currency,

                /*
                |--------------------------------------------------------------------------
                | Customer
                |--------------------------------------------------------------------------
                */

                'customer_id' =>
                    $validated['customer_id'] ?? null,

                'customer_name' =>
                    $customerName,

                'customer_email' =>
                    $validated['customer_email'] ?? null,

                'customer_phone' =>
                    $validated['customer_phone'] ?? null,

                /*
                |--------------------------------------------------------------------------
                | Provider URLs
                |--------------------------------------------------------------------------
                */

                'return_url' =>
                    $validated['return_url'] ?? null,

                'callback_url' =>
                    $validated['callback_url'] ?? null,

                /*
                |--------------------------------------------------------------------------
                | Description
                |--------------------------------------------------------------------------
                */

                'description' =>
                    $validated['description'] ?? null,

                /*
                |--------------------------------------------------------------------------
                | Metadata
                |--------------------------------------------------------------------------
                */

                'metadata' =>
                    $validated['metadata'] ?? [],
            ];

            /*
            |--------------------------------------------------------------------------
            | Log DTO Preparation
            |--------------------------------------------------------------------------
            */

            Log::info(
                'Preparing payment DTO data.',
                [
                    'request_id' =>
                        $requestId,

                    'invoice_id' =>
                        $invoiceId,

                    'citizen_id' =>
                        $citizenId,

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
                'Payment DTO created.',
                [
                    'request_id' =>
                        $requestId,

                    'payment_reference' =>
                        $data->paymentReference,

                    'invoice_id' =>
                        $data->invoiceId,

                    'citizen_id' =>
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

                    'customer_name' =>
                        $data->customerName,

                    'customer_email' =>
                        $data->customerEmail,

                    'customer_phone' =>
                        $data->customerPhone,

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

                    'citizen_id' =>
                        $data->citizenId,

                    'amount' =>
                        $data->amount,

                    'currency' =>
                        $data->currency,

                    'provider' =>
                        $this->safeEnumValue(
                            $data->provider
                        ),
                ]
            );

            $result =
                $this->paymentService->initialize(
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
                    'Payment initialization completed without a successful provider checkout.',
                    [
                        'request_id' =>
                            $requestId,

                        'payment_reference' =>
                            $data->paymentReference,

                        'invoice_id' =>
                            $data->invoiceId,

                        'citizen_id' =>
                            $data->citizenId,

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
            | Completed
            |--------------------------------------------------------------------------
            */

            Log::info(
                'Payment initialization request completed successfully.',
                [
                    'request_id' =>
                        $requestId,

                    'payment_reference' =>
                        $data->paymentReference,

                    'invoice_id' =>
                        $data->invoiceId,

                    'citizen_id' =>
                        $data->citizenId,

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

            return ApiResponse::success(
                $result,
                $result->message
            );

        } catch (Throwable $exception) {

            Log::error(
                'Payment initialization failed.',
                [
                    'request_id' =>
                        $requestId,

                    'user_id' =>
                        $request->user()?->id,

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
}