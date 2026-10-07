<?php

namespace App\Modules\Payment\Providers\Chapa;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ChapaClient
{
    private string $baseUrl;
    private string $secretKey;
    private int $timeout;
    private int $connectTimeout;

    public function __construct()
    {
        $this->baseUrl = rtrim(
            config('services.chapa.base_url', 'https://api.chapa.co'),
            '/'
        );

        $this->secretKey = (string) config('services.chapa.secret_key');

        $this->timeout = (int) config('services.chapa.timeout', 30);
        $this->connectTimeout = (int) config('services.chapa.connect_timeout', 10);

        if ($this->secretKey === '') {
            throw new RuntimeException('Chapa secret key is not configured.');
        }
    }

    /**
     * Initialize a Chapa checkout transaction.
     */
    public function initialize(array $payload): Response
    {
        return $this->client()->post('/transaction/initialize', $payload);
    }

    /**
     * Verify a Chapa transaction using the original tx_ref.
     */
    public function verify(string $transactionReference): Response
    {
        return $this->client()->get(
            '/transaction/verify/' . rawurlencode($transactionReference)
        );
    }

    /**
     * Build the configured Chapa HTTP client.
     */
    private function client()
    {
        return Http::baseUrl($this->apiBaseUrl())
            ->withToken($this->secretKey)
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout)
            ->retry(
                (int) config('services.chapa.retry_times', 3),
                (int) config('services.chapa.retry_sleep', 500),
                throw: false
            );
    }

    /**
     * Chapa API base URL.
     *
     * Example:
     * https://api.chapa.co/v1
     */
    private function apiBaseUrl(): string
    {
        return rtrim($this->baseUrl, '/') . '/v1';
    }
}
