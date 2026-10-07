<?php

namespace App\Modules\Payment\Services;

use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Modules\Payment\DTOs\InitializePaymentData;
use App\Modules\Payment\DTOs\PaymentResult;
use App\Modules\Payment\DTOs\PaymentVerificationResult;
use App\Modules\Payment\Factories\PaymentProviderFactory;
use App\Services\DocumentSequenceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class OnlinePaymentService
{
    public function __construct(
        protected PaymentProviderFactory $providerFactory,
        protected DocumentSequenceService $documentSequenceService,
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | Initialize Payment
    |--------------------------------------------------------------------------
    */

    public function initialize(
        InitializePaymentData $data
    ): PaymentResult {
        /*
        |--------------------------------------------------------------------------
        | Check Existing Payment
        |--------------------------------------------------------------------------
        */

        $existingPayment = Payment::query()
            ->with('onlineDetails')
            ->where(
                'transaction_reference',
                $data->paymentReference
            )
            ->first();

        if ($existingPayment) {

            /*
            |--------------------------------------------------------------------------
            | Existing Pending Payment
            |--------------------------------------------------------------------------
            */

            if (
                $existingPayment->isPending()
                && filled(
                    $existingPayment->onlineDetails?->checkout_url
                )
            ) {
                Log::info(
                    'Existing pending online payment found.',
                    [
                        'payment_id' =>
                            $existingPayment->id,

                        'payment_number' =>
                            $existingPayment->payment_number,

                        'transaction_reference' =>
                            $existingPayment->transaction_reference,

                        'payment_reference' =>
                            $data->paymentReference,

                        'provider' =>
                            $this->providerValue(
                                $existingPayment->payment_provider
                                ?? $data->provider
                            ),

                        'provider_reference' =>
                            $existingPayment->onlineDetails?->checkout_reference,

                        'checkout_url' =>
                            $existingPayment->onlineDetails?->checkout_url,
                    ]
                );

                return $this->paymentResultFromExistingPayment(
                    $existingPayment,
                    'Payment already initialized.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Existing Successful Payment
            |--------------------------------------------------------------------------
            */

            if ($existingPayment->isSuccessful()) {

                Log::info(
                    'Existing successful payment found.',
                    [
                        'payment_id' =>
                            $existingPayment->id,

                        'payment_number' =>
                            $existingPayment->payment_number,

                        'transaction_reference' =>
                            $existingPayment->transaction_reference,

                        'payment_reference' =>
                            $data->paymentReference,

                        'provider' =>
                            $this->providerValue(
                                $existingPayment->payment_provider
                                ?? $data->provider
                            ),
                    ]
                );

                return $this->paymentResultFromExistingPayment(
                    $existingPayment,
                    'Payment has already been completed.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Existing Failed / Non-Reusable Payment
            |--------------------------------------------------------------------------
            */

            Log::warning(
                'Existing payment found with non-reusable status.',
                [
                    'payment_id' =>
                        $existingPayment->id,

                    'payment_number' =>
                        $existingPayment->payment_number,

                    'transaction_reference' =>
                        $existingPayment->transaction_reference,

                    'status' =>
                        $this->paymentStatusValue(
                            $existingPayment->status
                        ),

                    'payment_reference' =>
                        $data->paymentReference,

                    'provider' =>
                        $this->nullableProviderValue(
                            $existingPayment->payment_provider
                        ),
                ]
            );

            throw new RuntimeException(
                'A payment already exists for this payment reference.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Validate Provider
        |--------------------------------------------------------------------------
        */

        $paymentProvider = $this->providerEnum(
            $data->provider
        );

        /*
        |--------------------------------------------------------------------------
        | Generate Payment Number
        |--------------------------------------------------------------------------
        */

        $paymentNumber = $this->documentSequenceService->generate(
            sequenceType: 'payment',
        );

        Log::info(
            'Payment number generated.',
            [
                'payment_number' =>
                    $paymentNumber,

                'transaction_reference' =>
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
                    $paymentProvider->value,
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Create Local Payment
        |--------------------------------------------------------------------------
        */

        $payment = DB::transaction(
            function () use (
                $data,
                $paymentNumber,
                $paymentProvider
            ): Payment {
                return Payment::query()->create([

                    'invoice_id' =>
                        $data->invoiceId,

                    'citizen_id' =>
                        $data->citizenId,

                    'payment_number' =>
                        $paymentNumber,

                    /*
                    |--------------------------------------------------------------------------
                    | Online Payment
                    |--------------------------------------------------------------------------
                    */

                    'payment_method' =>
                        $data->method,

                    'payment_provider' =>
                        $paymentProvider,

                    /*
                    |--------------------------------------------------------------------------
                    | Initial Status
                    |--------------------------------------------------------------------------
                    */

                    'status' =>
                        PaymentStatus::PENDING,

                    /*
                    |--------------------------------------------------------------------------
                    | Internal Transaction Reference
                    |--------------------------------------------------------------------------
                    */

                    'transaction_reference' =>
                        $data->paymentReference,

                    /*
                    |--------------------------------------------------------------------------
                    | Amount
                    |--------------------------------------------------------------------------
                    */

                    'amount' =>
                        $data->amount,

                    'currency' =>
                        $data->currency,

                    /*
                    |--------------------------------------------------------------------------
                    | Payer
                    |--------------------------------------------------------------------------
                    */

                    'payer_name' =>
                        $data->customerName,

                    'payer_email' =>
                        $data->customerEmail,

                    'payer_phone' =>
                        $data->customerPhone,

                    /*
                    |--------------------------------------------------------------------------
                    | Metadata
                    |--------------------------------------------------------------------------
                    */

                    'metadata' =>
                        $data->metadata ?? [],
                ]);
            }
        );

        /*
        |--------------------------------------------------------------------------
        | Verify Provider Was Persisted
        |--------------------------------------------------------------------------
        */

        if ($payment->payment_provider === null) {

            Log::error(
                'Payment provider was not persisted to the payment record.',
                [
                    'payment_id' =>
                        $payment->id,

                    'payment_number' =>
                        $payment->payment_number,

                    'transaction_reference' =>
                        $payment->transaction_reference,

                    'expected_provider' =>
                        $paymentProvider->value,

                    'actual_provider' =>
                        $payment->payment_provider,

                    'invoice_id' =>
                        $payment->invoice_id,

                    'citizen_id' =>
                        $payment->citizen_id,
                ]
            );

            $payment->markAsFailed(
                'Payment provider could not be persisted. '
                . 'Check the Payment model $fillable configuration '
                . 'and payment_provider cast.'
            );

            throw new RuntimeException(
                'Payment provider could not be persisted. '
                . 'Check the Payment model $fillable configuration '
                . 'and payment_provider cast.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Local Payment Created Logging
        |--------------------------------------------------------------------------
        */

        Log::info(
            'Local payment created successfully.',
            [
                'payment_id' =>
                    $payment->id,

                'payment_number' =>
                    $payment->payment_number,

                'transaction_reference' =>
                    $payment->transaction_reference,

                'invoice_id' =>
                    $payment->invoice_id,

                'citizen_id' =>
                    $payment->citizen_id,

                'amount' =>
                    $payment->amount,

                'currency' =>
                    $payment->currency,

                'status' =>
                    $this->paymentStatusValue(
                        $payment->status
                    ),

                'provider' =>
                    $this->providerValue(
                        $payment->payment_provider
                    ),

                'method' =>
                    $this->paymentMethodValue(
                        $payment->payment_method
                    ),
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Resolve And Initialize Provider
        |--------------------------------------------------------------------------
        */

        try {

            $provider = $this->providerFactory->make(
                $paymentProvider
            );

            Log::info(
                'Calling payment provider initialize.',
                [
                    'payment_id' =>
                        $payment->id,

                    'payment_number' =>
                        $payment->payment_number,

                    'transaction_reference' =>
                        $payment->transaction_reference,

                    'provider' =>
                        $paymentProvider->value,

                    'amount' =>
                        $data->amount,

                    'currency' =>
                        $data->currency,
                ]
            );

            $result = $provider->initialize(
                $data
            );

        } catch (Throwable $exception) {

            Log::error(
                'Payment provider initialization failed.',
                [
                    'payment_id' =>
                        $payment->id,

                    'payment_number' =>
                        $payment->payment_number,

                    'citizen_id' =>
                        $payment->citizen_id,

                    'invoice_id' =>
                        $payment->invoice_id,

                    'provider' =>
                        $paymentProvider->value,

                    'payment_reference' =>
                        $data->paymentReference,

                    'transaction_reference' =>
                        $payment->transaction_reference,

                    'amount' =>
                        $data->amount,

                    'currency' =>
                        $data->currency,

                    'exception' =>
                        $exception::class,

                    'message' =>
                        $exception->getMessage(),
                ]
            );

            $failureReason = $exception->getMessage();

            if (
                ! is_string($failureReason)
                || trim($failureReason) === ''
            ) {
                $failureReason =
                    'Payment provider initialization failed.';
            }

            try {

                $payment->markAsFailed(
                    $failureReason
                );

            } catch (Throwable $markFailedException) {

                Log::critical(
                    'Unable to mark payment as failed after provider initialization exception.',
                    [
                        'payment_id' =>
                            $payment->id,

                        'payment_number' =>
                            $payment->payment_number,

                        'transaction_reference' =>
                            $payment->transaction_reference,

                        'provider' =>
                            $paymentProvider->value,

                        'original_exception' =>
                            $exception::class,

                        'original_message' =>
                            $exception->getMessage(),

                        'mark_failed_exception' =>
                            $markFailedException::class,

                        'mark_failed_message' =>
                            $markFailedException->getMessage(),
                    ]
                );
            }

            throw $exception;
        }

        /*
        |--------------------------------------------------------------------------
        | Provider Initialization Returned Failure
        |--------------------------------------------------------------------------
        */

        if (! $result->success) {

            DB::transaction(
                function () use (
                    $payment,
                    $result
                ): void {

                    $payment->forceFill([
                        'status' =>
                            PaymentStatus::FAILED,

                        'failure_reason' =>
                            $result->message,
                    ])->save();

                    $this->updateOnlinePaymentDetails(
                        $payment,
                        $result
                    );
                }
            );

            Log::warning(
                'Payment provider initialization returned failure.',
                [
                    'payment_id' =>
                        $payment->id,

                    'payment_number' =>
                        $payment->payment_number,

                    'transaction_reference' =>
                        $payment->transaction_reference,

                    'provider' =>
                        $paymentProvider->value,

                    'provider_reference' =>
                        $result->providerReference,

                    'amount' =>
                        $data->amount,

                    'currency' =>
                        $data->currency,

                    'message' =>
                        $result->message,
                ]
            );

            return $result;
        }

        /*
        |--------------------------------------------------------------------------
        | Provider Initialization Successful
        |--------------------------------------------------------------------------
        |
        | IMPORTANT:
        |
        | This does NOT mean the taxpayer has paid.
        |
        | The local payment remains PENDING.
        |
        */

        DB::transaction(
            function () use (
                $payment,
                $result
            ): void {

                $payment->forceFill([
                    'status' =>
                        PaymentStatus::PENDING,
                ])->save();

                $this->updateOnlinePaymentDetails(
                    $payment,
                    $result
                );
            }
        );

        /*
        |--------------------------------------------------------------------------
        | Initialization Logging
        |--------------------------------------------------------------------------
        */

        Log::info(
            'OnlinePaymentService::initialize() completed.',
            [
                'payment_id' =>
                    $payment->id,

                'payment_number' =>
                    $payment->payment_number,

                'payment_reference' =>
                    $data->paymentReference,

                'transaction_reference' =>
                    $payment->transaction_reference,

                'success' =>
                    $result->success,

                'status' =>
                    $result->status->value,

                'message' =>
                    $result->message,

                'provider' =>
                    $result->provider->value,

                'provider_reference' =>
                    $result->providerReference,

                'provider_transaction_id' =>
                    $result->providerTransactionId,

                'amount' =>
                    $data->amount,

                'currency' =>
                    $data->currency,

                'checkout_url' =>
                    $result->checkoutUrl,
            ]
        );

        return $result;
    }

    /*
    |--------------------------------------------------------------------------
    | Update Online Payment Details
    |--------------------------------------------------------------------------
    */

    protected function updateOnlinePaymentDetails(
        Payment $payment,
        PaymentResult $result
    ): void {

        /*
        |--------------------------------------------------------------------------
        | Resolve Provider Master Record
        |--------------------------------------------------------------------------
        |
        | payment_provider_id must reference the payment_providers table.
        |
        */

        $providerId = $this->resolvePaymentProviderId(
            $result->provider
        );

        $onlineDetails = $payment->onlineDetails()
            ->firstOrNew();

        $onlineDetails->payment_provider_id =
            $providerId;

        $onlineDetails->checkout_reference =
            $result->providerReference;

        $onlineDetails->provider_transaction_id =
            $result->providerTransactionId;

        $onlineDetails->checkout_url =
            $result->checkoutUrl;

        $onlineDetails->provider_status =
            $result->status->value;

        $onlineDetails->provider_response =
            $this->providerResponseFromResult(
                $result
            );

        $onlineDetails->save();
    }

    /*
    |--------------------------------------------------------------------------
    | Resolve Payment Provider ID
    |--------------------------------------------------------------------------
    */

    protected function resolvePaymentProviderId(
        PaymentProvider $provider
    ): string {

        /*
        |--------------------------------------------------------------------------
        | IMPORTANT
        |--------------------------------------------------------------------------
        |
        | Replace this query with your actual provider model if the model
        | is not named PaymentProviderModel.
        |
        */

        $providerRecord = \App\Models\PaymentProvider::query()
            ->where(
                'code',
                $provider->value
            )
            ->first();

        if (! $providerRecord) {

            throw new RuntimeException(
                "Payment provider [{$provider->value}] "
                . 'is not registered in payment_providers.'
            );
        }

        return $providerRecord->id;
    }

    /*
    |--------------------------------------------------------------------------
    | Verify Payment
    |--------------------------------------------------------------------------
    */

    public function verify(
        Payment $payment
    ): PaymentVerificationResult {

        $payment->loadMissing(
            'onlineDetails'
        );

        /*
        |--------------------------------------------------------------------------
        | Already Successful
        |--------------------------------------------------------------------------
        */

        if ($payment->isSuccessful()) {

            return PaymentVerificationResult::success(
                payment:
                    $payment,

                message:
                    'Payment has already been verified.',

                providerReference:
                    $payment->onlineDetails?->checkout_reference,

                transactionReference:
                    $payment->transaction_reference,

                amount:
                    (float) $payment->amount,

                currency:
                    $payment->currency,

                paidAt:
                    $payment->onlineDetails?->paid_at
                    ?? $payment->verified_at
                    ?? now(),

                metadata:
                    $this->decodeProviderResponse(
                        $payment->onlineDetails?->provider_response
                    ),
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Validate Provider
        |--------------------------------------------------------------------------
        */

        if ($payment->payment_provider === null) {

            throw new RuntimeException(
                'Cannot verify payment because payment provider is missing.'
            );
        }

        $paymentProvider = $this->providerEnum(
            $payment->payment_provider
        );

        /*
        |--------------------------------------------------------------------------
        | Resolve And Verify Provider
        |--------------------------------------------------------------------------
        */

        try {

            $provider = $this->providerFactory->make(
                $paymentProvider
            );

            Log::info(
                'Calling payment provider verify.',
                [
                    'payment_id' =>
                        $payment->id,

                    'payment_number' =>
                        $payment->payment_number,

                    'transaction_reference' =>
                        $payment->transaction_reference,

                    'provider' =>
                        $paymentProvider->value,

                    'provider_reference' =>
                        $payment->onlineDetails?->checkout_reference,

                    'provider_transaction_id' =>
                        $payment->onlineDetails?->provider_transaction_id,
                ]
            );

            $result = $provider->verify(
                $payment
            );

        } catch (Throwable $exception) {

            Log::error(
                'Payment verification failed.',
                [
                    'payment_id' =>
                        $payment->id,

                    'payment_number' =>
                        $payment->payment_number,

                    'citizen_id' =>
                        $payment->citizen_id,

                    'invoice_id' =>
                        $payment->invoice_id,

                    'provider' =>
                        $paymentProvider->value,

                    'transaction_reference' =>
                        $payment->transaction_reference,

                    'provider_reference' =>
                        $payment->onlineDetails?->checkout_reference,

                    'exception' =>
                        $exception::class,

                    'message' =>
                        $exception->getMessage(),
                ]
            );

            throw $exception;
        }

        /*
        |--------------------------------------------------------------------------
        | Apply Verification Result
        |--------------------------------------------------------------------------
        */

        $this->applyVerificationResult(
            $payment,
            $result
        );

        return $result;
    }

    /*
    |--------------------------------------------------------------------------
    | Apply Verification Result
    |--------------------------------------------------------------------------
    */

    protected function applyVerificationResult(
        Payment $payment,
        PaymentVerificationResult $result
    ): void {

        DB::transaction(
            function () use (
                $payment,
                $result
            ): void {

                $lockedPayment = Payment::query()
                    ->with('onlineDetails')
                    ->whereKey($payment->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                /*
                |--------------------------------------------------------------------------
                | Never Downgrade Successful Payment
                |--------------------------------------------------------------------------
                */

                if ($lockedPayment->isSuccessful()) {
                    return;
                }

                /*
                |--------------------------------------------------------------------------
                | SUCCESS
                |--------------------------------------------------------------------------
                */

                if ($result->isSuccessful()) {

                    $lockedPayment->forceFill([
                        'status' =>
                            PaymentStatus::COMPLETED,

                        'amount' =>
                            $result->amount
                            ??
                            $lockedPayment->amount,

                        'currency' =>
                            $result->currency
                            ??
                            $lockedPayment->currency,

                        'verified_at' =>
                            now(),

                        'failure_reason' =>
                            null,
                    ])->save();

                    $this->updateOnlinePaymentVerification(
                        $lockedPayment,
                        $result
                    );

                    Log::info(
                        'Payment verification applied successfully.',
                        [
                            'payment_id' =>
                                $lockedPayment->id,

                            'payment_number' =>
                                $lockedPayment->payment_number,

                            'transaction_reference' =>
                                $lockedPayment->transaction_reference,

                            'provider' =>
                                $this->nullableProviderValue(
                                    $lockedPayment->payment_provider
                                ),

                            'provider_reference' =>
                                $result->providerReference,

                            'provider_transaction_id' =>
                                $lockedPayment->onlineDetails?->provider_transaction_id,

                            'status' =>
                                $this->paymentStatusValue(
                                    $lockedPayment->status
                                ),

                            'amount' =>
                                $lockedPayment->amount,

                            'currency' =>
                                $lockedPayment->currency,

                            'verified_at' =>
                                $lockedPayment->verified_at?->toISOString(),
                        ]
                    );

                    return;
                }

                /*
                |--------------------------------------------------------------------------
                | FAILED
                |--------------------------------------------------------------------------
                */

                if ($result->isFailed()) {

                    $lockedPayment->forceFill([
                        'status' =>
                            PaymentStatus::FAILED,

                        'failure_reason' =>
                            $result->message,
                    ])->save();

                    $this->updateOnlinePaymentVerification(
                        $lockedPayment,
                        $result
                    );

                    Log::warning(
                        'Payment verification returned failed status.',
                        [
                            'payment_id' =>
                                $lockedPayment->id,

                            'payment_number' =>
                                $lockedPayment->payment_number,

                            'transaction_reference' =>
                                $lockedPayment->transaction_reference,

                            'provider' =>
                                $this->nullableProviderValue(
                                    $lockedPayment->payment_provider
                                ),

                            'provider_reference' =>
                                $result->providerReference,

                            'status' =>
                                $this->paymentStatusValue(
                                    $lockedPayment->status
                                ),

                            'failure_reason' =>
                                $result->message,
                        ]
                    );

                    return;
                }

                /*
                |--------------------------------------------------------------------------
                | PENDING
                |--------------------------------------------------------------------------
                */

                $lockedPayment->forceFill([
                    'status' =>
                        PaymentStatus::PENDING,
                ])->save();

                $this->updateOnlinePaymentVerification(
                    $lockedPayment,
                    $result
                );

                Log::info(
                    'Payment verification remains pending.',
                    [
                        'payment_id' =>
                            $lockedPayment->id,

                        'payment_number' =>
                            $lockedPayment->payment_number,

                        'transaction_reference' =>
                            $lockedPayment->transaction_reference,

                        'provider' =>
                            $this->nullableProviderValue(
                                $lockedPayment->payment_provider
                            ),

                        'provider_reference' =>
                            $result->providerReference,

                        'status' =>
                            $this->paymentStatusValue(
                                $lockedPayment->status
                            ),
                    ]
                );
            }
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Update Online Payment Verification
    |--------------------------------------------------------------------------
    */

    protected function updateOnlinePaymentVerification(
        Payment $payment,
        PaymentVerificationResult $result
    ): void {

        $onlineDetails = $payment->onlineDetails()
            ->firstOrNew();

        /*
        |--------------------------------------------------------------------------
        | Provider Reference
        |--------------------------------------------------------------------------
        */

        if ($result->providerReference !== null) {
            $onlineDetails->checkout_reference =
                $result->providerReference;
        }

        /*
        |--------------------------------------------------------------------------
        | Provider Transaction ID
        |--------------------------------------------------------------------------
        */

        if (
            $result->metadata['provider_transaction_id']
            ?? null
        ) {
            $onlineDetails->provider_transaction_id =
                $result->metadata['provider_transaction_id'];
        }

        /*
        |--------------------------------------------------------------------------
        | Provider Status
        |--------------------------------------------------------------------------
        */

        $onlineDetails->provider_status =
            $result->status->value;

        /*
        |--------------------------------------------------------------------------
        | Provider Response
        |--------------------------------------------------------------------------
        */

        $onlineDetails->provider_response =
            $this->verificationResponseFromResult(
                $result
            );

        /*
        |--------------------------------------------------------------------------
        | Paid At
        |--------------------------------------------------------------------------
        */

        if ($result->isSuccessful()) {

            $onlineDetails->paid_at =
                $result->paidAt
                ?? now();
        }

        /*
        |--------------------------------------------------------------------------
        | Callback / Verification Timestamp
        |--------------------------------------------------------------------------
        */

        $onlineDetails->callback_received_at =
            now();

        /*
        |--------------------------------------------------------------------------
        | Provider
        |--------------------------------------------------------------------------
        */

        if ($payment->payment_provider !== null) {

            $onlineDetails->payment_provider_id =
                $this->resolvePaymentProviderId(
                    $this->providerEnum(
                        $payment->payment_provider
                    )
                );
        }

        $onlineDetails->save();
    }

    /*
    |--------------------------------------------------------------------------
    | Find By Transaction Reference
    |--------------------------------------------------------------------------
    */

    public function findByTransactionReference(
        string $transactionReference
    ): ?Payment {

        return Payment::query()
            ->with('onlineDetails')
            ->where(
                'transaction_reference',
                $transactionReference
            )
            ->first();
    }

    /*
    |--------------------------------------------------------------------------
    | Find By Transaction Reference Or Fail
    |--------------------------------------------------------------------------
    */

    public function findByTransactionReferenceOrFail(
        string $transactionReference
    ): Payment {

        return Payment::query()
            ->with('onlineDetails')
            ->where(
                'transaction_reference',
                $transactionReference
            )
            ->firstOrFail();
    }

    /*
    |--------------------------------------------------------------------------
    | Find Payment For User
    |--------------------------------------------------------------------------
    */

    public function findForUser(
        $user,
        string $paymentId
    ): Payment {

        return Payment::query()
            ->with('onlineDetails')
            ->whereKey($paymentId)
            ->whereHas(
                'citizen.account',
                function ($query) use ($user) {
                    $query->where(
                        'user_id',
                        $user->id
                    );
                }
            )
            ->firstOrFail();
    }

    /*
    |--------------------------------------------------------------------------
    | Existing Payment -> PaymentResult
    |--------------------------------------------------------------------------
    */

    protected function paymentResultFromExistingPayment(
        Payment $payment,
        string $message
    ): PaymentResult {

        $payment->loadMissing(
            'onlineDetails'
        );

        if ($payment->payment_provider === null) {

            throw new RuntimeException(
                'Existing payment has no payment provider.'
            );
        }

        return PaymentResult::success(
            provider:
                $this->providerEnum(
                    $payment->payment_provider
                ),

            paymentReference:
                $payment->transaction_reference,

            providerReference:
                $payment->onlineDetails?->checkout_reference,

            checkoutUrl:
                $payment->onlineDetails?->checkout_url,

            providerTransactionId:
                $payment->onlineDetails?->provider_transaction_id,

            message:
                $message,

            metadata:
                $this->decodeProviderResponse(
                    $payment->onlineDetails?->provider_response
                ),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Provider Response From PaymentResult
    |--------------------------------------------------------------------------
    */

    protected function providerResponseFromResult(
        PaymentResult $result
    ): array {

        return [
            'success' =>
                $result->success,

            'status' =>
                $result->status->value,

            'provider' =>
                $result->provider->value,

            'payment_reference' =>
                $result->paymentReference,

            'provider_reference' =>
                $result->providerReference,

            'provider_transaction_id' =>
                $result->providerTransactionId,

            'checkout_url' =>
                $result->checkoutUrl,

            'message' =>
                $result->message,

            'metadata' =>
                $result->metadata,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Verification Response From Result
    |--------------------------------------------------------------------------
    */

    protected function verificationResponseFromResult(
        PaymentVerificationResult $result
    ): array {

        return [
            'success' =>
                $result->isSuccessful(),

            'status' =>
                $result->status->value,

            'provider_reference' =>
                $result->providerReference,

            'transaction_reference' =>
                $result->transactionReference,

            'amount' =>
                $result->amount,

            'currency' =>
                $result->currency,

            'message' =>
                $result->message,

            'paid_at' =>
                $result->paidAt?->toISOString(),

            'metadata' =>
                $result->metadata,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Decode Provider Response
    |--------------------------------------------------------------------------
    */

    protected function decodeProviderResponse(
        mixed $response
    ): array {

        if (is_array($response)) {
            return $response;
        }

        if (is_string($response)) {

            $decoded = json_decode(
                $response,
                true
            );

            return is_array($decoded)
                ? $decoded
                : [];
        }

        return [];
    }

    /*
    |--------------------------------------------------------------------------
    | Provider Enum
    |--------------------------------------------------------------------------
    */

    protected function providerEnum(
        PaymentProvider|string $provider
    ): PaymentProvider {

        if ($provider instanceof PaymentProvider) {
            return $provider;
        }

        return PaymentProvider::from(
            $provider
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Provider Value
    |--------------------------------------------------------------------------
    */

    protected function providerValue(
        PaymentProvider|string $provider
    ): string {

        return $provider instanceof PaymentProvider
            ? $provider->value
            : $provider;
    }

    /*
    |--------------------------------------------------------------------------
    | Nullable Provider Value
    |--------------------------------------------------------------------------
    */

    protected function nullableProviderValue(
        PaymentProvider|string|null $provider
    ): ?string {

        if ($provider === null) {
            return null;
        }

        return $this->providerValue(
            $provider
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Payment Status Value
    |--------------------------------------------------------------------------
    */

    protected function paymentStatusValue(
        PaymentStatus|string $status
    ): string {

        return $status instanceof PaymentStatus
            ? $status->value
            : $status;
    }

    /*
    |--------------------------------------------------------------------------
    | Payment Method Value
    |--------------------------------------------------------------------------
    */

    protected function paymentMethodValue(
        mixed $method
    ): ?string {

        if ($method === null) {
            return null;
        }

        return is_object($method)
            && property_exists($method, 'value')
            ? $method->value
            : (string) $method;
    }

    /*
    |--------------------------------------------------------------------------
    | Find Payment
    |--------------------------------------------------------------------------
    */

    public function find(
        string $paymentId
    ): ?Payment {

        return Payment::query()
            ->with('onlineDetails')
            ->whereKey($paymentId)
            ->first();
    }
}