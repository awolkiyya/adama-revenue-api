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

    private const BASE_URL = 'https://api.chapa.co/v1';

    private const TIMEOUT = 30;

    private const RETRIES = 3;

    /*
    |--------------------------------------------------------------------------
    | Chapa Constraints
    |--------------------------------------------------------------------------
    |
    | Keep the customization title within Chapa's supported
    | character limit.
    |
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
        | Internal Payment Reference
        |--------------------------------------------------------------------------
        |
        | Your internal payment reference is used as Chapa's tx_ref.
        |
        */

        $paymentReference =
            $data->paymentReference;

        $txRef =
            $paymentReference;

        /*
        |--------------------------------------------------------------------------
        | Split Customer Name
        |--------------------------------------------------------------------------
        */

        [
            $firstName,
            $lastName
        ] = $this->splitCustomerName(
            $data->customerName
        );

        /*
        |--------------------------------------------------------------------------
        | Normalize Phone
        |--------------------------------------------------------------------------
        */

        $phone =
            $this->normalizePhone(
                $data->customerPhone
            );

        /*
        |--------------------------------------------------------------------------
        | Build Chapa Payload
        |--------------------------------------------------------------------------
        */

        $payload = [
            'amount' =>
                $this->formatAmount(
                    (float) $data->amount
                ),

            'currency' =>
                $data->currency,

            'email' =>
                $data->customerEmail,

            'first_name' =>
                $firstName,

            'last_name' =>
                $lastName,

            'phone_number' =>
                $phone,

            'tx_ref' =>
                $txRef,

            'callback_url' =>
                $data->callbackUrl,

            'return_url' =>
                $data->returnUrl,

            'customization' => [
                'title' =>
                    self::CUSTOMIZATION_TITLE,

                'description' =>
                    $this->truncateDescription(
                        $data->description
                        ?? 'Municipal invoice payment'
                    ),
            ],

            'meta' =>
                $data->metadata ?: null,
        ];

        /*
        |--------------------------------------------------------------------------
        | Remove Null Values
        |--------------------------------------------------------------------------
        */

        $payload =
            $this->removeNullValues(
                $payload
            );

        /*
        |--------------------------------------------------------------------------
        | Log Initialization
        |--------------------------------------------------------------------------
        */

        Log::info(
            'Initializing payment with Chapa.',
            [
                'provider' =>
                    $this->provider()->value,

                'tx_ref' =>
                    $txRef,

                'amount' =>
                    $data->amount,

                'currency' =>
                    $data->currency,
            ]
        );

        try {
            /*
            |--------------------------------------------------------------------------
            | Call Chapa
            |--------------------------------------------------------------------------
            */

            $response =
                $this->client()->post(
                    '/transaction/initialize',
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
                    'Chapa payment initialization HTTP failure.',
                    [
                        'tx_ref' =>
                            $txRef,

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
            | Chapa Business Failure
            |--------------------------------------------------------------------------
            */

            if (
                ($body['status'] ?? null)
                !== 'success'
            ) {

                Log::error(
                    'Chapa rejected payment initialization.',
                    [
                        'tx_ref' =>
                            $txRef,

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
                is_array(
                    $body['data'] ?? null
                )
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
            */

            $providerReference =
                $providerData['tx_ref']
                ?? $txRef;

            /*
            |--------------------------------------------------------------------------
            | Provider Transaction ID
            |--------------------------------------------------------------------------
            */

            $providerTransactionId =
                $providerData['reference']
                ?? $providerData['transaction_reference']
                ?? null;

            /*
            |--------------------------------------------------------------------------
            | Return Normalized Result
            |--------------------------------------------------------------------------
            |
            | PaymentResult::success() now supports amount and currency.
            |
            | This allows PaymentService to safely access:
            |
            | $result->amount
            | $result->currency
            | $result->isSuccessful()
            |
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
                    'tx_ref' =>
                        $txRef,

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

        $txRef =
            $payment->provider_reference
            ?? $payment->transaction_reference;

        if (!$txRef) {

            throw new RuntimeException(
                'Payment does not contain a Chapa transaction reference.'
            );
        }

        try {

            $response =
                $this->client()->get(
                    '/transaction/verify/'
                    . urlencode($txRef)
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
                    'Chapa payment verification HTTP failure.',
                    [
                        'payment_id' =>
                            $payment->id,

                        'tx_ref' =>
                            $txRef,

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
            | Map Response
            |--------------------------------------------------------------------------
            */

            return $this->mapVerificationResponse(
                $payment,
                $body
            );

        } catch (Throwable $exception) {

            Log::error(
                'Chapa payment verification exception.',
                [
                    'payment_id' =>
                        $payment->id,

                    'tx_ref' =>
                        $txRef,

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
                'Invalid Chapa webhook signature.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Resolve Transaction Reference
        |--------------------------------------------------------------------------
        */

        $txRef =
            $payload['tx_ref']
            ?? null;

        if (!$txRef) {

            throw new RuntimeException(
                'Chapa webhook does not contain tx_ref.'
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
                ->orWhere(
                    'provider_reference',
                    $txRef
                )
                ->first();

        if (!$payment) {

            throw new RuntimeException(
                'Payment associated with Chapa webhook was not found.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Never Trust Webhook Alone
        |--------------------------------------------------------------------------
        |
        | The webhook is only an event notification.
        |
        | We independently call Chapa's verification API.
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
            'Automated refunds are not currently supported by the Chapa payment provider.'
        );
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

        return Http::baseUrl(
            self::BASE_URL
        )
            ->withToken(
                $secretKey
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
    | Map Verification Response
    |--------------------------------------------------------------------------
    */

    protected function mapVerificationResponse(
        Payment $payment,
        array $body
    ): PaymentVerificationResult {

        $data =
            is_array(
                $body['data'] ?? null
            )
                ? $body['data']
                : [];

        /*
        |--------------------------------------------------------------------------
        | Normalize Status
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
            $data['tx_ref']
            ?? $payment->provider_reference
            ?? $payment->transaction_reference;

        $transactionReference =
            $data['tx_ref']
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
            $data['reference']
            ?? $data['transaction_reference']
            ?? null;

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
            $status === 'success'
            ||
            $status === 'successful'
        ) {

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
            $status === 'failed'
            ||
            $status === 'cancelled'
            ||
            $status === 'canceled'
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
                $body['message']
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
                'services.chapa.webhook_secret'
            );

        if (!$secret) {

            throw new RuntimeException(
                'Chapa webhook secret is not configured.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Canonical Payload
        |--------------------------------------------------------------------------
        */

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
    | Split Customer Name
    |--------------------------------------------------------------------------
    */

    protected function splitCustomerName(
        ?string $name
    ): array {

        $name =
            trim(
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
            $parts[0]
            ?? '';

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

        /*
        |--------------------------------------------------------------------------
        | Convert Ethiopian International Format
        |--------------------------------------------------------------------------
        |
        | +251911996750
        |        ↓
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

            return 'Unable to communicate with Chapa.';
        }

        /*
        |--------------------------------------------------------------------------
        | String Message
        |--------------------------------------------------------------------------
        */

        if (
            isset($body['message'])
            &&
            is_string(
                $body['message']
            )
        ) {

            return $body['message'];
        }

        /*
        |--------------------------------------------------------------------------
        | Validation Message Object
        |--------------------------------------------------------------------------
        */

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

                        if (
                            is_string($error)
                        ) {

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

        /*
        |--------------------------------------------------------------------------
        | Error Field
        |--------------------------------------------------------------------------
        */

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