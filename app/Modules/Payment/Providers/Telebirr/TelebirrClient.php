<?php

declare(strict_types=1);

namespace App\Modules\Payment\Providers\Telebirr;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class TelebirrClient
{
    private const TOKEN_ENDPOINT = '/payment/v1/token';

    private const PRE_ORDER_ENDPOINT = '/payment/v1/merchant/preOrder';

    private const QUERY_ORDER_ENDPOINT = '/payment/v1/merchant/queryOrder';

    private string $baseUrl;

    private int $timeout;

    private int $connectTimeout;

    private ?string $caBundle;

    public function __construct()
    {
        $this->baseUrl = rtrim(
            (string) config('services.telebirr.endpoint'),
            '/'
        );

        $this->timeout = (int) config(
            'services.telebirr.timeout',
            30
        );

        $this->connectTimeout = (int) config(
            'services.telebirr.connect_timeout',
            10
        );

        $caBundle = config('services.telebirr.ca_bundle');

        $this->caBundle = is_string($caBundle)
            && trim($caBundle) !== ''
                ? trim($caBundle)
                : null;

        $this->validateConfiguration();
    }

    /**
     * Validate HTTP configuration.
     */
    private function validateConfiguration(): void
    {
        if ($this->baseUrl === '') {
            throw new RuntimeException(
                'Telebirr API endpoint is not configured.'
            );
        }

        if (
            filter_var($this->baseUrl, FILTER_VALIDATE_URL) === false
            || strtolower(
                (string) parse_url($this->baseUrl, PHP_URL_SCHEME)
            ) !== 'https'
        ) {
            throw new RuntimeException(
                'Telebirr API endpoint must be a valid HTTPS URL.'
            );
        }

        if ($this->timeout < 1 || $this->connectTimeout < 1) {
            throw new RuntimeException(
                'Telebirr HTTP timeouts must be greater than zero.'
            );
        }

        if ($this->connectTimeout > $this->timeout) {
            throw new RuntimeException(
                'Telebirr connection timeout cannot exceed total timeout.'
            );
        }

        if (
            $this->caBundle !== null
            && ! is_file($this->caBundle)
        ) {
            throw new RuntimeException(
                'Telebirr CA bundle file does not exist: '
                . $this->caBundle
            );
        }
    }

    /**
     * Obtain the main Telebirr Fabric Token.
     *
     * POST /payment/v1/token
     *
     * Headers:
     * X-APP-Key: {fabricAppId}
     *
     * Body:
     * {
     *     "appSecret": "..."
     * }
     *
     * Return the original decoded response.
     * Never transform the token returned by Telebirr.
     */
    public function getMainToken(
        string $fabricAppId,
        array $payload,
    ): array {
        $this->assertNotEmpty(
            $fabricAppId,
            'Fabric App ID'
        );

        if (
            ! isset($payload['appSecret'])
            || ! is_string($payload['appSecret'])
        ) {
            throw new RuntimeException(
                'Telebirr token request requires an appSecret.'
            );
        }

        $this->assertNotEmpty(
            $payload['appSecret'],
            'App Secret'
        );

        $fabricAppId = trim($fabricAppId);

        Log::info('Telebirr main token request started.', [
            'endpoint' => self::TOKEN_ENDPOINT,
            'fabric_app_id_present' => true,
            'fabric_app_id_fingerprint' => $this->fingerprint(
                $fabricAppId
            ),
        ]);

        $response = $this->send(
            endpoint: self::TOKEN_ENDPOINT,
            payload: $payload,
            headers: [
                'X-APP-Key' => $fabricAppId,
            ],
            stage: 'main_token',
        );

        $token = $response['token'] ?? null;

        if (! is_string($token) || trim($token) === '') {
            $errorDetails = $this->extractProviderErrorDetails(
                $response
            );

            Log::error(
                'Telebirr main token response does not contain a valid token.',
                [
                    'response_keys' => array_keys($response),
                    'token_type' => get_debug_type($token),
                    'error_code' => $errorDetails['error_code'],
                    'error_message' => $errorDetails['error_message'],
                ]
            );

            throw new RuntimeException(
                'Telebirr did not return a valid Fabric Token.'
            );
        }

        // Preserve the original token, including any Bearer prefix.
        Log::info(
            'Telebirr main Fabric Token obtained successfully.',
            [
                'fabric_app_id_fingerprint' => $this->fingerprint(
                    $fabricAppId
                ),

                'token_length' => strlen($token),

                'token_fingerprint' => $this->fingerprint($token),

                'token_has_surrounding_whitespace' => (
                    $token !== trim($token)
                ),

                'token_has_bearer_prefix' => strncasecmp(
                    $token,
                    'Bearer ',
                    7
                ) === 0,

                'expiration_date' => $response['expirationDate'] ?? null,
            ]
        );

        return $response;
    }

