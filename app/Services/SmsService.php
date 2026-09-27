<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class SmsService
{
    private string $baseUrl;
    private string $token;
    private string $senderId;
    private string $paymentUrl;

    /**
     * HTTP timeout in seconds.
     */
    private int $timeout = 30;

    /**
     * Connection timeout in seconds.
     */
    private int $connectTimeout = 10;

    /**
     * Retry count.
     */
    private int $retryTimes = 3;

    /**
     * Retry delay in milliseconds.
     */
    private int $retryDelay = 500;

    public function __construct()
    {
        $this->baseUrl = rtrim(
            config('services.dagu_sms.base_url', ''),
            '/'
        );

        $this->token = config(
            'services.dagu_sms.token',
            ''
        );

        $this->senderId = config(
            'services.dagu_sms.sender_id',
            '9141'
        );

        $this->paymentUrl = rtrim(
            config('app.payment_url', ''),
            '/'
        );

        Log::debug('Initializing SMS service.', [
            'base_url' => $this->baseUrl,
            'sender_id' => $this->senderId,
            'payment_url' => $this->paymentUrl,
            'token_configured' => !empty($this->token),
            'timeout_seconds' => $this->timeout,
            'connect_timeout_seconds' => $this->connectTimeout,
            'retry_times' => $this->retryTimes,
            'retry_delay_ms' => $this->retryDelay,
        ]);

        if (!$this->baseUrl || !$this->token) {
            Log::critical('Dagu SMS configuration is missing.', [
                'base_url_configured' => !empty($this->baseUrl),
                'token_configured' => !empty($this->token),
                'sender_id_configured' => !empty($this->senderId),
            ]);

            throw new \RuntimeException(
                'Dagu SMS configuration missing'
            );
        }

        Log::debug('SMS service initialized successfully.');
    }

    /**
     * Send SMS to a single phone number.
     */
    public function sendByPhone(
        string $phone,
        string $message,
        bool $flash = false
    ): array {
        $requestId = $this->requestId();

        Log::info('SMS sendByPhone request received.', [
            'request_id' => $requestId,
            'phone_raw' => $phone,
            'message_length' => mb_strlen($message),
            'flash' => $flash,
        ]);

        return $this->sendSms(
            $phone,
            $message,
            $flash,
            $requestId
        );
    }

    /**
     * Send revenue payment link SMS.
     */
    public function sendPaymentLink(
        string $phone,
        string $taxpayerName,
        string $paymentToken,
        bool $flash = false
    ): array {
        $requestId = $this->requestId();

        $paymentLink = $this->paymentUrl
            . '/p/'
            . $paymentToken;

        $message =
            "Dear {$taxpayerName}, "
            . "your revenue payment is pending. "
            . "Pay securely here: {$paymentLink}";

        Log::info('Preparing payment link SMS.', [
            'request_id' => $requestId,
            'phone_raw' => $phone,
            'taxpayer_name' => $taxpayerName,
            'payment_url_configured' => !empty($this->paymentUrl),
            'payment_link_length' => strlen($paymentLink),
            'message_length' => mb_strlen($message),
            'flash' => $flash,
        ]);

        return $this->sendSms(
            $phone,
            $message,
            $flash,
            $requestId
        );
    }

    /**
     * Send OTP SMS.
     *
     * The OTP itself is intentionally NOT logged.
     */
    public function sendOtp(string $phone): array
    {
        $requestId = $this->requestId();

        $otp = random_int(
            100000,
            999999
        );

        $message =
            "Your verification code is {$otp}.";

        Log::info('Preparing OTP SMS.', [
            'request_id' => $requestId,
            'phone_raw' => $phone,
            'message_length' => mb_strlen($message),
            'otp_generated' => true,
        ]);

        $response = $this->sendSms(
            $phone,
            $message,
            false,
            $requestId
        );

        return [
            'otp' => $otp,
            'response' => $response,
        ];
    }

    /**
     * Send bulk SMS.
     */
    public function sendBulk(
        array $phones,
        string $message,
        bool $flash = false
    ): array {
        $requestId = $this->requestId();

        $startedAt = microtime(true);

        Log::info('Bulk SMS request started.', [
            'request_id' => $requestId,
            'phone_count_received' => count($phones),
            'message_length' => mb_strlen($message),
            'flash' => $flash,
        ]);

        try {
            /*
             * Normalize phones individually so we can log
             * the transformation without exposing message contents.
             */
            $normalizedPhones = collect($phones)
                ->map(function ($phone) use ($requestId) {
                    $normalized = $this->normalizePhone($phone);

                    Log::debug('Bulk SMS phone normalized.', [
                        'request_id' => $requestId,
                        'phone_raw' => $phone,
                        'phone_normalized' => $normalized,
                    ]);

                    return $normalized;
                })
                ->values()
                ->toArray();

            Log::debug('Bulk SMS phones normalized.', [
                'request_id' => $requestId,
                'phone_count' => count($normalizedPhones),
                'endpoint' => $this->baseUrl . '/to-phone-list',
            ]);

            $response = $this->performRequest(
                method: 'POST',
                url: $this->baseUrl . '/to-phone-list',
                payload: [
                    'senderID' => $this->senderId,
                    'message' => $message,
                    'phones' => $normalizedPhones,
                    'flash' => $flash,
                ],
                requestId: $requestId,
                operation: 'bulk_sms'
            );

            $result = $this->formatResponse(
                $response,
                $requestId,
                $startedAt
            );

            Log::info('Bulk SMS request completed.', [
                'request_id' => $requestId,
                'status' => $result['status'],
                'http_status' => $result['http_status'],
                'duration_ms' => $result['duration_ms'],
                'phone_count' => count($normalizedPhones),
            ]);

            return $result;

        } catch (Throwable $e) {
            $durationMs = $this->durationMs($startedAt);

            Log::error('Bulk SMS failed.', [
                'request_id' => $requestId,
                'duration_ms' => $durationMs,
                'phone_count' => count($phones),
                'exception_class' => get_class($e),
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return [
                'status' => 'failed',
                'error' => $e->getMessage(),
                'request_id' => $requestId,
                'duration_ms' => $durationMs,
            ];
        }
    }

    /**
     * Internal SMS sender.
     */
    private function sendSms(
        string $phone,
        string $message,
        bool $flash = false,
        ?string $requestId = null
    ): array {
        $requestId ??= $this->requestId();

        $startedAt = microtime(true);

        Log::info('SMS request started.', [
            'request_id' => $requestId,
            'phone_raw' => $phone,
            'message_length' => mb_strlen($message),
            'flash' => $flash,
            'sender_id' => $this->senderId,
        ]);

        try {
            $normalizedPhone = $this->normalizePhone($phone);

            Log::debug('SMS phone normalized.', [
                'request_id' => $requestId,
                'phone_raw' => $phone,
                'phone_normalized' => $normalizedPhone,
            ]);

            $endpoint = $this->baseUrl . '/by-phone';

            Log::debug('Preparing SMS HTTP request.', [
                'request_id' => $requestId,
                'method' => 'POST',
                'endpoint' => $endpoint,
                'sender_id' => $this->senderId,
                'phone' => $normalizedPhone,
                'message_length' => mb_strlen($message),
                'flash' => $flash,
                'timeout_seconds' => $this->timeout,
                'connect_timeout_seconds' => $this->connectTimeout,
                'retry_times' => $this->retryTimes,
                'retry_delay_ms' => $this->retryDelay,
            ]);

            $response = $this->performRequest(
                method: 'POST',
                url: $endpoint,
                payload: [
                    'senderID' => $this->senderId,
                    'message' => $message,
                    'phone' => $normalizedPhone,
                    'flash' => $flash,
                ],
                requestId: $requestId,
                operation: 'single_sms'
            );

            $result = $this->formatResponse(
                $response,
                $requestId,
                $startedAt
            );

            if ($response->successful()) {
                Log::info('SMS sent successfully.', [
                    'request_id' => $requestId,
                    'phone' => $normalizedPhone,
                    'http_status' => $response->status(),
                    'duration_ms' => $result['duration_ms'],
                ]);
            } else {
                Log::warning('SMS gateway returned unsuccessful response.', [
                    'request_id' => $requestId,
                    'phone' => $normalizedPhone,
                    'http_status' => $response->status(),
                    'duration_ms' => $result['duration_ms'],
                    'response_body' => $response->body(),
                    'response_json' => $response->json(),
                ]);
            }

            return $result;

        } catch (Throwable $e) {
            $durationMs = $this->durationMs($startedAt);

            Log::error('SMS sending failed.', [
                'request_id' => $requestId,
                'phone' => $phone,
                'duration_ms' => $durationMs,
                'exception_class' => get_class($e),
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return [
                'status' => 'failed',
                'error' => $e->getMessage(),
                'request_id' => $requestId,
                'duration_ms' => $durationMs,
            ];
        }
    }

    /**
     * Execute SMS HTTP request with explicit logging.
     */
    private function performRequest(
        string $method,
        string $url,
        array $payload,
        string $requestId,
        string $operation
    ): Response {
        Log::debug('SMS HTTP request dispatching.', [
            'request_id' => $requestId,
            'operation' => $operation,
            'method' => $method,
            'url' => $url,
            'payload_keys' => array_keys($payload),
        ]);

        /*
         * Important:
         *
         * Do NOT log:
         * - Authorization token
         * - Full OTP message
         * - Full payment token
         * - Sensitive citizen information
         */
        return Http::timeout($this->timeout)
            ->connectTimeout($this->connectTimeout)
            ->retry(
                $this->retryTimes,
                $this->retryDelay,
                function (Throwable $exception) use (
                    $requestId,
                    $operation
                ) {
                    Log::warning(
                        'SMS HTTP retry triggered.',
                        [
                            'request_id' => $requestId,
                            'operation' => $operation,
                            'exception_class' => get_class($exception),
                            'error' => $exception->getMessage(),
                        ]
                    );

                    return true;
                }
            )
            ->withToken($this->token)
            ->acceptJson()
            ->post(
                $url,
                $payload
            );
    }

    /**
     * Standardize SMS gateway response.
     */
    private function formatResponse(
        Response $response,
        string $requestId,
        float $startedAt
    ): array {
        $durationMs = $this->durationMs($startedAt);

        $body = $response->body();
        $json = null;

        try {
            $json = $response->json();
        } catch (Throwable $e) {
            Log::debug('SMS response is not valid JSON.', [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
            ]);
        }

        Log::debug('SMS gateway response received.', [
            'request_id' => $requestId,
            'http_status' => $response->status(),
            'successful' => $response->successful(),
            'duration_ms' => $durationMs,
            'response_body' => $body,
            'response_json' => $json,
        ]);

        return [
            'status' => $response->successful()
                ? 'success'
                : 'failed',

            'http_status' => $response->status(),

            'body' => $body,

            'json' => $json,

            'request_id' => $requestId,

            'duration_ms' => $durationMs,
        ];
    }

    /**
     * Normalize Ethiopian phone numbers.
     *
     * 0912345678
     * => +251912345678
     *
     * 251912345678
     * => +251912345678
     */
    private function normalizePhone(string $phone): string
    {
        $original = $phone;

        $phone = preg_replace(
            '/[\s\-\(\)]/',
            '',
            trim($phone)
        );

        if (str_starts_with($phone, '+251')) {
            $normalized = $phone;
        } elseif (str_starts_with($phone, '251')) {
            $normalized = '+' . $phone;
        } elseif (str_starts_with($phone, '0')) {
            $normalized = '+251' . substr($phone, 1);
        } else {
            $normalized = $phone;
        }

        Log::debug('Phone normalization completed.', [
            'phone_raw' => $original,
            'phone_cleaned' => $phone,
            'phone_normalized' => $normalized,
        ]);

        return $normalized;
    }

    /**
     * Generate request ID.
     */
    private function requestId(): string
    {
        return (string) Str::uuid();
    }

    /**
     * Calculate elapsed milliseconds.
     */
    private function durationMs(float $startedAt): int
    {
        return (int) round(
            (microtime(true) - $startedAt) * 1000
        );
    }
}