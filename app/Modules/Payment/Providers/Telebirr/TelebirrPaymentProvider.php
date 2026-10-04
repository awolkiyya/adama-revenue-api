<?php

namespace App\Modules\Payment\Providers\Telebirr;

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

class TelebirrPaymentProvider implements PaymentProviderInterface
{
    /*
    |--------------------------------------------------------------------------
    | Configuration
    |--------------------------------------------------------------------------
    |
    | Keep Telebirr-specific configuration in config/services.php.
    |
    */

    private const TIMEOUT = 30;

    private const RETRIES = 3;

    private const CUSTOMIZATION_TITLE = 'Municipal Pay';

    /*
    |--------------------------------------------------------------------------
    | Provider
    |--------------------------------------------------------------------------
    */

    public function provider(): PaymentProvider
    {
        return PaymentProvider::TELEBIRR;
    }

    /*
    |--------------------------------------------------------------------------
    | Display Name
    |--------------------------------------------------------------------------
    */

    public function displayName(): string
    {
        return 'Telebirr';
    }

    /*
    |--------------------------------------------------------------------------
    | Initialize Payment
    |--------------------------------------------------------------------------
    */

    public function initialize(
        InitializePaymentData $data
    ): PaymentResult {
        $paymentReference =
            $data->paymentReference;

        /*
        |--------------------------------------------------------------------------
        | Telebirr Transaction Reference
        |--------------------------------------------------------------------------
        |
        | Keep your municipal payment reference as the primary correlation
        | reference whenever the Telebirr integration permits it.
        |
        */

        $transactionReference =
            $paymentReference;

        /*
        |--------------------------------------------------------------------------
        | Build Provider Payload
        |--------------------------------------------------------------------------
        |
        | IMPORTANT:
        |
        | The exact Telebirr request structure depends on the merchant
        | integration/API version provided to your municipality.
        |
        | Keep the mapping isolated here rather than leaking Telebirr
        | fields into PaymentService.
        |
        */

        $payload =
            $this->buildInitializePayload(
                data: $data,
                transactionReference: $transactionReference,
            );

        Log::info(
            'Initializing payment with Telebirr.',
            [
                'provider' =>
                    $this->provider()->value,

                'transaction_reference' =>
                    $transactionReference,

                'amount' =>
                    $data->amount,

                'currency' =>
                    $data->currency,
            ]
        );

        try {
            /*
            |--------------------------------------------------------------------------
            | Call Telebirr
            |--------------------------------------------------------------------------
            */

            $response =
                $this->client()->post(
                    $this->initializeEndpoint(),
                    $payload
                );

            $body =
                $response->json();

            /*
            |--------------------------------------------------------------------------
            | HTTP Failure
            |--------------------------------------------------------------------------
            */

            if (!$response->successful()) {

                Log::error(
                    'Telebirr payment initialization HTTP failure.',
                    [
                        'payment_reference' =>
                            $paymentReference,

                        'status' =>
                            $response->status(),

                        'response' =>
                            $body,
                    ]
                );

                throw new RuntimeException(
                    $this->extractErrorMessage(
                        $body
                    )
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Provider Failure
            |--------------------------------------------------------------------------
            */

            if (!$this->isSuccessfulResponse($body)) {

                Log::error(
                    'Telebirr rejected payment initialization.',
                    [
                        'payment_reference' =>
                            $paymentReference,

                        'response' =>
                            $body,
                    ]
                );

                throw new RuntimeException(
                    $this->extractErrorMessage(
                        $body
                    )
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Extract Provider Data
            |--------------------------------------------------------------------------
            */

            $providerData =
                $this->extractResponseData(
                    $body
                );

            /*
            |--------------------------------------------------------------------------
            | Checkout URL
            |--------------------------------------------------------------------------
            |
            | Depending on the Telebirr integration, this might be returned
            | under a different field. Keep extraction in one place.
            |
            */

            $checkoutUrl =
                $this->extractCheckoutUrl(
                    $providerData
                );

            if (!$checkoutUrl) {

                throw new RuntimeException(
                    'Telebirr did not return a checkout URL.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Provider Reference
            |--------------------------------------------------------------------------
            */

            $providerReference =
                $this->extractProviderReference(
                    $providerData
                )
                ?? $transactionReference;

            /*
            |--------------------------------------------------------------------------
            | Provider Transaction ID
            |--------------------------------------------------------------------------
            */

            $providerTransactionId =
                $this->extractProviderTransactionId(
                    $providerData
                );

            /*
            |--------------------------------------------------------------------------
            | Return Normalized Result
            |--------------------------------------------------------------------------
            */

            return PaymentResult::success(
                provider:
                    $this->provider(),

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
                    $this->extractMessage(
                        $body
                    )
                    ?? 'Payment initialized successfully.',

                metadata: [
                    'telebirr_response' =>
                        $providerData,
                ],
            );

        } catch (Throwable $exception) {

            Log::error(
                'Telebirr payment initialization exception.',
                [
                    'payment_reference' =>
                        $paymentReference,

                    'exception' =>
                        $exception::class,

                    'message' =>
                        $exception->getMessage(),
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

        $providerReference =
            $payment->provider_reference
            ?? $payment->transaction_reference;

        if (!$providerReference) {

            throw new RuntimeException(
                'Payment does not contain a Telebirr transaction reference.'
            );
        }

        try {

            $response =
                $this->client()->get(
                    $this->verifyEndpoint(
                        $providerReference
                    )
                );

            $body =
                $response->json();

            /*
            |--------------------------------------------------------------------------
            | HTTP Failure
            |--------------------------------------------------------------------------
            */

            if (!$response->successful()) {

                Log::error(
                    'Telebirr payment verification HTTP failure.',
                    [
                        'payment_id' =>
                            $payment->id,

                        'provider_reference' =>
                            $providerReference,

                        'status' =>
                            $response->status(),

                        'response' =>
                            $body,
                    ]
                );

                throw new RuntimeException(
                    $this->extractErrorMessage(
                        $body
                    )
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Map Provider Response
            |--------------------------------------------------------------------------
            */

            return $this->mapVerificationResponse(
                payment: $payment,
                body: $body,
            );

        } catch (Throwable $exception) {

            Log::error(
                'Telebirr payment verification exception.',
                [
                    'payment_id' =>
                        $payment->id,

                    'provider_reference' =>
                        $providerReference,

                    'exception' =>
                        $exception::class,

                    'message' =>
                        $exception->getMessage(),
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

        /*
        |--------------------------------------------------------------------------
        | Verify Signature
        |--------------------------------------------------------------------------
        */

        if (
            !$this->verifyWebhookSignature(
                $payload,
                $signature
            )
        ) {

            throw new RuntimeException(
                'Invalid Telebirr webhook signature.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Resolve Transaction Reference
        |--------------------------------------------------------------------------
        */

        $transactionReference =
            $this->extractWebhookReference(
                $payload
            );

        if (!$transactionReference) {

            throw new RuntimeException(
                'Telebirr webhook does not contain a transaction reference.'
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
                    $transactionReference
                )
                ->orWhere(
                    'provider_reference',
                    $transactionReference
                )
                ->first();

        if (!$payment) {

            throw new RuntimeException(
                'Payment associated with Telebirr webhook was not found.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Never Trust Webhook Alone
        |--------------------------------------------------------------------------
        |
        | The webhook tells us that something happened.
        |
        | We independently verify the transaction with Telebirr.
        |
        */

        return $this->verify(
            $payment
        );
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
            'Automated refunds are not currently supported by the Telebirr payment provider.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | HTTP Client
    |--------------------------------------------------------------------------
    */

    protected function client(): PendingRequest
    {
        $baseUrl =
            config(
                'services.telebirr.base_url'
            );

        if (!$baseUrl) {

            throw new RuntimeException(
                'Telebirr base URL is not configured.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Credentials
        |--------------------------------------------------------------------------
        |
        | The exact authentication mechanism depends on the Telebirr
        | merchant integration issued to you.
        |
        */

        $appKey =
            config(
                'services.telebirr.app_key'
            );

        $appSecret =
            config(
                'services.telebirr.app_secret'
            );

        if (!$appKey || !$appSecret) {

            throw new RuntimeException(
                'Telebirr credentials are not configured.'
            );
        }

        return Http::baseUrl(
            rtrim(
                $baseUrl,
                '/'
            )
        )
            ->acceptJson()
            ->asJson()
            ->timeout(
                self::TIMEOUT
            )
            ->retry(
                self::RETRIES,
                200,
                throw: false
            );
    }

    /*
    |--------------------------------------------------------------------------
    | Initialize Endpoint
    |--------------------------------------------------------------------------
    */

    protected function initializeEndpoint(): string
    {
        return (string) config(
            'services.telebirr.initialize_endpoint'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Verify Endpoint
    |--------------------------------------------------------------------------
    */

    protected function verifyEndpoint(
        string $providerReference
    ): string {

        $endpoint =
            (string) config(
                'services.telebirr.verify_endpoint'
            );

        return str_replace(
            '{reference}',
            urlencode(
                $providerReference
            ),
            $endpoint
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Build Initialize Payload
    |--------------------------------------------------------------------------
    */

    protected function buildInitializePayload(
        InitializePaymentData $data,
        string $transactionReference,
    ): array {

        return $this->removeNullValues([
            'amount' =>
                $this->formatAmount(
                    (float) $data->amount
                ),

            'currency' =>
                $data->currency,

            'transaction_reference' =>
                $transactionReference,

            'customer_name' =>
                $data->customerName,

            'customer_email' =>
                $data->customerEmail,

            'customer_phone' =>
                $this->normalizePhone(
                    $data->customerPhone
                ),

            'return_url' =>
                $data->returnUrl,

            'callback_url' =>
                $data->callbackUrl,

            'description' =>
                $this->truncateDescription(
                    $data->description
                    ?? 'Municipal invoice payment'
                ),

            'metadata' =>
                $data->metadata ?: null,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Response Success
    |--------------------------------------------------------------------------
    */

    protected function isSuccessfulResponse(
        ?array $body
    ): bool {

        if (!$body) {
            return false;
        }

        $status =
            strtolower(
                (string) (
                    $body['status']
                    ?? $body['code']
                    ?? ''
                )
            );

        return in_array(
            $status,
            [
                'success',
                'successful',
                '200',
                '0',
            ],
            true
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Extract Response Data
    |--------------------------------------------------------------------------
    */

    protected function extractResponseData(
        array $body
    ): array {

        if (
            isset($body['data'])
            &&
            is_array($body['data'])
        ) {

            return $body['data'];
        }

        if (
            isset($body['result'])
            &&
            is_array($body['result'])
        ) {

            return $body['result'];
        }

        return [];
    }

    /*
    |--------------------------------------------------------------------------
    | Extract Checkout URL
    |--------------------------------------------------------------------------
    */

    protected function extractCheckoutUrl(
        array $data
    ): ?string {

        $url =
            $data['checkout_url']
            ?? $data['checkoutUrl']
            ?? $data['payment_url']
            ?? $data['paymentUrl']
            ?? $data['redirect_url']
            ?? $data['redirectUrl']
            ?? null;

        return is_string($url) && $url !== ''
            ? $url
            : null;
    }

    /*
    |--------------------------------------------------------------------------
    | Extract Provider Reference
    |--------------------------------------------------------------------------
    */

    protected function extractProviderReference(
        array $data
    ): ?string {

        $reference =
            $data['transaction_reference']
            ?? $data['transactionReference']
            ?? $data['tx_ref']
            ?? $data['txRef']
            ?? $data['reference']
            ?? null;

        return is_scalar($reference)
            ? (string) $reference
            : null;
    }

    /*
    |--------------------------------------------------------------------------
    | Extract Provider Transaction ID
    |--------------------------------------------------------------------------
    */

    protected function extractProviderTransactionId(
        array $data
    ): ?string {

        $reference =
            $data['provider_transaction_id']
            ?? $data['providerTransactionId']
            ?? $data['transaction_id']
            ?? $data['transactionId']
            ?? $data['reference']
            ?? null;

        return is_scalar($reference)
            ? (string) $reference
            : null;
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

        $data =
            $this->extractResponseData(
                $body
            );

        /*
        |--------------------------------------------------------------------------
        | Status
        |--------------------------------------------------------------------------
        */

        $status =
            strtolower(
                (string) (
                    $data['status']
                    ?? $body['status']
                    ?? 'pending'
                )
            );

        /*
        |--------------------------------------------------------------------------
        | References
        |--------------------------------------------------------------------------
        */

        $providerReference =
            $this->extractProviderReference(
                $data
            )
            ?? $payment->provider_reference
            ?? $payment->transaction_reference;

        $transactionReference =
            $data['transaction_reference']
            ?? $data['transactionReference']
            ?? $payment->transaction_reference
            ?? $providerReference;

        /*
        |--------------------------------------------------------------------------
        | Amount
        |--------------------------------------------------------------------------
        */

        $amount =
            isset($data['amount'])
                ? (float) $data['amount']
                : (float) $payment->amount;

        /*
        |--------------------------------------------------------------------------
        | Currency
        |--------------------------------------------------------------------------
        */

        $currency =
            $data['currency']
            ?? $payment->currency;

        /*
        |--------------------------------------------------------------------------
        | Provider Transaction ID
        |--------------------------------------------------------------------------
        */

        $providerTransactionId =
            $this->extractProviderTransactionId(
                $data
            );

        /*
        |--------------------------------------------------------------------------
        | Metadata
        |--------------------------------------------------------------------------
        */

        $metadata = [
            ...$data,

            'provider_transaction_id' =>
                $providerTransactionId,
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
                    'paid',
                ],
                true
            )
        ) {

            return PaymentVerificationResult::success(
                payment:
                    $payment,

                message:
                    $this->extractMessage(
                        $body
                    )
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
                    now(),

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
                    'rejected',
                    'expired',
                ],
                true
            )
        ) {

            return PaymentVerificationResult::failed(
                payment:
                    $payment,

                message:
                    $this->extractMessage(
                        $body
                    )
                    ?? 'Payment verification failed.',

                providerReference:
                    $providerReference,

                transactionReference:
                    $transactionReference,

                amount:
                    $amount,

                currency:
                    $currency,

                metadata:
                    $metadata,
            );
        }

        /*
        |--------------------------------------------------------------------------
        | PENDING
        |--------------------------------------------------------------------------
        */

        return PaymentVerificationResult::pending(
            payment:
                $payment,

            message:
                $this->extractMessage(
                    $body
                )
                ?? 'Payment is still pending.',

            providerReference:
                $providerReference,

            transactionReference:
                $transactionReference,

            amount:
                $amount,

            currency:
                $currency,

            metadata:
                $metadata,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Webhook Signature
    |--------------------------------------------------------------------------
    */

    protected function verifyWebhookSignature(
        array $payload,
        ?string $signature
    ): bool {

        if (!$signature) {
            return false;
        }

        $secret =
            config(
                'services.telebirr.webhook_secret'
            );

        if (!$secret) {

            throw new RuntimeException(
                'Telebirr webhook secret is not configured.'
            );
        }

        $canonicalPayload =
            json_encode(
                $payload,
                JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
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
    | Webhook Reference
    |--------------------------------------------------------------------------
    */

    protected function extractWebhookReference(
        array $payload
    ): ?string {

        $reference =
            $payload['transaction_reference']
            ?? $payload['transactionReference']
            ?? $payload['tx_ref']
            ?? $payload['txRef']
            ?? $payload['reference']
            ?? null;

        return is_scalar($reference)
            ? (string) $reference
            : null;
    }

    /*
    |--------------------------------------------------------------------------
    | Message
    |--------------------------------------------------------------------------
    */

    protected function extractMessage(
        array $body
    ): ?string {

        $message =
            $body['message']
            ?? $body['msg']
            ?? null;

        return is_string($message)
            ? $message
            : null;
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

        /*
        |--------------------------------------------------------------------------
        | Ethiopian Number
        |--------------------------------------------------------------------------
        |
        | +251911996750
        |       ↓
        | 0911996750
        |
        */

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
            return 'Unable to communicate with Telebirr.';
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
            isset($body['msg'])
            &&
            is_string(
                $body['msg']
            )
        ) {

            return $body['msg'];
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

                    foreach ($errors as $error) {

                        if (is_string($error)) {

                            $messages[] =
                                "{$field}: {$error}";
                        }
                    }

                } elseif (
                    is_string($errors)
                ) {

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

        return 'Telebirr payment request failed.';
    }
}