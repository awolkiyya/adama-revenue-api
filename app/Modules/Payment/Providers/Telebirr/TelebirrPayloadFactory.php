<?php

declare(strict_types=1);

namespace App\Modules\Payment\Providers\Telebirr;

use App\Helpers\TelebirrHelper;
use InvalidArgumentException;

final class TelebirrPayloadFactory
{
    private const SIGN_TYPE = 'SHA256WithRSA';

    private const API_VERSION = '1.0';

    private const CURRENCY = 'ETB';

    private const BUSINESS_TYPE = 'BuyGoods';

    private const TRADE_TYPE = 'Checkout';

    private const MAX_CALLBACK_INFO_LENGTH = 64;

    /**
     * Build the main Fabric Token request body.
     *
     * POST /payment/v1/token
     */
    public function createMainTokenPayload(string $appSecret): array
    {
        $this->assertNotEmpty($appSecret, 'App secret');

        return [
            'appSecret' => $appSecret,
        ];
    }

    /**
     * Build a C2B merchant preorder request.
     *
     * POST /payment/v1/merchant/preOrder
     *
     * This is the browser-based H5 Checkout flow.
     * It does not use the InApp H5 token flow.
     */
    public function createPreOrderPayload(
        string $title,
        string $amount,
        string $merchantAppId,
        string $merchantCode,
        string $merchantOrderId,
        string $notifyUrl,
        string $returnUrl,
        string $privateKeyPem,
        array $meta = [],
    ): array {
        $title = $this->validateTitle($title);

        $this->assertNotEmpty($merchantAppId, 'Merchant app ID');
        $this->assertNotEmpty($merchantCode, 'Merchant code');
        $this->assertNotEmpty($merchantOrderId, 'Merchant order ID');
        $this->assertNotEmpty($notifyUrl, 'Notify URL');
        $this->assertNotEmpty($returnUrl, 'Return URL');
        $this->assertNotEmpty($privateKeyPem, 'Private key');

        $this->assertValidAmount($amount);

        $redirectUrl = $this->appendQueryParameter(
            $returnUrl,
            'order_id',
            $merchantOrderId,
        );

        $bizContent = [
            'notify_url' => $notifyUrl,
            'appid' => $merchantAppId,
            'merch_code' => $merchantCode,
            'merch_order_id' => $merchantOrderId,
            'trade_type' => self::TRADE_TYPE,
            'title' => $title,
            'total_amount' => $amount,
            'trans_currency' => self::CURRENCY,
            'timeout_express' => '120m',
            'business_type' => self::BUSINESS_TYPE,
            'payee_identifier' => $merchantCode,
            'payee_identifier_type' => '04',
            'payee_type' => '5000',
            'redirect_url' => $redirectUrl,
            'callback_info' => $this->formatCallbackInfo($meta),
        ];

        // Validate callback_info before signing.
        if (
            strlen($bizContent['callback_info'])
            > self::MAX_CALLBACK_INFO_LENGTH
        ) {
            throw new InvalidArgumentException(
                'Telebirr callback_info exceeds the maximum length of 64 characters.'
            );
        }

        $payload = [
            'nonce_str' => $this->createNonceStr(),
            'method' => 'payment.preorder',
            'timestamp' => $this->createTimestamp(),
            'version' => self::API_VERSION,
            'biz_content' => $bizContent,
            'sign_type' => self::SIGN_TYPE,
        ];

        return $this->signPayload(
            $payload,
            $privateKeyPem,
        );
    }

    /**
     * Build a transaction query request.
     *
     * POST /payment/v1/merchant/queryOrder
     *
     * Confirm the query request fields and authentication
     * requirements against your merchant's QueryOrder documentation.
     */
    public function createQueryOrderPayload(
        string $merchantAppId,
        string $merchantCode,
        string $merchantOrderId,
        string $privateKeyPem,
    ): array {
        $this->assertNotEmpty($merchantAppId, 'Merchant app ID');
        $this->assertNotEmpty($merchantCode, 'Merchant code');
        $this->assertNotEmpty($merchantOrderId, 'Merchant order ID');
        $this->assertNotEmpty($privateKeyPem, 'Private key');

        $payload = [
            'nonce_str' => $this->createNonceStr(),
            'method' => 'payment.queryorder',
            'timestamp' => $this->createTimestamp(),
            'version' => self::API_VERSION,
            'biz_content' => [
                'appid' => $merchantAppId,
                'merch_code' => $merchantCode,
                'merch_order_id' => $merchantOrderId,
            ],
            'sign_type' => self::SIGN_TYPE,
        ];

        return $this->signPayload(
            $payload,
            $privateKeyPem,
        );
    }

    /**
     * Build signed parameters for the browser-based H5 Checkout URL.
     *
     * The caller appends:
     * version=1.0
     * trade_type=Checkout
     *
     * These parameters are appended after signing,
     * following the supplied C2B CreateOrderService example.
     */
    public function createRawRequest(
        string $prepayId,
        string $merchantAppId,
        string $merchantCode,
        string $privateKeyPem,
    ): string {
        $this->assertNotEmpty($prepayId, 'Prepay ID');
        $this->assertNotEmpty($merchantAppId, 'Merchant app ID');
        $this->assertNotEmpty($merchantCode, 'Merchant code');
        $this->assertNotEmpty($privateKeyPem, 'Private key');

        $fields = [
            'appid' => $merchantAppId,
            'merch_code' => $merchantCode,
            'nonce_str' => $this->createNonceStr(),
            'prepay_id' => $prepayId,
            'timestamp' => $this->createTimestamp(),
            'sign_type' => self::SIGN_TYPE,
        ];

        $signedFields = $this->signPayload(
            $fields,
            $privateKeyPem,
        );

        return http_build_query(
            $signedFields,
            '',
            '&',
            PHP_QUERY_RFC3986,
        );
    }

