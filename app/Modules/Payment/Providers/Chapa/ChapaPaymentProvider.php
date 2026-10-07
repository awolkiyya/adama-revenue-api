<?php

namespace App\Modules\Payment\Providers\Chapa;

use App\Enums\PaymentProvider;
use App\Models\Payment;
use App\Modules\Payment\Contracts\PaymentProviderInterface;
use App\Modules\Payment\DTOs\InitializePaymentData;
use App\Modules\Payment\DTOs\PaymentResult;
use App\Modules\Payment\DTOs\PaymentVerificationResult;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ChapaPaymentProvider implements PaymentProviderInterface
{
    /*
    |--------------------------------------------------------------------------
    | Configuration
    |--------------------------------------------------------------------------
    */

    private const CUSTOMIZATION_TITLE = 'Municipal Pay';

    /*
    |--------------------------------------------------------------------------
    | Provider
    |--------------------------------------------------------------------------
    */

    public function provider(): PaymentProvider
    {
        return PaymentProvider::CHAPA;
    }

    /*
    |--------------------------------------------------------------------------
    | Display Name
    |--------------------------------------------------------------------------
    */

    public function displayName(): string
    {
        return 'Chapa';
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
        | Local Transaction Reference
        |--------------------------------------------------------------------------
        |
        | This is the municipality's transaction reference.
        |
        | It is sent to Chapa as:
        |
        |     tx_ref
        |
        | Do NOT confuse this with Chapa's provider reference.
        |
        */

        $paymentReference = $data->paymentReference;

        $txRef = $paymentReference;

        Log::info(
            'Chapa payment initialization started.',
            [
                'provider' => $this->provider()->value,
                'payment_reference' => $paymentReference,
                'tx_ref' => $txRef,
                'invoice_id' => $data->invoiceId,
                'citizen_id' => $data->citizenId,
                'amount' => $data->amount,
                'currency' => $data->currency,
                'payment_method' => $data->method->value,
                'payment_provider' => $data->provider->value,
                'customer_id' => $data->customerId,
                'customer_name' => $data->customerName,
                'customer_email_present' => !empty($data->customerEmail),
                'customer_phone_present' => !empty($data->customerPhone),
                'description' => $data->description,
                'callback_url' => $data->callbackUrl,
                'return_url' => $data->returnUrl,
                'metadata' => $data->metadata,
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Validate Callback URL
        |--------------------------------------------------------------------------
        */

        if (!filled($data->callbackUrl)) {
            throw new RuntimeException(
                'Chapa callback URL is not configured.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Validate Return URL
        |--------------------------------------------------------------------------
        */

        if (!filled($data->returnUrl)) {
            throw new RuntimeException(
                'Chapa return URL is not configured.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Customer Name
        |--------------------------------------------------------------------------
        */

        [
            $firstName,
            $lastName,
        ] = $this->splitCustomerName(
            $data->customerName
        );

        /*
        |--------------------------------------------------------------------------
        | Customer Phone
        |--------------------------------------------------------------------------
        */

        $phone = $this->normalizePhone(
            $data->customerPhone
        );

        /*
        |--------------------------------------------------------------------------
        | Build Chapa Payload
        |--------------------------------------------------------------------------
        */

        $payload = [
            'amount' => $this->formatAmount(
                (float) $data->amount
            ),

            'currency' => strtoupper(
                (string) $data->currency
            ),

            'email' => $data->customerEmail,

            'first_name' => $firstName,

            'last_name' => $lastName,

            'phone_number' => $phone,

            /*
             * Municipality transaction reference.
             */
            'tx_ref' => $txRef,

            'callback_url' => $data->callbackUrl,

            'return_url' => $data->returnUrl,

            'customization' => [
                'title' => self::CUSTOMIZATION_TITLE,

                'description' => $this->truncateDescription(
                    $data->description
                    ?? 'Municipal invoice payment'
                ),
            ],

            'meta' => $data->metadata ?: null,
        ];

        $payload = $this->removeNullValues(
            $payload
        );

        /*
        |--------------------------------------------------------------------------
        | Final URL Validation
        |--------------------------------------------------------------------------
        */

        if (
            !isset($payload['callback_url'])
            || !filled($payload['callback_url'])
        ) {
            throw new RuntimeException(
                'Chapa callback URL was removed from the payment payload.'
            );
        }

        if (
            !isset($payload['return_url'])
            || !filled($payload['return_url'])
        ) {
            throw new RuntimeException(
                'Chapa return URL was removed from the payment payload.'
            );
        }

        Log::info(
            'Chapa payment payload prepared.',
            [
                'tx_ref' => $txRef,
                'amount' => $payload['amount'] ?? null,
                'currency' => $payload['currency'] ?? null,
                'callback_url' => $payload['callback_url'] ?? null,
                'return_url' => $payload['return_url'] ?? null,
                'payload' => $payload,
            ]
        );

        try {
            /*
            |--------------------------------------------------------------------------
            | Initialize With Chapa
            |--------------------------------------------------------------------------
            */

            $endpoint = '/transaction/initialize';

            $response = $this->client()->post(
                $endpoint,
                $payload
            );

            $body = $response->json();

            Log::info(
                'Chapa initialization response received.',
                [
                    'tx_ref' => $txRef,
                    'http_status' => $response->status(),
                    'successful' => $response->successful(),
                    'response' => $body,
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | HTTP Failure
            |--------------------------------------------------------------------------
            */

            if (!$response->successful()) {
                throw new RuntimeException(
                    $this->extractErrorMessage($body)
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Chapa Business Failure
            |--------------------------------------------------------------------------
            */

            if (
                ($body['status'] ?? null) !== 'success'
            ) {
                throw new RuntimeException(
                    $this->extractErrorMessage($body)
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Provider Data
            |--------------------------------------------------------------------------
            */

            $providerData =
                is_array($body['data'] ?? null)
                    ? $body['data']
                    : [];

            /*
            |--------------------------------------------------------------------------
            | Checkout URL
            |--------------------------------------------------------------------------
            */

            $checkoutUrl =
                $providerData['checkout_url']
                ?? null;

            if (!$checkoutUrl) {
                throw new RuntimeException(
                    'Chapa did not return a checkout URL.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Provider Reference
            |--------------------------------------------------------------------------
            |
            | Chapa initialization normally does not provide the final
            | provider transaction reference.
            |
            | Therefore this remains NULL.
            |
            */

            $providerReference = null;

            /*
            |--------------------------------------------------------------------------
            | Provider Transaction ID
            |--------------------------------------------------------------------------
            */

            $providerTransactionId =
                $providerData['transaction_reference']
                ?? $providerData['reference']
                ?? null;

            Log::info(
                'Chapa initialization references resolved.',
                [
                    'tx_ref' => $txRef,

                    'transaction_reference' =>
                        $txRef,

                    'provider_reference' =>
                        $providerReference,

                    'provider_transaction_id' =>
                        $providerTransactionId,
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Return Result
            |--------------------------------------------------------------------------
            */

            return PaymentResult::success(
                provider: $this->provider(),

                paymentReference:
                    $paymentReference,

                amount:
                    (float) $data->amount,

                currency:
                    $data->currency,

                providerReference:
                    $providerReference,

                checkoutUrl:
                    $checkoutUrl,

                providerTransactionId:
                    $providerTransactionId,

                message:
                    $body['message']
                    ?? 'Payment initialized successfully.',

                metadata: [
                    'chapa_response' =>
                        $providerData,
                ],
            );

        } catch (Throwable $exception) {
            Log::error(
                'Chapa payment initialization exception.',
                [
                    'tx_ref' => $txRef,
                    'invoice_id' => $data->invoiceId,
                    'payment_reference' => $paymentReference,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                ]
            );

            throw $exception;
        }
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
        | IMPORTANT
        |--------------------------------------------------------------------------
        |
        | Chapa verification MUST use the original municipality
        | transaction reference.
        |
        |     transaction_reference = Chapa tx_ref
        |
        | provider_reference is Chapa's provider-side reference and
        | must NEVER be used as the verification URL reference.
        |
        */

        $txRef = $payment->transaction_reference;

        Log::info(
            'Chapa payment verification started.',
            [
                'payment_id' => $payment->id,
                'payment_number' => $payment->payment_number,
                'invoice_id' => $payment->invoice_id,

                'transaction_reference' =>
                    $payment->transaction_reference,

                'provider_reference' =>
                    $payment->provider_reference,

                'tx_ref' =>
                    $txRef,

                'amount' =>
                    $payment->amount,

                'currency' =>
                    $payment->currency,

                'status' =>
                    $payment->status?->value
                    ?? $payment->status,
            ]
        );

        if (!filled($txRef)) {
            throw new RuntimeException(
                'Payment does not contain a Chapa transaction reference.'
            );
        }

        try {
            /*
            |--------------------------------------------------------------------------
            | Verification Endpoint
            |--------------------------------------------------------------------------
            */

            $endpoint =
                '/transaction/verify/'
                . urlencode($txRef);

            Log::info(
                'Sending payment verification request to Chapa.',
                [
                    'payment_id' =>
                        $payment->id,

                    'payment_number' =>
                        $payment->payment_number,

                    'tx_ref' =>
                        $txRef,

                    'endpoint' =>
                        $this->baseUrl() . $endpoint,

                    'method' =>
                        'GET',
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Call Chapa
            |--------------------------------------------------------------------------
            */

            $response =
                $this->client()->get(
                    $endpoint
                );

            $body = $response->json();

            Log::info(
                'Chapa verification response received.',
                [
                    'payment_id' =>
                        $payment->id,

                    'payment_number' =>
                        $payment->payment_number,

                    'tx_ref' =>
                        $txRef,

                    'http_status' =>
                        $response->status(),

                    'successful' =>
                        $response->successful(),

                    'response' =>
                        $body,
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | HTTP Failure
            |--------------------------------------------------------------------------
            */

            if (!$response->successful()) {
                throw new RuntimeException(
                    $this->extractErrorMessage($body)
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Map + Validate
            |--------------------------------------------------------------------------
            */

            $result =
                $this->mapVerificationResponse(
                    $payment,
                    $body
                );

            Log::info(
                'Chapa payment verification completed.',
                [
                    'payment_id' =>
                        $payment->id,

                    'payment_number' =>
                        $payment->payment_number,

                    'tx_ref' =>
                        $txRef,

                    'verification_status' =>
                        $result->status?->value
                        ?? $result->status,

                    'message' =>
                        $result->message,

                    'amount' =>
                        $result->amount,

                    'currency' =>
                        $result->currency,

                    'provider_reference' =>
                        $result->providerReference,

                    'transaction_reference' =>
                        $result->transactionReference,

                    'is_successful' =>
                        $result->isSuccessful(),
                ]
            );

            return $result;

        } catch (Throwable $exception) {
            Log::error(
                'Chapa payment verification exception.',
                [
                    'payment_id' =>
                        $payment->id,

                    'payment_number' =>
                        $payment->payment_number,

                    'invoice_id' =>
                        $payment->invoice_id,

                    'tx_ref' =>
                        $txRef,

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

            throw $exception;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Handle Webhook
    |--------------------------------------------------------------------------
    */

    public function handleWebhook(
        array $payload,
        ?string $signature = null
    ): PaymentVerificationResult {
        Log::info(
            'Chapa webhook received.',
            [
                'payload' =>
                    $payload,

                'payload_keys' =>
                    array_keys($payload),

                'signature_present' =>
                    !empty($signature),

                'trx_ref' =>
                    $payload['trx_ref'] ?? null,

                'tx_ref' =>
                    $payload['tx_ref'] ?? null,

                'ref_id' =>
                    $payload['ref_id'] ?? null,

                'status' =>
                    $payload['status'] ?? null,
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Resolve Transaction Reference
        |--------------------------------------------------------------------------
        */

        $txRef =
            $payload['trx_ref']
            ?? $payload['tx_ref']
            ?? $payload['reference']
            ?? null;

        /*
        | Support nested payloads as well.
        */

        if (
            !filled($txRef)
            && is_array($payload['data'] ?? null)
        ) {
            $data = $payload['data'];

            $txRef =
                $data['trx_ref']
                ?? $data['tx_ref']
                ?? $data['reference']
                ?? null;
        }

        if (!filled($txRef)) {
            throw new RuntimeException(
                'Chapa webhook does not contain a transaction reference.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Webhook Signature
        |--------------------------------------------------------------------------
        |
        | IMPORTANT:
        |
        | Do not invent a signature scheme for Chapa.
        |
        | The actual security mechanism must match Chapa's documented
        | webhook signature format.
        |
        | For this reason signature validation is disabled by default.
        |
        | Even without trusting the webhook status, we immediately perform
        | independent verification against Chapa's API.
        |
        */

        $signatureRequired = (bool) config(
            'services.chapa.verify_webhook_signature',
            false
        );

        if ($signatureRequired) {
            if (!$this->verifyWebhookSignature(
                $payload,
                $signature
            )) {
                throw new RuntimeException(
                    'Invalid Chapa webhook signature.'
                );
            }
        } else {
            Log::warning(
                'Chapa webhook signature verification is disabled.',
                [
                    'tx_ref' =>
                        $txRef,
                ]
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Find Local Payment
        |--------------------------------------------------------------------------
        */

        $payment =
            Payment::query()
                ->where(
                    'transaction_reference',
                    $txRef
                )
                ->first();

        Log::info(
            'Chapa webhook payment lookup completed.',
            [
                'tx_ref' =>
                    $txRef,

                'payment_found' =>
                    $payment !== null,

                'payment_id' =>
                    $payment?->id,

                'payment_number' =>
                    $payment?->payment_number,

                'invoice_id' =>
                    $payment?->invoice_id,

                'transaction_reference' =>
                    $payment?->transaction_reference,

                'provider_reference' =>
                    $payment?->provider_reference,

                'payment_status' =>
                    $payment?->status?->value
                    ?? $payment?->status,
            ]
        );

        if (!$payment) {
            throw new RuntimeException(
                'Payment associated with Chapa webhook was not found.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | NEVER TRUST WEBHOOK STATUS
        |--------------------------------------------------------------------------
        |
        | The webhook only tells us that a provider-side event occurred.
        |
        | The authoritative result comes from Chapa's verification API.
        |
        */

        Log::info(
            'Chapa webhook accepted. Starting independent payment verification.',
            [
                'payment_id' =>
                    $payment->id,

                'payment_number' =>
                    $payment->payment_number,

                'invoice_id' =>
                    $payment->invoice_id,

                'tx_ref' =>
                    $txRef,

                'webhook_provider_reference' =>
                    $payload['ref_id'] ?? null,

                'webhook_status' =>
                    $payload['status'] ?? null,
            ]
        );

        $result =
            $this->verify(
                $payment
            );

        Log::info(
            'Chapa webhook processing completed.',
            [
                'payment_id' =>
                    $payment->id,

                'payment_number' =>
                    $payment->payment_number,

                'invoice_id' =>
                    $payment->invoice_id,

                'tx_ref' =>
                    $txRef,

                'verification_status' =>
                    $result->status?->value
                    ?? $result->status,

                'is_successful' =>
                    $result->isSuccessful(),

                'message' =>
                    $result->message,

                'amount' =>
                    $result->amount,

                'currency' =>
                    $result->currency,
            ]
        );

        return $result;
    }

    /*
    |--------------------------------------------------------------------------
    | Supports Webhook
    |--------------------------------------------------------------------------
    */

    public function supportsWebhook(): bool
    {
        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | Supports Refund
    |--------------------------------------------------------------------------
    */

    public function supportsRefund(): bool
    {
        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | Refund
    |--------------------------------------------------------------------------
    */

    public function refund(
        Payment $payment,
        ?float $amount = null
    ): PaymentResult {
        throw new RuntimeException(
            'Automated refunds are not currently supported by the Chapa payment provider.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Base URL
    |--------------------------------------------------------------------------
    */

    protected function baseUrl(): string
    {
        $baseUrl = config(
            'services.chapa.base_url',
            'https://api.chapa.co'
        );

        return rtrim(
            $baseUrl,
            '/'
        ) . '/v1';
    }

    /*
    |--------------------------------------------------------------------------
    | HTTP Client
    |--------------------------------------------------------------------------
    */

    protected function client(): PendingRequest
    {
        $secretKey =
            config(
                'services.chapa.secret_key'
            );

        if (!$secretKey) {
            throw new RuntimeException(
                'Chapa secret key is not configured.'
            );
        }

        $timeout = (int) config(
            'services.chapa.timeout',
            30
        );

        $connectTimeout = (int) config(
            'services.chapa.connect_timeout',
            10
        );

        $retries = (int) config(
            'services.chapa.retries',
            3
        );

        return Http::baseUrl(
            $this->baseUrl()
        )
            ->withToken(
                $secretKey
            )
            ->acceptJson()
            ->asJson()
            ->timeout(
                $timeout
            )
            ->connectTimeout(
                $connectTimeout
            )
            ->retry(
                $retries,
                200,
                throw: false
            );
    }

    /*
    |--------------------------------------------------------------------------
    | Map Verification Response
    |--------------------------------------------------------------------------
    */

    protected function mapVerificationResponse(
        Payment $payment,
        array $body
    ): PaymentVerificationResult {
        /*
        |--------------------------------------------------------------------------
        | Extract Provider Data
        |--------------------------------------------------------------------------
        */

        $data =
            is_array($body['data'] ?? null)
                ? $body['data']
                : [];

        /*
        |--------------------------------------------------------------------------
        | IMPORTANT:
        |
        | The top-level:
        |
        |     status = success
        |
        | can represent successful API communication.
        |
        | We therefore prefer transaction status from data.
        |--------------------------------------------------------------------------
        */

        $statusRaw =
            $data['status']
            ?? $data['payment_status']
            ?? $data['transaction_status']
            ?? null;

        $status =
            strtolower(
                trim(
                    (string) $statusRaw
                )
            );

        /*
        |--------------------------------------------------------------------------
        | Transaction Reference
        |--------------------------------------------------------------------------
        |
        | Chapa should return the same tx_ref that was supplied during
        | initialization.
        |
        */

        $transactionReference =
            $data['tx_ref']
            ?? $data['trx_ref']
            ?? $data['transaction_reference']
            ?? null;

        /*
        |--------------------------------------------------------------------------
        | Provider Reference
        |--------------------------------------------------------------------------
        |
        | Chapa's provider-side reference is normally returned as
        | reference. Some responses may expose ref_id instead.
        |
        */

        $providerReference =
            $data['ref_id']
            ?? $data['reference']
            ?? $data['provider_reference']
            ?? null;

        /*
        |--------------------------------------------------------------------------
        | Amount
        |--------------------------------------------------------------------------
        */

        $amount =
            array_key_exists(
                'amount',
                $data
            )
                ? (float) $data['amount']
                : null;

        /*
        |--------------------------------------------------------------------------
        | Currency
        |--------------------------------------------------------------------------
        */

        $currency =
            array_key_exists(
                'currency',
                $data
            )
                ? strtoupper(
                    trim(
                        (string) $data['currency']
                    )
                )
                : null;

        /*
        |--------------------------------------------------------------------------
        | Provider Transaction ID
        |--------------------------------------------------------------------------
        */

        $providerTransactionId =
            $data['transaction_reference']
            ?? $data['transaction_id']
            ?? $data['reference']
            ?? $data['ref_id']
            ?? null;

        /*
        |--------------------------------------------------------------------------
        | Provider Timestamp
        |--------------------------------------------------------------------------
        */

        $paidAt = now();

        if (
            isset($data['updated_at'])
            && is_string($data['updated_at'])
        ) {
            try {
                $paidAt = \Carbon\Carbon::parse(
                    $data['updated_at']
                );
            } catch (Throwable $exception) {
                Log::warning(
                    'Unable to parse Chapa payment timestamp. Using local verification time.',
                    [
                        'payment_id' =>
                            $payment->id,

                        'updated_at' =>
                            $data['updated_at'],

                        'message' =>
                            $exception->getMessage(),
                    ]
                );
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Log Normalized Response
        |--------------------------------------------------------------------------
        */

        Log::info(
            'Chapa verification data normalized.',
            [
                'payment_id' =>
                    $payment->id,

                'payment_number' =>
                    $payment->payment_number,

                'local_transaction_reference' =>
                    $payment->transaction_reference,

                'local_provider_reference' =>
                    $payment->provider_reference,

                'chapa_status' =>
                    $status,

                'chapa_transaction_reference' =>
                    $transactionReference,

                'chapa_provider_reference' =>
                    $providerReference,

                'chapa_amount' =>
                    $amount,

                'local_amount' =>
                    $payment->amount,

                'chapa_currency' =>
                    $currency,

                'local_currency' =>
                    $payment->currency,

                'provider_transaction_id' =>
                    $providerTransactionId,

                'paid_at' =>
                    $paidAt->toIso8601String(),
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Validate Transaction Reference
        |--------------------------------------------------------------------------
        |
        | A successful verification must identify the same transaction
        | that belongs to this municipal payment.
        |
        */

        if (
            in_array(
                $status,
                [
                    'success',
                    'successful',
                    'completed',
                ],
                true
            )
        ) {
            if (!filled($transactionReference)) {
                throw new RuntimeException(
                    'Chapa verification response does not contain a transaction reference.'
                );
            }

            if (
                $transactionReference !==
                $payment->transaction_reference
            ) {
                Log::error(
                    'Chapa transaction reference mismatch.',
                    [
                        'payment_id' =>
                            $payment->id,

                        'expected' =>
                            $payment->transaction_reference,

                        'received' =>
                            $transactionReference,
                    ]
                );

                throw new RuntimeException(
                    'Chapa transaction reference does not match the local payment.'
                );
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Validate Amount
        |--------------------------------------------------------------------------
        |
        | A successful Chapa transaction MUST contain an amount.
        |
        */

        if (
            in_array(
                $status,
                [
                    'success',
                    'successful',
                    'completed',
                ],
                true
            )
        ) {
            if ($amount === null) {
                throw new RuntimeException(
                    'Chapa verification response does not contain a payment amount.'
                );
            }

            if (
                $this->compareMoney(
                    $amount,
                    $payment->amount
                ) !== 0
            ) {
                Log::error(
                    'Chapa payment amount mismatch.',
                    [
                        'payment_id' =>
                            $payment->id,

                        'expected_amount' =>
                            $payment->amount,

                        'chapa_amount' =>
                            $amount,
                    ]
                );

                throw new RuntimeException(
                    'Chapa payment amount does not match the local payment amount.'
                );
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Validate Currency
        |--------------------------------------------------------------------------
        */

        if (
            in_array(
                $status,
                [
                    'success',
                    'successful',
                    'completed',
                ],
                true
            )
        ) {
            if (!filled($currency)) {
                throw new RuntimeException(
                    'Chapa verification response does not contain a payment currency.'
                );
            }

            if (
                strtoupper($currency)
                !==
                strtoupper(
                    (string) $payment->currency
                )
            ) {
                Log::error(
                    'Chapa payment currency mismatch.',
                    [
                        'payment_id' =>
                            $payment->id,

                        'expected_currency' =>
                            $payment->currency,

                        'chapa_currency' =>
                            $currency,
                    ]
                );

                throw new RuntimeException(
                    'Chapa payment currency does not match the local payment currency.'
                );
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Metadata
        |--------------------------------------------------------------------------
        */

        $metadata = [
            ...$data,

            'provider_transaction_id' =>
                $providerTransactionId,

            'verified_transaction_reference' =>
                $transactionReference,

            'verified_provider_reference' =>
                $providerReference,

            'verified_amount' =>
                $amount,

            'verified_currency' =>
                $currency,

            'verified_at' =>
                now()->toIso8601String(),
        ];

        /*
        |--------------------------------------------------------------------------
        | SUCCESS
        |--------------------------------------------------------------------------
        */

        if (
            in_array(
                $status,
                [
                    'success',
                    'successful',
                    'completed',
                ],
                true
            )
        ) {
            Log::info(
                'Chapa payment verification mapped to SUCCESS.',
                [
                    'payment_id' =>
                        $payment->id,

                    'payment_number' =>
                        $payment->payment_number,

                    'invoice_id' =>
                        $payment->invoice_id,

                    'transaction_reference' =>
                        $transactionReference,

                    'provider_reference' =>
                        $providerReference,

                    'amount' =>
                        $amount,

                    'currency' =>
                        $currency,

                    'provider_transaction_id' =>
                        $providerTransactionId,
                ]
            );

            return PaymentVerificationResult::success(
                payment:
                    $payment,

                message:
                    $body['message']
                    ?? 'Payment verified successfully.',

                providerReference:
                    $providerReference,

                transactionReference:
                    $transactionReference,

                amount:
                    $amount,

                currency:
                    $currency,

                paidAt:
                    $paidAt,

                metadata:
                    $metadata,
            );
        }

        /*
        |--------------------------------------------------------------------------
        | FAILED
        |--------------------------------------------------------------------------
        */

        if (
            in_array(
                $status,
                [
                    'failed',
                    'cancelled',
                    'canceled',
                ],
                true
            )
        ) {
            return PaymentVerificationResult::failed(
                payment:
                    $payment,

                message:
                    $body['message']
                    ?? 'Payment verification failed.',

                providerReference:
                    $providerReference,

                transactionReference:
                    $transactionReference
                    ?? $payment->transaction_reference,

                amount:
                    $amount
                    ?? (float) $payment->amount,

                currency:
                    $currency
                    ?? $payment->currency,

                metadata:
                    $metadata,
            );
        }

        /*
        |--------------------------------------------------------------------------
        | PENDING / UNKNOWN
        |--------------------------------------------------------------------------
        */

        return PaymentVerificationResult::pending(
            payment:
                $payment,

            message:
                $body['message']
                ?? 'Payment is still pending.',

            providerReference:
                $providerReference,

            transactionReference:
                $transactionReference
                ?? $payment->transaction_reference,

            amount:
                $amount
                ?? (float) $payment->amount,

            currency:
                $currency
                ?? $payment->currency,

            metadata:
                $metadata,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Compare Money
    |--------------------------------------------------------------------------
    */

    protected function compareMoney(
        float|string $left,
        float|string $right
    ): int {
        $leftNormalized =
            number_format(
                (float) $left,
                2,
                '.',
                ''
            );

        $rightNormalized =
            number_format(
                (float) $right,
                2,
                '.',
                ''
            );

        if (function_exists('bccomp')) {
            return bccomp(
                $leftNormalized,
                $rightNormalized,
                2
            );
        }

        return $leftNormalized === $rightNormalized
            ? 0
            : (
                (float) $leftNormalized
                <
                (float) $rightNormalized
                    ? -1
                    : 1
            );
    }

    /*
    |--------------------------------------------------------------------------
    | Webhook Signature
    |--------------------------------------------------------------------------
    |
    | This method is intentionally only used when explicitly enabled.
    |
    | The canonicalization/signature algorithm must match the provider's
    | actual documented webhook specification.
    |--------------------------------------------------------------------------
    */

    protected function verifyWebhookSignature(
        array $payload,
        ?string $signature
    ): bool {
        if (!$signature) {
            Log::warning(
                'Chapa webhook signature is missing.',
                [
                    'trx_ref' =>
                        $payload['trx_ref']
                        ?? $payload['tx_ref']
                        ?? null,

                    'payload_keys' =>
                        array_keys($payload),
                ]
            );

            return false;
        }

        $secret =
            config(
                'services.chapa.webhook_secret'
            );

        if (!$secret) {
            throw new RuntimeException(
                'Chapa webhook secret is not configured.'
            );
        }

        $canonicalPayload =
            json_encode(
                $payload,
                JSON_UNESCAPED_SLASHES
                |
                JSON_UNESCAPED_UNICODE
            );

        if ($canonicalPayload === false) {
            return false;
        }

        $expected =
            hash_hmac(
                'sha256',
                $canonicalPayload,
                $secret
            );

        return hash_equals(
            $expected,
            $signature
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Split Customer Name
    |--------------------------------------------------------------------------
    */

    protected function splitCustomerName(
        ?string $name
    ): array {
        $name = trim(
            (string) $name
        );

        if ($name === '') {
            return ['', ''];
        }

        $parts =
            preg_split(
                '/\s+/',
                $name
            );

        if (!$parts) {
            return [$name, ''];
        }

        $firstName =
            $parts[0] ?? '';

        $lastName =
            count($parts) > 1
                ? implode(
                    ' ',
                    array_slice(
                        $parts,
                        1
                    )
                )
                : '';

        return [
            $firstName,
            $lastName,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Normalize Phone
    |--------------------------------------------------------------------------
    */

    protected function normalizePhone(
        ?string $phone
    ): ?string {
        if (!$phone) {
            return null;
        }

        $phone =
            preg_replace(
                '/\D+/',
                '',
                $phone
            );

        if (!$phone) {
            return null;
        }

        if (
            str_starts_with(
                $phone,
                '251'
            )
        ) {
            $phone =
                '0'
                . substr(
                    $phone,
                    3
                );
        }

        return $phone;
    }

    /*
    |--------------------------------------------------------------------------
    | Format Amount
    |--------------------------------------------------------------------------
    */

    protected function formatAmount(
        float $amount
    ): string {
        return number_format(
            $amount,
            2,
            '.',
            ''
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Truncate Description
    |--------------------------------------------------------------------------
    */

    protected function truncateDescription(
        string $description
    ): string {
        return mb_substr(
            trim($description),
            0,
            100
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Remove Null Values
    |--------------------------------------------------------------------------
    */

    protected function removeNullValues(
        array $payload
    ): array {
        return array_filter(
            $payload,
            static fn ($value) =>
                $value !== null
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Error Message
    |--------------------------------------------------------------------------
    */

    protected function extractErrorMessage(
        ?array $body
    ): string {
        if (!$body) {
            return 'Unable to communicate with Chapa.';
        }

        if (
            isset($body['message'])
            &&
            is_string(
                $body['message']
            )
        ) {
            return $body['message'];
        }

        if (
            isset($body['message'])
            &&
            is_array(
                $body['message']
            )
        ) {
            $messages = [];

            foreach (
                $body['message']
                as $field => $errors
            ) {
                if (is_array($errors)) {
                    foreach (
                        $errors
                        as $error
                    ) {
                        if (is_string($error)) {
                            $messages[] =
                                "{$field}: {$error}";
                        }
                    }
                } elseif (is_string($errors)) {
                    $messages[] =
                        "{$field}: {$errors}";
                }
            }

            if ($messages) {
                return implode(
                    '; ',
                    $messages
                );
            }
        }

        if (
            isset($body['error'])
            &&
            is_string(
                $body['error']
            )
        ) {
            return $body['error'];
        }

        return 'Chapa payment request failed.';
    }
}
