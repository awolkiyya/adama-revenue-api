<?php

declare(strict_types=1);

namespace App\Modules\Payment\Providers\Telebirr;

use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Helpers\TelebirrHelper;
use App\Models\Payment;
use App\Modules\Payment\Contracts\PaymentProviderInterface;
use App\Modules\Payment\DTOs\InitializePaymentData;
use App\Modules\Payment\DTOs\PaymentResult;
use App\Modules\Payment\DTOs\PaymentVerificationResult;
use App\Modules\Payment\Providers\Telebirr\DTOs\TelebirrQueryResult;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class TelebirrPaymentProvider implements PaymentProviderInterface
{
    public function __construct(
        private readonly TelebirrClient $client,
        private readonly TelebirrPayloadFactory $payloadFactory,
        private readonly TelebirrResponseMapper $responseMapper,
        private readonly TelebirrWebhookService $webhookService,
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | Provider Information
    |--------------------------------------------------------------------------
    */

    public function provider(): PaymentProvider
    {
        return PaymentProvider::TELEBIRR;
    }

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
        $this->assertCorrectProvider($data->provider);
        $this->assertSupportedCurrency($data->currency);
        $this->assertValidAmount($data->amount);

        $merchantOrderId = $this->payloadFactory
            ->createMerchantOrderId();

        $currentStage = 'initialization';

        try {
            /*
            |--------------------------------------------------------------------------
            | Step 1: Load Telebirr Configuration
            |--------------------------------------------------------------------------
            */

            $currentStage = 'configuration';

            // Fabric App ID: used in X-APP-Key.
            $fabricAppId = $this->requiredConfig('appid');

            $appSecret = $this->requiredConfig('appsecret');

            // Merchant App ID: used inside biz_content.appid.
            $merchantAppId = $this->requiredConfig('merchant_app_id');

            $merchantCode = $this->requiredConfig('shortcode');

            $checkoutBaseUrl = $this->requiredConfig('checkout_url');

            $notifyUrl = $data->callbackUrl
                ?? $this->requiredConfig('callback_url');

            $returnUrl = $data->returnUrl
                ?? $this->requiredConfig('return_url');

            $this->assertValidUrl($notifyUrl, 'Callback URL');
            $this->assertValidUrl($returnUrl, 'Return URL');

            $this->assertValidUrl(
                $checkoutBaseUrl,
                'Checkout URL'
            );

            $privateKey = TelebirrHelper::loadPrivateKey();

            /*
            |--------------------------------------------------------------------------
            | Step 2: Obtain Main Fabric Token
            |--------------------------------------------------------------------------
            |
            | POST /payment/v1/token
            |
            | Header:
            | X-APP-Key: {fabricAppId}
            |
            | Body:
            | {
            |     "appSecret": "..."
            | }
            |
            | Telebirr's response may contain:
            |
            | {
            |     "token": "Bearer xxxxxxxxx...",
            |     "effectiveDate": "...",
            |     "expirationDate": "..."
            | }
            |
            | The Authorization header for preorder must contain
            | the raw token, without the Bearer prefix.
            |
            */

            $currentStage = 'main_token_request';

            Log::info(
                'Telebirr main token request started.',
                [
                    'payment_reference' => $data->paymentReference,
                    'merchant_order_id' => $merchantOrderId,
                    'fabric_app_id_present' => true,
                ]
            );

            $mainTokenResponse = $this->client->getMainToken(
                fabricAppId: $fabricAppId,
                payload: $this->payloadFactory->createMainTokenPayload(
                    $appSecret
                ),
            );

            $this->logAuthenticationResponseStructure(
                stage: 'main_token',
                response: $mainTokenResponse,
                paymentReference: $data->paymentReference,
                merchantOrderId: $merchantOrderId,
            );

            /*
            |--------------------------------------------------------------------------
            | Step 3: Extract Raw Fabric Token
            |--------------------------------------------------------------------------
            |
            | Extract the exact top-level token returned by Telebirr.
            |
            | If it starts with "Bearer ", remove that prefix.
            | Do not add a Bearer prefix to the outgoing request.
            |
            */

            $currentStage = 'main_token_mapping';

            $fabricToken = $this->extractFabricToken(
                response: $mainTokenResponse,
                stage: 'initialization',
                paymentReference: $data->paymentReference,
                merchantOrderId: $merchantOrderId,
            );

            Log::info(
                'Telebirr main Fabric Token extracted successfully.',
                [
                    'payment_reference' => $data->paymentReference,
                    'merchant_order_id' => $merchantOrderId,
                    'fabric_token_length' => strlen($fabricToken),
                    'fabric_token_fingerprint' => substr(
                        hash('sha256', $fabricToken),
                        0,
                        16
                    ),
                    'authorization_has_bearer_prefix' => false,
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Step 4: Build C2B Checkout Preorder Payload
            |--------------------------------------------------------------------------
            */

            $currentStage = 'preorder_payload_generation';

            $preOrderPayload = $this->payloadFactory
                ->createPreOrderPayload(
                    title: $this->buildPaymentTitle($data),
                    amount: $this->formatAmount($data->amount),
                    merchantAppId: $merchantAppId,
                    merchantCode: $merchantCode,
                    merchantOrderId: $merchantOrderId,
                    notifyUrl: $notifyUrl,
                    returnUrl: $returnUrl,
                    privateKeyPem: $privateKey,
                    meta: [
                        'payment_reference' => $data->paymentReference,
                        'invoice_id' => $data->invoiceId,
                    ],
                );

            /*
            |--------------------------------------------------------------------------
            | Step 5: Submit C2B Preorder
            |--------------------------------------------------------------------------
            |
            | POST /payment/v1/merchant/preOrder
            |
            | Headers:
            |
            | X-APP-Key: {fabricAppId}
            | Authorization: {raw Fabric Token}
            |
            | Do not prepend "Bearer ".
            |
            */

            $currentStage = 'preorder_request';

            Log::info(
                'Telebirr C2B preorder request started.',
                [
                    'payment_reference' => $data->paymentReference,
                    'merchant_order_id' => $merchantOrderId,
                    'fabric_app_id_present' => true,
                    'merchant_app_id_present' => true,
                    'authorization_present' => true,
                    'authorization_length' => strlen($fabricToken),
                    'authorization_fingerprint' => substr(
                        hash('sha256', $fabricToken),
                        0,
                        16
                    ),
                    'authorization_has_bearer_prefix' => false,
                ]
            );

            $preOrderResponse = $this->client->createPreOrder(
                fabricToken: $fabricToken,
                payload: $preOrderPayload,
                fabricAppId: $fabricAppId,
            );

            /*
            |--------------------------------------------------------------------------
            | Step 6: Map Preorder Response
            |--------------------------------------------------------------------------
            */

            $currentStage = 'preorder_mapping';

            $preOrder = $this->responseMapper
                ->mapPreOrderResponse($preOrderResponse);

            if (
                ! is_string($preOrder->prepayId)
                || trim($preOrder->prepayId) === ''
            ) {
                throw new RuntimeException(
                    'Telebirr did not return a valid prepay ID.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Step 7: Generate Signed Browser Checkout Parameters
            |--------------------------------------------------------------------------
            */

            $currentStage = 'checkout_request_generation';

            $rawRequest = $this->payloadFactory
                ->createRawRequest(
                    prepayId: $preOrder->prepayId,
                    merchantAppId: $merchantAppId,
                    merchantCode: $merchantCode,
                    privateKeyPem: $privateKey,
                );

            if (
                ! is_string($rawRequest)
                || trim($rawRequest) === ''
            ) {
                throw new RuntimeException(
                    'Telebirr generated an empty checkout request.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Step 8: Construct Browser Checkout URL
            |--------------------------------------------------------------------------
            */

            $currentStage = 'checkout_url_generation';

            $checkoutUrl = $this->buildCheckoutUrl(
                checkoutBaseUrl: $checkoutBaseUrl,
                rawRequest: $rawRequest,
            );

            /*
            |--------------------------------------------------------------------------
            | Step 9: Log Successful Initialization
            |--------------------------------------------------------------------------
            */

            Log::info(
                'Telebirr browser checkout initialized successfully.',
                [
                    'payment_reference' => $data->paymentReference,
                    'merchant_order_id' => $merchantOrderId,
                    'prepay_id' => $preOrder->prepayId,
                    'amount' => $this->formatAmount($data->amount),
                    'currency' => strtoupper($data->currency),
                    'integration' => 'c2b_checkout',
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Step 10: Return Checkout URL
            |--------------------------------------------------------------------------
            */

            return PaymentResult::success(
                provider: $this->provider(),
                paymentReference: $data->paymentReference,
                amount: $data->amount,
                currency: $data->currency,
                providerReference: $merchantOrderId,
                checkoutUrl: $checkoutUrl,
                providerTransactionId: null,
                message: 'Telebirr checkout initialized successfully.',
                metadata: [
                    'merchant_order_id' => $merchantOrderId,
                    'prepay_id' => $preOrder->prepayId,
                    'telebirr_trade_status' => $preOrder->rawTradeStatus,
                    'payment_integration' => 'c2b_checkout',
                ],
            );

        } catch (Throwable $exception) {
            Log::error(
                'Telebirr payment initialization failed.',
                [
                    'payment_reference' => $data->paymentReference,
                    'merchant_order_id' => $merchantOrderId,
                    'failure_stage' => $currentStage,
                    'exception' => $exception::class,
                    'message' => $exception instanceof \InvalidArgumentException
                        ? $exception->getMessage()
                        : 'An error occurred during Telebirr initialization.',
                ]
            );

            throw $exception;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Fabric Token Extraction
    |--------------------------------------------------------------------------
    */

    /**
     * Extract the raw Fabric Token returned by Telebirr.
     *
     * Expected response:
     *
     * {
     *     "token": "Bearer xxxxxxxxx...",
     *     "effectiveDate": "...",
     *     "expirationDate": "..."
     * }
     *
     * The returned token is normalized by removing one leading
     * "Bearer " prefix, if present.
     *
     * The resulting raw token is passed to TelebirrClient.
     */
   

     private function extractFabricToken(
        array $response,
        string $stage,
        string $paymentReference,
        string $merchantOrderId,
    ): string {
        $token = $response['token'] ?? null;
    
        if (! is_string($token) || trim($token) === '') {
            throw new RuntimeException(
                'Telebirr did not return a valid Fabric Token.'
            );
        }
    
        // Preserve the complete token exactly as returned by Telebirr.
        // Do not remove the Bearer prefix.
        if ($token !== trim($token)) {
            throw new RuntimeException(
                'Telebirr returned a Fabric Token containing unexpected whitespace.'
            );
        }
    
        Log::info('Telebirr main Fabric Token extracted successfully.', [
            'stage' => $stage,
            'payment_reference' => $paymentReference,
            'merchant_order_id' => $merchantOrderId,
            'fabric_token_length' => strlen($token),
            'fabric_token_fingerprint' => substr(
                hash('sha256', $token),
                0,
                16
            ),
            'authorization_has_bearer_prefix' => strncasecmp(
                $token,
                'Bearer ',
                7
            ) === 0,
        ]);
    
        return $token;
    }
    


    /*
    |--------------------------------------------------------------------------
    | Authentication Response Diagnostics
    |--------------------------------------------------------------------------
    */

    private function logAuthenticationResponseStructure(
        string $stage,
        array $response,
        string $paymentReference,
        string $merchantOrderId,
    ): void {
        $topLevelTypes = [];

        foreach ($response as $key => $value) {
            $topLevelTypes[(string) $key] = get_debug_type($value);
        }

        $bizContent = $response['biz_content'] ?? null;
        $data = $response['data'] ?? null;

        $bizContentKeys = is_array($bizContent)
            ? array_keys($bizContent)
            : [];

        $dataKeys = is_array($data)
            ? array_keys($data)
            : [];

        $bizContentTypes = [];

        if (is_array($bizContent)) {
            foreach ($bizContent as $key => $value) {
                $bizContentTypes[(string) $key] = get_debug_type($value);
            }
        }

        $dataTypes = [];

        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $dataTypes[(string) $key] = get_debug_type($value);
            }
        }

        Log::info(
            'Telebirr authentication response structure.',
            [
                'stage' => $stage,
                'payment_reference' => $paymentReference,
                'merchant_order_id' => $merchantOrderId,
                'top_level_keys' => array_keys($response),
                'top_level_types' => $topLevelTypes,
                'biz_content_type' => get_debug_type($bizContent),
                'biz_content_keys' => $bizContentKeys,
                'biz_content_types' => $bizContentTypes,
                'data_type' => get_debug_type($data),
                'data_keys' => $dataKeys,
                'data_types' => $dataTypes,
            ]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Verify Payment
    |--------------------------------------------------------------------------
    */

    public function verify(
        Payment $payment
    ): PaymentVerificationResult {
        $this->assertTelebirrPayment($payment);

        $merchantOrderId = $payment->provider_reference;

        if (
            ! is_string($merchantOrderId)
            || trim($merchantOrderId) === ''
        ) {
            throw new RuntimeException(
                'Payment does not contain a Telebirr merchant order reference.'
            );
        }

        $this->assertSupportedCurrency($payment->currency);

        $amount = (float) $payment->amount;

        $this->assertValidAmount($amount);

        $currentStage = 'verification';

        try {
            /*
            |--------------------------------------------------------------------------
            | Step 1: Load Configuration
            |--------------------------------------------------------------------------
            */

            $currentStage = 'verification_configuration';

            $fabricAppId = $this->requiredConfig('appid');
            $appSecret = $this->requiredConfig('appsecret');
            $merchantAppId = $this->requiredConfig('merchant_app_id');
            $merchantCode = $this->requiredConfig('shortcode');

            $privateKey = TelebirrHelper::loadPrivateKey();

            /*
            |--------------------------------------------------------------------------
            | Step 2: Obtain Main Fabric Token
            |--------------------------------------------------------------------------
            */

            $currentStage = 'verification_main_token_request';

            $mainTokenResponse = $this->client->getMainToken(
                fabricAppId: $fabricAppId,
                payload: $this->payloadFactory->createMainTokenPayload(
                    $appSecret
                ),
            );

            $this->logAuthenticationResponseStructure(
                stage: 'verification_main_token',
                response: $mainTokenResponse,
                paymentReference: (string) $payment->transaction_reference,
                merchantOrderId: $merchantOrderId,
            );

            $currentStage = 'verification_main_token_mapping';

            $fabricToken = $this->extractFabricToken(
                response: $mainTokenResponse,
                stage: 'verification',
                paymentReference: (string) $payment->transaction_reference,
                merchantOrderId: $merchantOrderId,
            );

            /*
            |--------------------------------------------------------------------------
            | Step 3: Build Signed Query Payload
            |--------------------------------------------------------------------------
            */

            $currentStage = 'query_payload_generation';

            $queryPayload = $this->payloadFactory
                ->createQueryOrderPayload(
                    merchantAppId: $merchantAppId,
                    merchantCode: $merchantCode,
                    merchantOrderId: $merchantOrderId,
                    privateKeyPem: $privateKey,
                );

            /*
            |--------------------------------------------------------------------------
            | Step 4: Query Telebirr
            |--------------------------------------------------------------------------
            */

            $currentStage = 'query_order_request';

            $queryResponse = $this->client->queryOrder(
                accessToken: $fabricToken,
                payload: $queryPayload,
                appId: $fabricAppId,
            );

            $currentStage = 'query_order_mapping';

            $query = $this->responseMapper
                ->mapQueryResponse($queryResponse);

            /*
            |--------------------------------------------------------------------------
            | Step 5: Map Verification Result
            |--------------------------------------------------------------------------
            */

            return $this->mapVerificationResult(
                payment: $payment,
                query: $query,
            );

        } catch (Throwable $exception) {
            Log::error(
                'Telebirr payment verification failed.',
                [
                    'payment_id' => $payment->getKey(),
                    'merchant_order_id' => $merchantOrderId,
                    'failure_stage' => $currentStage,
                    'exception' => $exception::class,
                    'message' => $exception instanceof \InvalidArgumentException
                        ? $exception->getMessage()
                        : 'An error occurred during Telebirr verification.',
                ]
            );

            throw $exception;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Map Verification Result
    |--------------------------------------------------------------------------
    */

    private function mapVerificationResult(
        Payment $payment,
        TelebirrQueryResult $query,
    ): PaymentVerificationResult {
        $transactionId = $query->transactionId;

        $metadata = [
            'telebirr_trade_status' => $query->tradeStatus,
            'telebirr_prepay_id' => $query->prepayId,
        ];

        if ($query->isSuccessful()) {
            return PaymentVerificationResult::success(
                payment: $payment,
                status: PaymentStatus::COMPLETED,
                transactionReference: $payment->transaction_reference,
                transactionId: $transactionId,
                providerReference: $payment->provider_reference,
                amount: (float) $payment->amount,
                currency: $payment->currency,
                paidAt: now(),
                metadata: $metadata,
                message: 'Telebirr payment successfully verified.',
            );
        }

        if ($query->isFailed()) {
            return PaymentVerificationResult::failed(
                payment: $payment,
                status: PaymentStatus::FAILED,
                message: 'Telebirr reports that this transaction failed.',
                transactionReference: $payment->transaction_reference,
                transactionId: $transactionId,
                providerReference: $payment->provider_reference,
                amount: (float) $payment->amount,
                currency: $payment->currency,
                metadata: $metadata,
            );
        }

        return PaymentVerificationResult::pending(
            payment: $payment,
            message: 'Telebirr payment is still pending.',
            transactionReference: $payment->transaction_reference,
            transactionId: $transactionId,
            providerReference: $payment->provider_reference,
            amount: (float) $payment->amount,
            currency: $payment->currency,
            metadata: $metadata,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Handle Webhook
    |--------------------------------------------------------------------------
    */

    public function handleWebhook(
        array $payload,
        ?string $signature = null,
    ): PaymentVerificationResult {
        try {
            $payment = $this->webhookService->resolvePayment(
                payload: $payload,
            );

            /*
             * The callback is used to locate the payment.
             * Payment status is determined by querying Telebirr
             * server-to-server, not by trusting callback status fields.
             */
            return $this->verify($payment);

        } catch (Throwable $exception) {
            Log::error(
                'Telebirr webhook processing failed.',
                [
                    'exception' => $exception::class,
                    'message' => 'Telebirr webhook processing could not be completed.',
                ]
            );

            throw $exception;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Webhook Support
    |--------------------------------------------------------------------------
    */

    public function supportsWebhook(): bool
    {
        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | Refund Support
    |--------------------------------------------------------------------------
    */

    public function supportsRefund(): bool
    {
        return false;
    }

    public function refund(
        Payment $payment,
        ?float $amount = null,
    ): PaymentResult {
        throw new RuntimeException(
            'Automated refunds are not currently supported by Telebirr.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Checkout URL Construction
    |--------------------------------------------------------------------------
    */

    private function buildCheckoutUrl(
        string $checkoutBaseUrl,
        string $rawRequest,
    ): string {
        $checkoutBaseUrl = trim($checkoutBaseUrl);
        $rawRequest = trim($rawRequest);

        if ($checkoutBaseUrl === '' || $rawRequest === '') {
            throw new RuntimeException(
                'Telebirr checkout URL or signed request is empty.'
            );
        }

        $separator = str_contains($checkoutBaseUrl, '?')
            ? '&'
            : '?';

        if (
            str_ends_with($checkoutBaseUrl, '?')
            || str_ends_with($checkoutBaseUrl, '&')
        ) {
            $separator = '';
        }

        $checkoutUrl = $checkoutBaseUrl
            . $separator
            . $rawRequest
            . '&version=1.0&trade_type=Checkout';

        if (! filter_var($checkoutUrl, FILTER_VALIDATE_URL)) {
            throw new RuntimeException(
                'The generated Telebirr checkout URL is invalid.'
            );
        }

        return $checkoutUrl;
    }

    /*
    |--------------------------------------------------------------------------
    | Configuration
    |--------------------------------------------------------------------------
    */

    private function requiredConfig(string $key): string
    {
        $value = config("services.telebirr.{$key}");

        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException(
                "Telebirr configuration [{$key}] is missing."
            );
        }

        return trim($value);
    }

    /*
    |--------------------------------------------------------------------------
    | Input Validation
    |--------------------------------------------------------------------------
    */

    private function assertCorrectProvider(
        PaymentProvider $provider
    ): void {
        if ($provider !== PaymentProvider::TELEBIRR) {
            throw new RuntimeException(
                'The payment data does not belong to the Telebirr provider.'
            );
        }
    }

    private function assertTelebirrPayment(
        Payment $payment
    ): void {
        $provider = $payment->provider;

        if (
            $provider !== PaymentProvider::TELEBIRR
            && $provider !== PaymentProvider::TELEBIRR->value
        ) {
            throw new RuntimeException(
                'The payment does not belong to the Telebirr provider.'
            );
        }
    }

    private function assertSupportedCurrency(
        string $currency
    ): void {
        if (strtoupper(trim($currency)) !== 'ETB') {
            throw new RuntimeException(
                'Telebirr payments must use ETB.'
            );
        }
    }

    private function assertValidAmount(float $amount): void
    {
        if (! is_finite($amount) || $amount <= 0) {
            throw new RuntimeException(
                'Telebirr payment amount must be greater than zero.'
            );
        }
    }

    private function assertValidUrl(
        string $url,
        string $field,
    ): void {
        if (
            filter_var($url, FILTER_VALIDATE_URL) === false
            || ! in_array(
                strtolower((string) parse_url($url, PHP_URL_SCHEME)),
                ['http', 'https'],
                true,
            )
        ) {
            throw new RuntimeException(
                "Telebirr {$field} must be a valid HTTP or HTTPS URL."
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Payload Helpers
    |--------------------------------------------------------------------------
    */

    private function buildPaymentTitle(
        InitializePaymentData $data
    ): string {
        $title = $data->description
            ?? 'Municipal invoice payment';

        $title = trim($title);

        if ($title === '') {
            $title = 'Municipal invoice payment';
        }

        return mb_substr($title, 0, 100);
    }

    private function formatAmount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}