    /**
     * Sign a Telebirr request.
     *
     * SHA256WithRSA conventionally means RSA PKCS#1 v1.5
     * with SHA-256, not RSA-PSS.
     *
     * Signing and canonicalization are delegated to TelebirrHelper.
     */
    private function signPayload(
        array $payload,
        string $privateKeyPem,
    ): array {
        $this->assertNotEmpty($privateKeyPem, 'Private key');

        unset($payload['sign']);

        $payload['sign'] = TelebirrHelper::sign(
            $payload,
            $privateKeyPem,
        );

        return $payload;
    }

    /**
     * Validate and normalize the Telebirr preorder title.
     *
     * Telebirr rejects these characters in biz_content.title:
     *
     * ~ ` ! # $ % ^ * ( ) - + = | / < > ? ; : " [ ] { } \ &
     *
     * Replace prohibited characters with spaces.
     */
    private function validateTitle(string $title): string
    {
        $title = trim($title);

        if ($title === '') {
            throw new InvalidArgumentException(
                'Telebirr payment title cannot be empty.'
            );
        }

        $sanitizedTitle = preg_replace(
            '/[~`!#$%^*()\-+=|\/<>?;:"\[\]{}\\\\&]/u',
            ' ',
            $title,
        );

        if ($sanitizedTitle === null) {
            throw new InvalidArgumentException(
                'Unable to validate the Telebirr payment title.'
            );
        }

        $sanitizedTitle = preg_replace(
            '/\s+/u',
            ' ',
            $sanitizedTitle,
        );

        if ($sanitizedTitle === null) {
            throw new InvalidArgumentException(
                'Unable to normalize the Telebirr payment title.'
            );
        }

        $sanitizedTitle = trim($sanitizedTitle);

        if ($sanitizedTitle === '') {
            throw new InvalidArgumentException(
                'Telebirr payment title contains no supported characters.'
            );
        }

        return $sanitizedTitle;
    }

    /**
     * Format callback metadata.
     *
     * Maximum length: 64 characters.
     *
     * Entries that would exceed the limit are skipped entirely,
     * so identifiers are never partially truncated.
     */
    private function formatCallbackInfo(array $meta): string
    {
        $parts = [];

        foreach ($meta as $key => $value) {
            if (! is_scalar($value) && $value !== null) {
                continue;
            }

            $safeKey = preg_replace(
                '/[^\w-]/',
                '',
                (string) $key,
            );

            $safeValue = preg_replace(
                '/[^\w-]/',
                '',
                (string) ($value ?? ''),
            );

            if ($safeKey === '' || $safeKey === null) {
                continue;
            }

            $part = "{$safeKey}-{$safeValue}";

            $candidate = implode(
                '_',
                [...$parts, $part],
            );

            if (
                strlen($candidate)
                > self::MAX_CALLBACK_INFO_LENGTH
            ) {
                continue;
            }

            $parts[] = $part;
        }

        return implode('_', $parts);
    }

    /**
     * Append a query parameter without removing existing parameters.
     */
    private function appendQueryParameter(
        string $url,
        string $key,
        string $value,
    ): string {
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . http_build_query(
            [$key => $value],
            '',
            '&',
            PHP_QUERY_RFC3986,
        );
    }

    /**
     * Validate a positive decimal amount without converting to float.
     */
    private function assertValidAmount(string $amount): void
    {
        if (
            ! preg_match('/^\d+(?:\.\d{1,2})?$/', $amount)
            || ! preg_match('/[1-9]/', $amount)
        ) {
            throw new InvalidArgumentException(
                'Amount must be a positive decimal with up to two decimal places.'
            );
        }
    }

    /**
     * Validate a required string.
     */
    private function assertNotEmpty(
        string $value,
        string $field,
    ): void {
        if (trim($value) === '') {
            throw new InvalidArgumentException(
                "{$field} cannot be empty."
            );
        }
    }

    /**
     * Generate a unique merchant order ID.
     *
     * Persist this ID before sending the preorder request.
     */
    public function createMerchantOrderId(): string
    {
        return now()->format('YmdHis')
            . random_int(100000, 999999);
    }

    /**
     * Generate the current Unix timestamp.
     */
    public function createTimestamp(): string
    {
        return (string) time();
    }

    /**
     * Generate a cryptographically secure nonce.
     */
    public function createNonceStr(int $length = 32): string
    {
        if ($length < 1) {
            throw new InvalidArgumentException(
                'Nonce length must be greater than zero.'
            );
        }

        $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';

        $maxIndex = strlen($characters) - 1;

        $nonce = '';

        for ($i = 0; $i < $length; $i++) {
            $nonce .= $characters[random_int(0, $maxIndex)];
        }

        return $nonce;
    }
}
