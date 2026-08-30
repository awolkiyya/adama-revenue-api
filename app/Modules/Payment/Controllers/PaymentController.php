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

class PaymentController extends Controller
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
                    $request->user()?->id,

                'authenticated' =>
                    $request->user() !== null,

                'method' =>
                    $request->method(),

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
                        $user?->user_type,
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Resolve Citizen
            |--------------------------------------------------------------------------
            |
            | Production:
            |
            | authenticated user
            |       ↓
            | citizen_accounts
            |       ↓
            | citizen_id
            |
            | Never trust citizen_id from frontend.
            |
            */

            $citizenId = null;

            if ($user) {

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

                if (!$citizenAccount) {

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

            } else {

                /*
                |--------------------------------------------------------------------------
                | Temporary Testing Citizen
                |--------------------------------------------------------------------------
                |
                | REMOVE BEFORE PRODUCTION.
                |
                */

                $citizenId =
                    'd0353d20-6066-417e-95c3-cc9d4a7a101b';

                Log::warning(
                    'Payment initialization is using a temporary hard-coded citizen ID because the request is unauthenticated.',
                    [
                        'request_id' =>
                            $requestId,

                        'citizen_id' =>
                            $citizenId,
                    ]
                );
            }

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

            if (!$citizenExists) {

                Log::error(
                    'Payment initialization rejected because citizen does not exist.',
                    [
                        'request_id' =>
                            $requestId,

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
            | Generate Internal Payment Reference
            |--------------------------------------------------------------------------
            |
            | IMPORTANT:
            |
            | The client should NOT generate the payment reference.
            | The backend owns this value.
            |
            */

            $paymentReference =
                'PAY-' . strtoupper(
                    Str::ulid()->toBase32()
                );

            Log::info(
                'Internal payment reference generated.',
                [
                    'request_id' =>
                        $requestId,

                    'payment_reference' =>
                        $paymentReference,
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Prepare DTO Data
            |--------------------------------------------------------------------------
            */

            Log::info(
                'Preparing payment DTO data.',
                [
                    'request_id' =>
                        $requestId,

                    'citizen_id' =>
                        $citizenId,

                    'payment_reference' =>
                        $paymentReference,
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Normalize Customer Name
            |--------------------------------------------------------------------------
            |
            | Request contains:
            |
            | customer_first_name
            | customer_last_name
            |
            | DTO expects:
            |
            | customer_name
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
                );

            /*
            |--------------------------------------------------------------------------
            | Build DTO Payload
            |--------------------------------------------------------------------------
            */

            $dtoData = [

                /*
                |--------------------------------------------------------------------------
                | Trusted Backend Values
                |--------------------------------------------------------------------------
                */

                'invoice_id' =>
                    $validated['invoice_id'],

                'citizen_id' =>
                    $citizenId,

                'payment_reference' =>
                    $paymentReference,

                /*
                |--------------------------------------------------------------------------
                | Payment Configuration
                |--------------------------------------------------------------------------
                |
                | Request currently sends:
                |
                | payment_method
                | payment_provider
                |
                | DTO expects:
                |
                | method
                | provider
                |
                */

                'method' =>
                    $validated['payment_method'],

                'provider' =>
                    $validated['payment_provider'],

                /*
                |--------------------------------------------------------------------------
                | Amount
                |--------------------------------------------------------------------------
                |
                | IMPORTANT:
                |
                | Ideally amount should be resolved from the invoice
                | on the backend rather than trusted from the client.
                |
                | Your current request does not contain amount.
                |
                | Therefore we leave this to InitializePaymentRequest
                | only if it already resolves/provides it.
                |
                */

                'amount' =>
                    $validated['amount'] ?? 100,

                'currency' =>
                    $validated['currency'] ?? 'ETB',

                /*
                |--------------------------------------------------------------------------
                | Customer
                |--------------------------------------------------------------------------
                */

                'customer_id' =>
                    $validated['customer_id'] ?? null,

                'customer_name' =>
                    $customerName !== ''
                        ? $customerName
                        : null,

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
                        $result->provider,

                    'provider_reference' =>
                        $result->providerReference,

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

    /*
    |--------------------------------------------------------------------------
    | Verify Payment
    |--------------------------------------------------------------------------
    */

    public function verify(
        VerifyPaymentRequest $request
    ): JsonResponse {

        $requestId =
            $request->header('X-Request-ID')
            ?? (string) Str::uuid();

        $transactionReference =
            $request->validated(
                'transaction_reference'
            );

        Log::info(
            'Payment verification request started.',
            [
                'request_id' =>
                    $requestId,

                'user_id' =>
                    $request->user()?->id,

                'authenticated' =>
                    $request->user() !== null,

                'transaction_reference' =>
                    $transactionReference,

                'ip' =>
                    $request->ip(),

                'user_agent' =>
                    $request->userAgent(),
            ]
        );

        try {

            Log::info(
                'Searching for payment by transaction reference.',
                [
                    'request_id' =>
                        $requestId,

                    'transaction_reference' =>
                        $transactionReference,
                ]
            );

            $payment =
                $this->paymentService
                    ->findByTransactionReferenceOrFail(
                        $transactionReference
                    );

            Log::info(
                'Payment found for verification.',
                [
                    'request_id' =>
                        $requestId,

                    'payment_id' =>
                        $payment->id,

                    'invoice_id' =>
                        $payment->invoice_id,

                    'citizen_id' =>
                        $payment->citizen_id,

                    'payment_method' =>
                        $this->safeEnumValue(
                            $payment->payment_method
                        ),

                    'payment_provider' =>
                        $this->safeEnumValue(
                            $payment->payment_provider
                        ),

                    'status' =>
                        $this->safeEnumValue(
                            $payment->status
                        ),

                    'amount' =>
                        $payment->amount,

                    'currency' =>
                        $payment->currency,

                    'provider_reference' =>
                        $payment->provider_reference,
                ]
            );

            Log::info(
                'Calling PaymentVerificationService::verify().',
                [
                    'request_id' =>
                        $requestId,

                    'payment_id' =>
                        $payment->id,

                    'transaction_reference' =>
                        $payment->transaction_reference,

                    'provider' =>
                        $this->safeEnumValue(
                            $payment->payment_provider
                        ),
                ]
            );

            $result =
                $this->verificationService->verify(
                    $payment
                );

            Log::info(
                'Payment provider verification completed.',
                [
                    'request_id' =>
                        $requestId,

                    'payment_id' =>
                        $payment->id,

                    'transaction_reference' =>
                        $payment->transaction_reference,

                    'success' =>
                        $result->isSuccessful(),

                    'failed' =>
                        $result->isFailed(),

                    'message' =>
                        $result->message,

                    'provider_reference' =>
                        $result->providerReference,

                    'amount' =>
                        $result->amount,

                    'currency' =>
                        $result->currency,
                ]
            );

            $payment->refresh();

            Log::info(
                'Payment refreshed after verification.',
                [
                    'request_id' =>
                        $requestId,

                    'payment_id' =>
                        $payment->id,

                    'citizen_id' =>
                        $payment->citizen_id,

                    'status' =>
                        $this->safeEnumValue(
                            $payment->status
                        ),

                    'provider_reference' =>
                        $payment->provider_reference,

                    'verified_at' =>
                        $payment->verified_at,

                    'payment_date' =>
                        $payment->payment_date,
                ]
            );

            return ApiResponse::success(
                [
                    'payment' =>
                        new PaymentResource($payment),

                    'verification' =>
                        $result,
                ],
                $this->verificationMessage($result)
            );

        } catch (Throwable $exception) {

            Log::error(
                'Payment verification failed.',
                [
                    'request_id' =>
                        $requestId,

                    'user_id' =>
                        $request->user()?->id,

                    'transaction_reference' =>
                        $transactionReference,

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
                'Unable to verify payment.',
                500
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Show Payment
    |--------------------------------------------------------------------------
    */

    public function show(
        Request $request,
        string $payment
    ): JsonResponse {

        $requestId =
            $request->header('X-Request-ID')
            ?? (string) Str::uuid();

        Log::info(
            'Payment retrieval request started.',
            [
                'request_id' =>
                    $requestId,

                'user_id' =>
                    $request->user()?->id,

                'authenticated' =>
                    $request->user() !== null,

                'payment_reference' =>
                    $payment,

                'ip' =>
                    $request->ip(),
            ]
        );

        try {

            $paymentModel =
                $this->paymentService
                    ->findByTransactionReferenceOrFail(
                        $payment
                    );

            Log::info(
                'Payment found for retrieval.',
                [
                    'request_id' =>
                        $requestId,

                    'payment_id' =>
                        $paymentModel->id,

                    'invoice_id' =>
                        $paymentModel->invoice_id,

                    'payment_user_id' =>
                        $paymentModel->user_id,

                    'citizen_id' =>
                        $paymentModel->citizen_id,

                    'status' =>
                        $this->safeEnumValue(
                            $paymentModel->status
                        ),
                ]
            );

            $user = $request->user();

            if ($user) {

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

                $authorized =
                    $citizenAccount
                    && $citizenAccount->citizen_id ===
                        $paymentModel->citizen_id;

            } else {

                $authorized = false;
            }

            if (!$authorized) {

                Log::warning(
                    'Payment retrieval denied because citizen ownership check failed.',
                    [
                        'request_id' =>
                            $requestId,

                        'authenticated_user_id' =>
                            $user?->id,

                        'payment_id' =>
                            $paymentModel->id,

                        'payment_citizen_id' =>
                            $paymentModel->citizen_id,

                        'payment_reference' =>
                            $payment,
                    ]
                );

                return ApiResponse::error(
                    'Payment not found.',
                    404
                );
            }

            Log::info(
                'Payment retrieved successfully.',
                [
                    'request_id' =>
                        $requestId,

                    'payment_id' =>
                        $paymentModel->id,

                    'payment_reference' =>
                        $paymentModel->transaction_reference,

                    'citizen_id' =>
                        $paymentModel->citizen_id,
                ]
            );

            return ApiResponse::success(
                new PaymentResource($paymentModel),
                'Payment retrieved successfully.'
            );

        } catch (Throwable $exception) {

            Log::warning(
                'Payment retrieval failed.',
                [
                    'request_id' =>
                        $requestId,

                    'user_id' =>
                        $request->user()?->id,

                    'payment_reference' =>
                        $payment,

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
                'Payment not found.',
                404
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Verification Message
    |--------------------------------------------------------------------------
    */

    protected function verificationMessage(
        PaymentVerificationResult $result
    ): string {

        if ($result->isSuccessful()) {
            return 'Payment verified successfully.';
        }

        if ($result->isFailed()) {
            return 'Payment verification failed.';
        }

        return 'Payment is still pending.';
    }

    /*
    |--------------------------------------------------------------------------
    | Safe Request Data
    |--------------------------------------------------------------------------
    */

    protected function safeRequestData(
        array $data
    ): array {

        return collect($data)
            ->except([
                'password',
                'password_confirmation',
                'token',
                'access_token',
                'refresh_token',
                'authorization',
            ])
            ->toArray();
    }

    /*
    |--------------------------------------------------------------------------
    | Safe Enum Value
    |--------------------------------------------------------------------------
    */

    protected function safeEnumValue(
        mixed $value
    ): mixed {

        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        return $value;
    }
}