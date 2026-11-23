<?php

declare(strict_types=1);

namespace App\Helpers;

use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;
use RuntimeException;
use Throwable;

final class TelebirrHelper
{
    private const SIGN_TYPE = 'SHA256WithRSA';

    private const EXCLUDED_SIGN_FIELDS = [
        'sign',
        'sign_type',
        'header',
        'refund_info',
        'openType',
        'raw_request',
    ];

    /**
     * Load the merchant private key from secure storage.
     */
    public static function loadPrivateKey(): string
    {
        $path = storage_path('app/secure/telebirr.pem');

        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException(
                'Telebirr merchant private key file is missing or unreadable.'
            );
        }

        $key = file_get_contents($path);

        if ($key === false) {
            throw new RuntimeException(
                'Unable to read the Telebirr merchant private key.'
            );
        }

        return self::normalizePrivateKey($key);
    }

    /**
     * Normalize a PEM private key.
     */
    public static function normalizePrivateKey(string $raw): string
    {
        $key = trim($raw, " \t\n\r\0\x0B\"'");

        $beginPosition = strpos($key, '-----BEGIN');

        if ($beginPosition !== false) {
            $key = substr($key, $beginPosition);
        }

        $key = str_replace(
            ['\\r\\n', '\\n', '\\r'],
            PHP_EOL,
            $key,
        );

        if (
            ! str_contains($key, '-----BEGIN')
            || ! str_contains($key, '-----END')
        ) {
            throw new RuntimeException(
                'Telebirr private key must contain valid PEM headers and footers.'
            );
        }

        $lines = preg_split('/\r\n|\r|\n/', $key);

        if ($lines === false) {
            throw new RuntimeException(
                'Unable to normalize the Telebirr private key.'
            );
        }

        $lines = array_filter(
            array_map('trim', $lines),
            static fn (string $line): bool => $line !== '',
        );

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /**
     * Sign a Telebirr request using SHA256WithRSA.
     *
     * SHA256WithRSA is implemented here using RSA PKCS#1 v1.5
     * with SHA-256, not RSA-PSS.
     */
    public static function sign(
        array $params,
        string $privateKeyPem,
    ): string {
        unset($params['sign']);

        $rawRequest = self::buildRawRequest($params);

        if ($rawRequest === '') {
            throw new RuntimeException(
                'Cannot sign an empty Telebirr request.'
            );
        }

        try {
            $privateKey = PublicKeyLoader::loadPrivateKey(
                self::normalizePrivateKey($privateKeyPem)
            )
                ->withHash('sha256')
                ->withPadding(RSA::SIGNATURE_PKCS1);

            $signature = $privateKey->sign($rawRequest);

            return base64_encode($signature);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Unable to sign the Telebirr request.',
                previous: $exception,
            );
        }
    }

    /**
     * Build the canonical string used for signing.
     *
     * Follows the supplied Telebirr PHP utility:
     *
     * 1. Sort top-level request keys.
     * 2. Exclude non-signature fields.
     * 3. Flatten biz_content.
     * 4. Sort the resulting key=value strings.
     * 5. Join using ampersands.
     */
    public static function buildRawRequest(array $params): string
    {
        ksort($params, SORT_STRING);

        $parts = [];

        foreach ($params as $key => $value) {
            if (
                in_array(
                    (string) $key,
                    self::EXCLUDED_SIGN_FIELDS,
                    true,
                )
                || $value === null
                || $value === ''
            ) {
                continue;
            }

            if ($key === 'biz_content' && is_array($value)) {
                foreach ($value as $bizKey => $bizValue) {
                    if (
                        $bizValue === null
                        || $bizValue === ''
                        || ! is_scalar($bizValue)
                    ) {
                        continue;
                    }

                    $parts[] = (string) $bizKey
                        . '='
                        . (string) $bizValue;
                }

                continue;
            }

            if (is_scalar($value)) {
                $parts[] = (string) $key
                    . '='
                    . (string) $value;
            }
        }

        sort($parts, SORT_STRING);

        return implode('&', $parts);
    }

    /**
     * Generate a signed browser-based H5 Checkout request.
     *
     * The returned query string contains:
     *
     * appid
     * merch_code
     * nonce_str
     * prepay_id
     * timestamp
     * sign_type
     * sign
     *
     * The caller appends:
     *
     * version=1.0
     * trade_type=Checkout
     */
    public static function createRawRequest(
        string $prepayId,
        string $merchantAppId,
        string $merchantCode,
    ): string {
        if (
            trim($prepayId) === ''
            || trim($merchantAppId) === ''
            || trim($merchantCode) === ''
        ) {
            throw new RuntimeException(
                'Telebirr Checkout parameters are incomplete.'
            );
        }

        $privateKeyPem = self::loadPrivateKey();

        $fields = [
            'appid' => $merchantAppId,
            'merch_code' => $merchantCode,
            'nonce_str' => self::createNonceStr(),
            'prepay_id' => $prepayId,
            'timestamp' => self::createTimeStamp(),
            'sign_type' => self::SIGN_TYPE,
        ];

        $fields['sign'] = self::sign(
            $fields,
            $privateKeyPem,
        );

        return http_build_query(
            $fields,
            '',
            '&',
            PHP_QUERY_RFC3986,
        );
    }

    /**
     * Generate a merchant order ID.
     */
    public static function createMerchantOrderId(): string
    {
        return date('YmdHis') . random_int(100000, 999999);
    }

    /**
     * Generate a Unix timestamp.
     */
    public static function createTimeStamp(): string
    {
        return (string) time();
    }

    /**
     * Generate a cryptographically secure nonce.
     */
    public static function createNonceStr(int $length = 32): string
    {
        if ($length < 1 || $length > 128) {
            throw new RuntimeException(
                'Telebirr nonce length must be between 1 and 128.'
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

    /**
     * Format callback information for preorder.
     */
    public static function formatCallbackInfo(array $meta): string
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

            if ($safeKey === '') {
                continue;
            }

            $parts[] = $safeKey . '-' . $safeValue;
        }

        return implode('_', $parts);
    }

    /**
     * Parse callback information.
     */
    public static function parseCallbackInfo(
        string $callbackInfo,
    ): array {
        $result = [];

        if (trim($callbackInfo) === '') {
            return $result;
        }

        foreach (explode('_', $callbackInfo) as $pair) {
            if (! str_contains($pair, '-')) {
                continue;
            }

            [$key, $value] = explode('-', $pair, 2);

            if ($key !== '') {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