    /**
     * Submit a C2B merchant preorder.
     *
     * POST /payment/v1/merchant/preOrder
     *
     * Headers:
     * X-APP-Key: {fabricAppId}
     * Authorization: {fabricToken}
     *
     * The Fabric Token is passed exactly as returned by
     * the token endpoint. No Bearer prefix is added or removed.
     */
    public function createPreOrder(
        string $fabricToken,
        array $payload,
        string $fabricAppId,
    ): array {
        $this->assertNotEmpty(
            $fabricToken,
            'Fabric Token'
        );

        $this->assertNotEmpty(
            $fabricAppId,
            'Fabric App ID'
        );

        $fabricAppId = trim($fabricAppId);

        // Reject whitespace rather than silently changing the token.
        if ($fabricToken !== trim($fabricToken)) {
            Log::warning(
                'Telebirr Fabric Token contains surrounding whitespace.',
                [
                    'original_token_length' => strlen($fabricToken),
                    'trimmed_token_length' => strlen(trim($fabricToken)),
                ]
            );

            throw new RuntimeException(
                'Telebirr Fabric Token contains unexpected whitespace.'
            );
        }

        $bizContent = $payload['biz_content'] ?? [];

        $authorizationHasBearerPrefix = strncasecmp(
            $fabricToken,
            'Bearer ',
            7
        ) === 0;

        Log::info(
            'Telebirr C2B preorder authentication diagnostics.',
            [
                'endpoint' => self::PRE_ORDER_ENDPOINT,

                'fabric_app_id_present' => true,

                'fabric_app_id_fingerprint' => $this->fingerprint(
                    $fabricAppId
                ),

                'authorization_present' => true,

                'authorization_length' => strlen($fabricToken),

                'authorization_fingerprint' => $this->fingerprint(
                    $fabricToken
                ),

                'authorization_matches_original_token' => true,

                'authorization_has_bearer_prefix' => (
                    $authorizationHasBearerPrefix
                ),

                'merchant_app_id_present' => is_array($bizContent)
                    && ! empty($bizContent['appid']),

                'merchant_code_present' => is_array($bizContent)
                    && ! empty($bizContent['merch_code']),

                'merchant_order_id_present' => is_array($bizContent)
                    && ! empty($bizContent['merch_order_id']),

                'signature_present' => ! empty($payload['sign']),

                'signature_length' => isset($payload['sign'])
                    && is_string($payload['sign'])
                        ? strlen($payload['sign'])
                        : 0,

                'sign_type' => $payload['sign_type'] ?? null,

                'payload_keys' => array_keys($payload),

                'biz_content_keys' => is_array($bizContent)
                    ? array_keys($bizContent)
                    : [],

                'timestamp' => $payload['timestamp'] ?? null,

                'nonce_length' => isset($payload['nonce_str'])
                    && is_string($payload['nonce_str'])
                        ? strlen($payload['nonce_str'])
                        : 0,
            ]
        );

        Log::info('Telebirr C2B preorder request started.', [
            'endpoint' => self::PRE_ORDER_ENDPOINT,
            'authentication' => 'fabric_token',
            'fabric_app_id_present' => true,
            'authorization_present' => true,
            'authorization_length' => strlen($fabricToken),
            'authorization_fingerprint' => $this->fingerprint(
                $fabricToken
            ),
            'authorization_has_bearer_prefix' => (
                $authorizationHasBearerPrefix
            ),
        ]);

        return $this->send(
            endpoint: self::PRE_ORDER_ENDPOINT,
            payload: $payload,
            headers: [
                'X-APP-Key' => $fabricAppId,
                'Authorization' => $fabricToken,
            ],
            stage: 'pre_order',
        );
    }

