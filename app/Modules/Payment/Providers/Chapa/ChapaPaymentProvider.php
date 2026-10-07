<?php

declare(strict_types=1);

namespace App\Modules\Payment\Providers\Chapa;

use App\Enums\PaymentMethod;
use App\Enums\PaymentProvider;
use App\Models\Payment;
use App\Modules\Payment\Contracts\PaymentProviderInterface;
use App\Modules\Payment\DTOs\InitializePaymentData;
use App\Modules\Payment\DTOs\PaymentResult;
use App\Modules\Payment\DTOs\PaymentVerificationResult;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class ChapaPaymentProvider implements PaymentProviderInterface
{
    /**
     * Provider represented by this adapter.
     */
    private const PROVIDER = PaymentProvider::CHAPA;

    /**
     * Payment method supported by this adapter.
     */
    private const PAYMENT_METHOD = PaymentMethod::ONLINE;

    /**
     * Currency supported by the municipal revenue system
     * for Chapa payments.
     */
    private const CURRENCY = 'ETB';

    /**
     * Maximum description length accepted by this adapter.
     */
    private const MAX_DESCRIPTION_LENGTH = 200;

    /**
     * Chapa customization title.
     *
     * Chapa limits customization.title to 16 characters.
     */
    private const CHAPA_TITLE = 'Municipal Pay';

    /**
     * Maximum local transaction-reference length.
     */
    private const MAX_TRANSACTION_REFERENCE_LENGTH = 100;

    /**
     * Maximum response body length included in exceptions.
     */
    private const MAX_RESPONSE_BODY_LENGTH = 2000;

    public function __construct(
        private readonly ChapaClient $client,
        private readonly ChapaResponseMapper $mapper,
        private readonly ChapaWebhookService $webhookService,
    ) {
    }

    /**
     * Return the provider represented by this adapter.
     */
    public function provider(): PaymentProvider
    {
        return self::PROVIDER;
    }

    /**
     * Return the human-readable provider name.
     */
    public function displayName(): string
    {
        return 'Chapa';
    }

    /**
     * Initialize an online payment with Chapa.
     *
     * This method only communicates with Chapa and maps the
     * provider response.
     *
     * Local payment state is managed by PaymentService.
     */
    public function initialize(
        InitializePaymentData $data
    ): PaymentResult {
        $this->validateInitializeData($data);

        $payload = $this->buildInitializationPayload($data);

        try {
            $response = $this->client->initialize(
                $payload
            );
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Unable to communicate with Chapa while initializing the payment.',
                previous: $exception
            );
        }

        $this->ensureSuccessfulHttpResponse(
            response: $response,
            operation: 'initializing the payment',
        );

        $responseData = $response->json();

        if (! is_array($responseData)) {
            throw new RuntimeException(
                'Chapa returned an invalid initialization response.'
            );
        }

        try {
            return $this->mapper->mapInitializationResponse(
                $responseData,
                $data,
            );
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Unable to process the Chapa payment initialization response.',
                previous: $exception
            );
        }
    }

    /**
     * Independently verify a payment with Chapa.
     *
     * IMPORTANT:
     *
     * A callback parameter such as:
     *
     *     status=success
     *
     * is NOT trusted as proof of payment.
     *
     * This method calls Chapa's verification endpoint using the
     * canonical local transaction_reference / Chapa tx_ref.
     *
     * PaymentVerificationService remains responsible for applying
     * the verification result to the municipal financial records.
     */
    public function verify(
        Payment $payment
    ): PaymentVerificationResult {
        $this->validatePaymentForVerification(
            $payment
        );

        /*
         * payment.transaction_reference is the canonical municipal
         * transaction reference and is sent to Chapa as tx_ref.
         */
        $transactionReference = trim(
            (string) $payment->transaction_reference
        );

        try {
            $response = $this->client->verify(
                $transactionReference
            );
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Unable to communicate with Chapa while verifying the payment.',
                previous: $exception
            );
        }

        $this->ensureSuccessfulHttpResponse(
            response: $response,
            operation: 'verifying the payment',
        );

        $responseData = $response->json();

        if (! is_array($responseData)) {
            throw new RuntimeException(
                'Chapa returned an invalid verification response.'
            );
        }

        try {
            return $this->mapper->mapVerificationResponse(
                $payment,
                $responseData,
            );
        } catch (Throwable $exception) {
            throw new RuntimeException(
                sprintf(
                    'Unable to process the Chapa payment verification response: %s',
                    $exception->getMessage()
                ),
                previous: $exception
            );
        }
    }

    /**
     * Handle Chapa callback/webhook.
     *
     * Chapa-specific callback processing is delegated to
     * ChapaWebhookService.
     *
     * ChapaWebhookService must depend on the generic
     * PaymentVerificationService rather than this provider,
     * otherwise a circular dependency would be created.
     */
    public function handleWebhook(
        array $payload,
        ?string $signature = null
    ): PaymentVerificationResult {
        return $this->webhookService->handle(
            payload: $payload,
            signature: $signature,
        );
    }

    /**
     * Chapa supports callback/webhook processing.
     */
    public function supportsWebhook(): bool
    {
        return true;
    }

    /**
     * Automated Chapa refunds are not currently supported.
     */
    public function supportsRefund(): bool
    {
        return false;
    }

    /**
     * Refund a Chapa payment.
     *
     * The interface requires this method even though the current
     * municipal implementation does not support automated refunds.
     */
    public function refund(
        Payment $payment,
        ?float $amount = null
    ): PaymentResult {
        throw new RuntimeException(
            'Refunds are not currently supported for Chapa payments.'
        );
    }

    /**
     * Validate initialization data.
     */
    private function validateInitializeData(
        InitializePaymentData $data
    ): void {
        /*
         * Provider must be CHAPA.
         */
        if ($data->provider !== self::PROVIDER) {
            $receivedProvider = $data->provider instanceof PaymentProvider
                ? $data->provider->value
                : (string) $data->provider;

            throw new InvalidArgumentException(
                sprintf(
                    'Invalid provider supplied to ChapaPaymentProvider. Expected %s, received %s.',
                    self::PROVIDER->value,
                    $receivedProvider
                )
            );
        }

        /*
         * Chapa is an ONLINE payment provider.
         */
        if ($data->method !== self::PAYMENT_METHOD) {
            throw new InvalidArgumentException(
                'Chapa payments must use the ONLINE payment method.'
            );
        }

        /*
         * Validate amount without relying on loose numeric values.
         *
         * InitializePaymentData currently exposes amount as float,
         * so reject non-positive and non-finite values here.
         */
        if (
            ! is_finite($data->amount)
            || $data->amount <= 0
        ) {
            throw new InvalidArgumentException(
                'Payment amount must be greater than zero.'
            );
        }

        /*
         * Chapa payments in this municipal system use ETB.
         */
        $currency = strtoupper(
            trim(
                (string) $data->currency
            )
        );

        if ($currency !== self::CURRENCY) {
            throw new InvalidArgumentException(
                sprintf(
                    'Chapa payments must use %s currency. Received %s.',
                    self::CURRENCY,
                    $currency
                )
            );
        }

        /*
         * Callback URL is mandatory.
         */
        if (! $this->isValidUrl(
            $data->callbackUrl
        )) {
            throw new InvalidArgumentException(
                'Invalid Chapa callback URL.'
            );
        }

        /*
         * Return URL is mandatory.
         */
        if (! $this->isValidUrl(
            $data->returnUrl
        )) {
            throw new InvalidArgumentException(
                'Invalid Chapa return URL.'
            );
        }

        /*
         * The municipal transaction reference becomes Chapa tx_ref.
         */
        $paymentReference = trim(
            (string) $data->paymentReference
        );

        if ($paymentReference === '') {
            throw new InvalidArgumentException(
                'Payment transaction reference is required.'
            );
        }

        if (
            mb_strlen($paymentReference)
            > self::MAX_TRANSACTION_REFERENCE_LENGTH
        ) {
            throw new InvalidArgumentException(
                sprintf(
                    'Payment transaction reference must not exceed %d characters.',
                    self::MAX_TRANSACTION_REFERENCE_LENGTH
                )
            );
        }
    }

    /**
     * Validate the local payment before calling Chapa.
     *
     * IMPORTANT:
     *
     * The canonical provider field in the municipal Payment model
     * is payment_provider.
     *
     * Do NOT use $payment->provider here.
     *
     * Your current production error occurred because:
     *
     *     $payment->payment_provider = CHAPA
     *
     * while:
     *
     *     $payment->provider = null
     *
     * Therefore this method deliberately uses payment_provider.
     */
    private function validatePaymentForVerification(
        Payment $payment
    ): void {
        /*
         * ---------------------------------------------------------
         * 1. Validate provider
         * ---------------------------------------------------------
         *
         * This is the important production fix.
         */
        $provider = $this->resolvePaymentProvider(
            $payment
        );

        if ($provider !== self::PROVIDER) {
            throw new InvalidArgumentException(
                sprintf(
                    'Payment provider mismatch. Expected %s, received %s.',
                    self::PROVIDER->value,
                    $provider?->value ?? '[missing]'
                )
            );
        }

        /*
         * ---------------------------------------------------------
         * 2. Validate payment method
         * ---------------------------------------------------------
         */
        $method = $this->resolvePaymentMethod(
            $payment
        );

        if ($method !== self::PAYMENT_METHOD) {
            throw new InvalidArgumentException(
                sprintf(
                    'Payment method mismatch. Chapa requires %s, received %s.',
                    self::PAYMENT_METHOD->value,
                    $method?->value ?? '[missing]'
                )
            );
        }

        /*
         * ---------------------------------------------------------
         * 3. Validate transaction reference
         * ---------------------------------------------------------
         *
         * Chapa verification uses this value as tx_ref.
         */
        $transactionReference = trim(
            (string) $payment->transaction_reference
        );

        if ($transactionReference === '') {
            throw new InvalidArgumentException(
                'Payment transaction reference is missing.'
            );
        }

        if (
            mb_strlen($transactionReference)
            > self::MAX_TRANSACTION_REFERENCE_LENGTH
        ) {
            throw new InvalidArgumentException(
                sprintf(
                    'Payment transaction reference must not exceed %d characters.',
                    self::MAX_TRANSACTION_REFERENCE_LENGTH
                )
            );
        }

        /*
         * ---------------------------------------------------------
         * 4. Validate amount
         * ---------------------------------------------------------
         */
        if ($payment->amount === null) {
            throw new InvalidArgumentException(
                'Payment amount is missing.'
            );
        }

        $amount = (float) $payment->amount;

        if (
            ! is_finite($amount)
            || $amount <= 0
        ) {
            throw new InvalidArgumentException(
                'Payment amount must be greater than zero.'
            );
        }

        /*
         * ---------------------------------------------------------
         * 5. Validate currency
         * ---------------------------------------------------------
         */
        $currency = strtoupper(
            trim(
                (string) $payment->currency
            )
        );

        if ($currency !== self::CURRENCY) {
            throw new InvalidArgumentException(
                sprintf(
                    'Payment currency must be %s. Received %s.',
                    self::CURRENCY,
                    $currency
                )
            );
        }
    }

    /**
     * Resolve the provider from the canonical Payment attribute.
     *
     * The database/model contract is:
     *
     *     payments.payment_provider
     *
     * This method also supports the attribute being cast to
     * PaymentProvider or stored as a string.
     */
    private function resolvePaymentProvider(
        Payment $payment
    ): ?PaymentProvider {
        /*
         * IMPORTANT:
         *
         * Do not use:
         *
         *     $payment->provider
         *
         * because that is not the canonical Payment attribute
         * in the current payment model.
         */
        $value = $payment->payment_provider;

        if ($value instanceof PaymentProvider) {
            return $value;
        }

        if ($value === null) {
            return null;
        }

        $value = strtoupper(
            trim(
                (string) $value
            )
        );

        if ($value === '') {
            return null;
        }

        /*
         * PaymentProvider::tryFrom() expects the enum backing value.
         */
        return PaymentProvider::tryFrom(
            $value
        );
    }

    /**
     * Resolve the payment method from the canonical Payment attribute.
     */
    private function resolvePaymentMethod(
        Payment $payment
    ): ?PaymentMethod {
        $value = $payment->payment_method;

        if ($value instanceof PaymentMethod) {
            return $value;
        }

        if ($value === null) {
            return null;
        }

        $value = strtoupper(
            trim(
                (string) $value
            )
        );

        if ($value === '') {
            return null;
        }

        return PaymentMethod::tryFrom(
            $value
        );
    }

    /**
     * Build the Chapa initialization payload.
     */
    private function buildInitializationPayload(
        InitializePaymentData $data
    ): array {
        $payload = [
            /*
             * Chapa expects amount as a decimal string.
             */
            'amount' => $this->formatAmount(
                $data->amount
            ),

            /*
             * Chapa currently receives ETB from the municipal
             * revenue system.
             */
            'currency' => self::CURRENCY,

            /*
             * Optional customer information.
             */
            'email' => $this->normalizeEmail(
                $data->email ?? null
            ),

            'first_name' => $this->normalizeName(
                $data->firstName ?? null
            ),

            'last_name' => $this->normalizeName(
                $data->lastName ?? null
            ),

            'phone_number' => $this->normalizeEthiopianPhone(
                $data->phoneNumber ?? null
            ),

            /*
             * Canonical municipal transaction reference.
             *
             * Example:
             *
             * PAY-01M4BC0XK6JT4VXHXMYAR5FC81
             */
            'tx_ref' => trim(
                (string) $data->paymentReference
            ),

            /*
             * Chapa callback endpoint.
             *
             * The callback triggers backend verification.
             * It is NOT itself trusted as proof of payment.
             */
            'callback_url' => trim(
                (string) $data->callbackUrl
            ),

            /*
             * Browser return endpoint.
             */
            'return_url' => trim(
                (string) $data->returnUrl
            ),

            /*
             * Chapa checkout customization.
             */
            'customization' => [
                'title' => self::CHAPA_TITLE,

                'description' => $this->buildDescription(
                    $data
                ),
            ],
        ];

        return $this->removeEmptyValues(
            $payload
        );
    }

    /**
     * Build a safe Chapa payment description.
     */
    private function buildDescription(
        InitializePaymentData $data
    ): string {
        $description = $data->description
            ?? sprintf(
                'Municipal revenue payment %s',
                trim(
                    (string) $data->paymentReference
                )
            );

        /*
         * Normalize whitespace.
         */
        $description = trim(
            preg_replace(
                '/\s+/u',
                ' ',
                $description
            ) ?? $description
        );

        /*
         * Guarantee a meaningful fallback.
         */
        if ($description === '') {
            $description = sprintf(
                'Municipal revenue payment %s',
                trim(
                    (string) $data->paymentReference
                )
            );
        }

        /*
         * Respect provider length limits.
         */
        return mb_substr(
            $description,
            0,
            self::MAX_DESCRIPTION_LENGTH
        );
    }

    /**
     * Format the amount exactly as required by Chapa.
     *
     * NOTE:
     *
     * The DTO currently exposes amount as float.
     * The broader financial system should preferably use
     * decimal strings/BCMath for authoritative financial
     * calculations.
     */
    private function formatAmount(
        float $amount
    ): string {
        if (
            ! is_finite($amount)
            || $amount <= 0
        ) {
            throw new InvalidArgumentException(
                'Payment amount must be greater than zero.'
            );
        }

        return number_format(
            $amount,
            2,
            '.',
            ''
        );
    }

    /**
     * Validate an HTTP or HTTPS URL.
     */
    private function isValidUrl(
        ?string $url
    ): bool {
        if ($url === null) {
            return false;
        }

        $url = trim($url);

        if ($url === '') {
            return false;
        }

        if (
            filter_var(
                $url,
                FILTER_VALIDATE_URL
            ) === false
        ) {
            return false;
        }

        $scheme = strtolower(
            (string) parse_url(
                $url,
                PHP_URL_SCHEME
            )
        );

        if (
            ! in_array(
                $scheme,
                [
                    'http',
                    'https',
                ],
                true
            )
        ) {
            return false;
        }

        /*
         * A callback/return URL must have a host.
         */
        $host = parse_url(
            $url,
            PHP_URL_HOST
        );

        return is_string($host)
            && trim($host) !== '';
    }

    /**
     * Normalize optional customer email.
     *
     * Invalid optional email values are omitted instead of
     * preventing an otherwise valid payment from being initialized.
     */
    private function normalizeEmail(
        ?string $email
    ): ?string {
        if ($email === null) {
            return null;
        }

        $email = trim($email);

        if ($email === '') {
            return null;
        }

        if (
            filter_var(
                $email,
                FILTER_VALIDATE_EMAIL
            ) === false
        ) {
            return null;
        }

        return mb_substr(
            $email,
            0,
            254
        );
    }

    /**
     * Normalize an optional customer name.
     */
    private function normalizeName(
        ?string $name
    ): ?string {
        if ($name === null) {
            return null;
        }

        $name = trim(
            preg_replace(
                '/\s+/u',
                ' ',
                $name
            ) ?? $name
        );

        if ($name === '') {
            return null;
        }

        return mb_substr(
            $name,
            0,
            100
        );
    }

    /**
     * Normalize Ethiopian mobile phone number.
     *
     * Supported examples:
     *
     * 0911234567
     * 911234567
     * +251911234567
     * 00251911234567
     */
    private function normalizeEthiopianPhone(
        ?string $phone
    ): ?string {
        if ($phone === null) {
            return null;
        }

        $phone = trim($phone);

        if ($phone === '') {
            return null;
        }

        /*
         * Remove whitespace, hyphens, parentheses, etc.
         * Preserve a leading +.
         */
        $phone = preg_replace(
            '/(?!^\+)[^\d]/',
            '',
            $phone
        ) ?? $phone;

        /*
         * Convert 00 international prefix.
         *
         * 00251911234567
         *       ↓
         * +251911234567
         */
        if (
            str_starts_with(
                $phone,
                '00'
            )
        ) {
            $phone = '+' . substr(
                $phone,
                2
            );
        }

        /*
         * Convert Ethiopian local format.
         *
         * 0911234567
         *       ↓
         * +251911234567
         */
        if (
            str_starts_with(
                $phone,
                '0'
            )
        ) {
            $phone = '+251' . substr(
                $phone,
                1
            );
        }

        /*
         * Convert 911234567.
         */
        elseif (
            preg_match(
                '/^9\d{8}$/',
                $phone
            ) === 1
        ) {
            $phone = '+251' . $phone;
        }

        /*
         * Validate final Ethiopian mobile format.
         */
        if (
            preg_match(
                '/^\+2519\d{8}$/',
                $phone
            ) !== 1
        ) {
            return null;
        }

        return $phone;
    }

    /**
     * Recursively remove null and empty-string values.
     */
    private function removeEmptyValues(
        array $data
    ): array {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $value = $this->removeEmptyValues(
                    $value
                );

                if ($value === []) {
                    unset($data[$key]);

                    continue;
                }

                $data[$key] = $value;

                continue;
            }

            if ($value === null) {
                unset($data[$key]);

                continue;
            }

            if (
                is_string($value)
                && trim($value) === ''
            ) {
                unset($data[$key]);
            }
        }

        return $data;
    }

    /**
     * Ensure Chapa returned a successful HTTP response.
     */
    private function ensureSuccessfulHttpResponse(
        Response $response,
        string $operation
    ): void {
        if ($response->successful()) {
            return;
        }

        throw new RuntimeException(
            sprintf(
                'Chapa payment %s failed with HTTP status %d. Response: %s',
                $operation,
                $response->status(),
                $this->safeResponseBody($response)
            )
        );
    }

    /**
     * Safely represent an HTTP response body in an exception.
     *
     * Provider responses can contain sensitive information, so
     * known sensitive keys are redacted before inclusion.
     */
    private function safeResponseBody(
        Response $response
    ): string {
        try {
            $body = $response->json();

            if (is_array($body)) {
                $encoded = json_encode(
                    $this->sanitizeForLogging($body),
                    JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_INVALID_UTF8_SUBSTITUTE
                );

                if (
                    $encoded !== false
                    && $encoded !== ''
                ) {
                    return mb_substr(
                        $encoded,
                        0,
                        self::MAX_RESPONSE_BODY_LENGTH
                    );
                }

                return '[invalid JSON]';
            }

            $text = (string) $response->body();

            if ($text === '') {
                return '[empty response body]';
            }

            return mb_substr(
                $text,
                0,
                self::MAX_RESPONSE_BODY_LENGTH
            );
        } catch (Throwable) {
            return '[unable to read Chapa response]';
        }
    }

    /**
     * Redact sensitive fields from provider responses.
     */
    private function sanitizeForLogging(
        array $data
    ): array {
        $sensitiveKeys = [
            'secret',
            'secret_key',
            'authorization',
            'token',
            'access_token',
            'api_key',
            'password',
            'card_number',
            'cardnumber',
            'cvv',
            'cvc',
            'pin',
            'otp',
        ];

        foreach ($data as $key => $value) {
            $normalizedKey = strtolower(
                str_replace(
                    [
                        '-',
                        ' ',
                    ],
                    '_',
                    (string) $key
                )
            );

            if (
                in_array(
                    $normalizedKey,
                    $sensitiveKeys,
                    true
                )
            ) {
                $data[$key] = '[REDACTED]';

                continue;
            }

            if (is_array($value)) {
                $data[$key] = $this->sanitizeForLogging(
                    $value
                );
            }
        }

        return $data;
    }
}