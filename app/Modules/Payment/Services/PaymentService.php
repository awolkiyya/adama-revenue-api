<?php

namespace App\Modules\Payment\Services;

use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Modules\Payment\DTOs\InitializePaymentData;
use App\Modules\Payment\DTOs\PaymentResult;
use App\Modules\Payment\DTOs\PaymentVerificationResult;
use App\Modules\Payment\Factories\PaymentProviderFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class PaymentService
{
    public function __construct(
        protected PaymentProviderFactory $providerFactory,
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
                && filled($existingPayment->checkout_url)
            ) {
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
                return $this->paymentResultFromExistingPayment(
                    $existingPayment,
                    'Payment has already been completed.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Existing Failed Payment
            |--------------------------------------------------------------------------
            */

            throw new RuntimeException(
                'A payment already exists for this payment reference.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Create Local Payment
        |--------------------------------------------------------------------------
        |
        | We create the local payment first.
        |
        | No external provider communication happens inside this
        | database transaction.
        |
        */

        $payment = DB::transaction(
            function () use ($data): Payment {
                return Payment::query()->create([

                    /*
                    |--------------------------------------------------------------------------
                    | Ownership / Relationships
                    |--------------------------------------------------------------------------
                    */

                    'invoice_id' =>
                        $data->invoiceId,

                    'citizen_id' =>
                        $data->citizenId,

                    /*
                    |--------------------------------------------------------------------------
                    | Payment Configuration
                    |--------------------------------------------------------------------------
                    */

                    'payment_method' =>
                        $data->method,

                    'payment_provider' =>
                        $data->provider,

                    /*
                    |--------------------------------------------------------------------------
                    | Initial Status
                    |--------------------------------------------------------------------------
                    */

                    'status' =>
                        PaymentStatus::PENDING,

                    /*
                    |--------------------------------------------------------------------------
                    | Internal Payment Reference
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
                        $data->metadata,
                ]);
            }
        );

        /*
        |--------------------------------------------------------------------------
        | Resolve Provider
        |--------------------------------------------------------------------------
        */

        $provider = $this->providerFactory->make(
            $data->provider
        );

        /*
        |--------------------------------------------------------------------------
        | Initialize External Provider
        |--------------------------------------------------------------------------
        */

        try {
            $result = $provider->initialize(
                $data
            );
        } catch (Throwable $exception) {

            Log::error(
                'Payment provider initialization failed.',
                [
                    'payment_id' =>
                        $payment->id,

                    'citizen_id' =>
                        $payment->citizen_id,

                    'provider' =>
                        $this->providerValue(
                            $data->provider
                        ),

                    'payment_reference' =>
                        $data->paymentReference,

                    'exception' =>
                        $exception::class,

                    'message' =>
                        $exception->getMessage(),
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Mark Local Payment Failed
            |--------------------------------------------------------------------------
            */

            $payment->markAsFailed(
                'Payment provider initialization failed.'
            );

            throw $exception;
        }

        /*
        |--------------------------------------------------------------------------
        | Provider Initialization Failed
        |--------------------------------------------------------------------------
        */

        if (!$result->success) {

            $payment->forceFill([
                'status' =>
                    PaymentStatus::FAILED,

                'provider_reference' =>
                    $result->providerReference,

                'failure_reason' =>
                    $result->message,

                'provider_response' =>
                    $this->providerResponseFromResult(
                        $result
                    ),
            ])->save();

            return $result;
        }

        /*
        |--------------------------------------------------------------------------
        | Provider Initialization Successful
        |--------------------------------------------------------------------------
        |
        | IMPORTANT:
        |
        | Successful initialization does NOT mean the payment
        | has been paid.
        |
        | For Chapa, the user still needs to complete checkout.
        |
        */

        $payment->forceFill([
            'status' =>
                PaymentStatus::PENDING,

            'provider_reference' =>
                $result->providerReference,

            'checkout_url' =>
                $result->checkoutUrl,

            'provider_response' =>
                $this->providerResponseFromResult(
                    $result
                ),
        ])->save();

        /*
        |--------------------------------------------------------------------------
        | Logging
        |--------------------------------------------------------------------------
        */

        Log::info(
            'PaymentService::initialize() completed.',
            [
                'payment_reference' =>
                    $data->paymentReference,

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
    | Verify Payment
    |--------------------------------------------------------------------------
    */

    public function verify(
        Payment $payment
    ): PaymentVerificationResult {

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
                    $payment->provider_reference,

                transactionReference:
                    $payment->transaction_reference,

                amount:
                    (float) $payment->amount,

                currency:
                    $payment->currency,

                paidAt:
                    $payment->payment_date
                    ?? $payment->verified_at
                    ?? now(),

                metadata:
                    $this->decodeProviderResponse(
                        $payment->provider_response
                    ),
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Resolve Provider
        |--------------------------------------------------------------------------
        */

        $provider = $this->providerFactory->make(
            $payment->payment_provider
        );

        /*
        |--------------------------------------------------------------------------
        | Verify With Provider
        |--------------------------------------------------------------------------
        */

        try {

            $result = $provider->verify(
                $payment
            );

        } catch (Throwable $exception) {

            Log::error(
                'Payment verification failed.',
                [
                    'payment_id' =>
                        $payment->id,

                    'citizen_id' =>
                        $payment->citizen_id,

                    'provider' =>
                        $this->providerValue(
                            $payment->payment_provider
                        ),

                    'transaction_reference' =>
                        $payment->transaction_reference,

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
                            PaymentStatus::SUCCESS,

                        'provider_reference' =>
                            $result->providerReference
                            ??
                            $lockedPayment->provider_reference,

                        'amount' =>
                            $result->amount
                            ??
                            $lockedPayment->amount,

                        'currency' =>
                            $result->currency
                            ??
                            $lockedPayment->currency,

                        'payment_date' =>
                            $result->paidAt
                            ?? now(),

                        'verified_at' =>
                            now(),

                        'failure_reason' =>
                            null,

                        'provider_response' =>
                            $this->verificationResponseFromResult(
                                $result
                            ),
                    ])->save();

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

                        'provider_reference' =>
                            $result->providerReference
                            ??
                            $lockedPayment->provider_reference,

                        'failure_reason' =>
                            $result->message,

                        'provider_response' =>
                            $this->verificationResponseFromResult(
                                $result
                            ),
                    ])->save();

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

                    'provider_reference' =>
                        $result->providerReference
                        ??
                        $lockedPayment->provider_reference,

                    'provider_response' =>
                        $this->verificationResponseFromResult(
                            $result
                        ),
                ])->save();
            }
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Find Payment
    |--------------------------------------------------------------------------
    */

    public function findByTransactionReference(
        string $transactionReference
    ): ?Payment {

        return Payment::query()
            ->where(
                'transaction_reference',
                $transactionReference
            )
            ->first();
    }

    /*
    |--------------------------------------------------------------------------
    | Find Payment Or Fail
    |--------------------------------------------------------------------------
    */

    public function findByTransactionReferenceOrFail(
        string $transactionReference
    ): Payment {

        return Payment::query()
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

        return PaymentResult::success(
            provider:
                $this->providerEnum(
                    $payment->payment_provider
                ),

            paymentReference:
                $payment->transaction_reference,

            providerReference:
                $payment->provider_reference,

            checkoutUrl:
                $payment->checkout_url,

            providerTransactionId:
                $this->providerTransactionIdFromResponse(
                    $payment->provider_response
                ),

            message:
                $message,

            metadata:
                $this->decodeProviderResponse(
                    $payment->provider_response
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

        /*
        |--------------------------------------------------------------------------
        | Store normalized verification data.
        |--------------------------------------------------------------------------
        |
        | This avoids depending on provider-specific raw response
        | properties inside PaymentService.
        |
        */

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
    | Provider Transaction ID From Response
    |--------------------------------------------------------------------------
    */

    protected function providerTransactionIdFromResponse(
        mixed $response
    ): ?string {

        $data = $this->decodeProviderResponse(
            $response
        );

        /*
        |--------------------------------------------------------------------------
        | New normalized structure
        |--------------------------------------------------------------------------
        */

        if (
            isset(
                $data['provider_transaction_id']
            )
            &&
            is_string(
                $data['provider_transaction_id']
            )
        ) {
            return $data['provider_transaction_id'];
        }

        /*
        |--------------------------------------------------------------------------
        | Metadata structure
        |--------------------------------------------------------------------------
        */

        $metadata =
            $data['metadata']
            ?? null;

        if (
            is_array($metadata)
            &&
            isset(
                $metadata['provider_transaction_id']
            )
            &&
            is_string(
                $metadata['provider_transaction_id']
            )
        ) {
            return $metadata[
                'provider_transaction_id'
            ];
        }

        return null;
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
}