    /**
     * Query an existing merchant order.
     *
     * POST /payment/v1/merchant/queryOrder
     *
     * Authentication must be confirmed against the
     * QueryOrder documentation for your merchant account.
     *
     * This method preserves the supplied token unchanged.
     */
    public function queryOrder(
        string $accessToken,
        array $payload,
        ?string $appId = null,
    ): array {
        $this->assertNotEmpty(
            $accessToken,
            'Fabric Token'
        );

        if ($accessToken !== trim($accessToken)) {
            throw new RuntimeException(
                'Telebirr query Fabric Token contains unexpected whitespace.'
            );
        }

        $headers = [
            'Authorization' => $accessToken,
        ];

        if ($appId !== null) {
            $this->assertNotEmpty(
                $appId,
                'Fabric App ID'
            );

            $appId = trim($appId);

            $headers['X-APP-Key'] = $appId;
        }

        Log::info('Telebirr query order request started.', [
            'endpoint' => self::QUERY_ORDER_ENDPOINT,
            'fabric_app_id_present' => $appId !== null,
            'fabric_app_id_fingerprint' => $appId !== null
                ? $this->fingerprint($appId)
                : null,
            'authorization_present' => true,
            'authorization_length' => strlen($accessToken),
            'authorization_fingerprint' => $this->fingerprint(
                $accessToken
            ),
            'authorization_has_bearer_prefix' => strncasecmp(
                $accessToken,
                'Bearer ',
                7
            ) === 0,
        ]);

        return $this->send(
            endpoint: self::QUERY_ORDER_ENDPOINT,
            payload: $payload,
            headers: $headers,
            stage: 'query_order',
        );
    }

    /**
     * Send a POST request to Telebirr.
     *
     * @throws RuntimeException
     */
    private function send(
        string $endpoint,
        array $payload,
        array $headers = [],
        string $stage = 'unknown',
    ): array {
        if ($payload === []) {
            throw new RuntimeException(
                'Telebirr request payload cannot be empty.'
            );
        }

        $url = $this->baseUrl . $endpoint;

        try {
            $response = $this->httpRequest($headers)
                ->post($url, $payload);

            $data = $this->decodeResponse(
                $response,
                $stage,
            );

            if ($stage === 'main_token') {
                $this->logAuthenticationResponseStructure(
                    response: $data,
                    httpStatus: $response->status(),
                    stage: $stage,
                );
            }

            return $data;

        } catch (ConnectionException $exception) {
            Log::error('Telebirr HTTP connection failed.', [
                'stage' => $stage,
                'endpoint' => $endpoint,
                'exception' => $exception::class,
                'error' => $this->safeErrorValue(
                    $exception->getMessage()
                ),
                'timeout' => $this->timeout,
                'connect_timeout' => $this->connectTimeout,
            ]);

            throw new RuntimeException(
                'Unable to connect to the Telebirr payment service.',
                previous: $exception,
            );
        }
    }

    /**
     * Build the HTTP request configuration.
     */
    private function httpRequest(
        array $headers = [],
    ): PendingRequest {
        return Http::acceptJson()
            ->asJson()
            ->withHeaders($headers)
            ->connectTimeout($this->connectTimeout)
            ->timeout($this->timeout)
            ->withOptions([
                // Always verify Telebirr's TLS certificate.
                'verify' => $this->caBundle ?? true,
            ]);
    }

    /**
     * Decode and validate the provider response.
     */
    private function decodeResponse(
        Response $response,
        string $stage,
    ): array {
        $httpStatus = $response->status();

        $contentType = $response->header('Content-Type');

        $data = $response->json();

        if (! $response->successful()) {
            $errorDetails = is_array($data)
                ? $this->extractProviderErrorDetails($data)
                : [
                    'error_code' => null,
                    'error_message' => null,
                    'error_solution' => null,
                ];

            $responseBody = $response->body();

            Log::error(
                'Telebirr returned an unsuccessful HTTP response.',
                [
                    'stage' => $stage,
                    'http_status' => $httpStatus,
                    'content_type' => $contentType,
                    'is_json' => is_array($data),

                    'response_keys' => is_array($data)
                        ? array_keys($data)
                        : [],

                    'response_body_length' => strlen($responseBody),

                    'provider_error_code' => $errorDetails['error_code'],

                    'provider_error_message' => $errorDetails['error_message'],

                    'provider_error_solution' => $errorDetails['error_solution'],

                    'sanitized_response_body' => (
                        $errorDetails['error_code'] === null
                        && $errorDetails['error_message'] === null
                        && $errorDetails['error_solution'] === null
                    )
                        ? $this->safeErrorValue($responseBody)
                        : null,
                ]
            );

            throw new RuntimeException(
                'Telebirr returned an unsuccessful HTTP response. '
                . 'HTTP status: ' . $httpStatus
                . (
                    $errorDetails['error_code'] !== null
                        ? '. Provider error code: '
                            . $errorDetails['error_code']
                        : ''
                )
            );
        }

        if (! is_array($data)) {
            Log::error(
                'Telebirr returned an invalid JSON response.',
                [
                    'stage' => $stage,
                    'http_status' => $httpStatus,
                    'content_type' => $contentType,
                    'body_length' => strlen($response->body()),
                ]
            );

            throw new RuntimeException(
                'Telebirr returned an invalid JSON response.'
            );
        }

        return $data;
    }

    /**
     * Extract common provider error fields.
     */
    private function extractProviderErrorDetails(
        array $response,
    ): array {
        $containers = [
            $response,
            $response['data'] ?? null,
            $response['biz_content'] ?? null,
        ];

        $errorCode = null;
        $errorMessage = null;
        $errorSolution = null;

        foreach ($containers as $container) {
            if (! is_array($container)) {
                continue;
            }

            $errorCode ??= $this->safeErrorValue(
                $container['errorCode']
                    ?? $container['error_code']
                    ?? $container['code']
                    ?? null
            );

            $errorMessage ??= $this->safeErrorValue(
                $container['errorMsg']
                    ?? $container['error_msg']
                    ?? $container['message']
                    ?? $container['msg']
                    ?? null
            );

            $errorSolution ??= $this->safeErrorValue(
                $container['errorSolution']
                    ?? $container['error_solution']
                    ?? $container['solution']
                    ?? null
            );
        }

        return [
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
            'error_solution' => $errorSolution,
        ];
    }

    /**
     * Log authentication response structure without exposing credentials.
     */
    private function logAuthenticationResponseStructure(
        array $response,
        int $httpStatus,
        string $stage,
    ): void {
        $bizContent = $response['biz_content'] ?? null;

        $data = $response['data'] ?? null;

        $topLevelTypes = [];

        foreach ($response as $key => $value) {
            $topLevelTypes[$key] = get_debug_type($value);
        }

        $bizContentKeys = is_array($bizContent)
            ? array_keys($bizContent)
            : [];

        $bizContentTypes = [];

        if (is_array($bizContent)) {
            foreach ($bizContent as $key => $value) {
                $bizContentTypes[$key] = get_debug_type($value);
            }
        }

        $dataKeys = is_array($data)
            ? array_keys($data)
            : [];

        $dataTypes = [];

        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $dataTypes[$key] = get_debug_type($value);
            }
        }

        $errorDetails = $this->extractProviderErrorDetails(
            $response
        );

        $token = $response['token'] ?? null;

        Log::info(
            'Telebirr authentication response structure.',
            [
                'stage' => $stage,
                'http_status' => $httpStatus,

                'is_json' => true,

                'top_level_keys' => array_keys($response),
                'top_level_types' => $topLevelTypes,

                'biz_content_type' => get_debug_type($bizContent),
                'biz_content_keys' => $bizContentKeys,
                'biz_content_types' => $bizContentTypes,

                'data_type' => get_debug_type($data),
                'data_keys' => $dataKeys,
                'data_types' => $dataTypes,

                'token_present' => is_string($token)
                    && trim($token) !== '',

                'token_length' => is_string($token)
                    ? strlen($token)
                    : 0,

                'token_fingerprint' => is_string($token)
                    ? $this->fingerprint($token)
                    : null,

                'error_code' => $errorDetails['error_code'],
                'error_message' => $errorDetails['error_message'],
                'error_solution' => $errorDetails['error_solution'],
            ]
        );
    }

    /**
     * Generate a non-reversible diagnostic fingerprint.
     */
    private function fingerprint(string $value): string
    {
        return substr(hash('sha256', $value), 0, 16);
    }

    /**
     * Sanitize provider error descriptions before logging.
     */
    private function safeErrorValue(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $value = preg_replace(
            '/(access_token|refresh_token|token|appsecret|app_secret|secret|password|authorization|signature|private_key)\s*[:=]\s*[^\s,;]+/i',
            '$1=[REDACTED]',
            $value
        ) ?? '[REDACTED]';

        return mb_substr($value, 0, 500);
    }

    /**
     * Validate a required string.
     */
    private function assertNotEmpty(
        string $value,
        string $field,
    ): void {
        if (trim($value) === '') {
            throw new RuntimeException(
                "Telebirr {$field} cannot be empty."
            );
        }
    }
}
