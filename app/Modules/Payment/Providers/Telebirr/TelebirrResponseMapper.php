<?php

declare(strict_types=1);

namespace App\Modules\Payment\Providers\Telebirr;

use App\Modules\Payment\Providers\Telebirr\DTOs\TelebirrPreOrderResult;
use App\Modules\Payment\Providers\Telebirr\DTOs\TelebirrQueryResult;
use App\Modules\Payment\Providers\Telebirr\DTOs\TelebirrToken;
use DateTimeImmutable;
use InvalidArgumentException;

final class TelebirrResponseMapper
{
    /**
     * Map the main Fabric Token response.
     *
     * Expected response commonly contains:
     *
     * {
     *     "token": "...",
     *     "expirationDate": "20261122230000"
     * }
     */
    public function mapTokenResponse(array $response): TelebirrToken
    {
        $this->assertSuccessfulResponse($response);

        $accessToken = $this->extractValue(
            $response,
            [
                'token',
                'access_token',
                'accessToken',
            ],
        );

        if ($accessToken === null) {
            throw new InvalidArgumentException(
                'Telebirr response does not contain a valid Fabric Token.'
            );
        }

        // Normalize a token that may include a Bearer prefix.
        // The C2B preorder client sends the token without this prefix.
        $accessToken = preg_replace(
            '/^Bearer\s+/i',
            '',
            trim($accessToken),
        );

        if (
            ! is_string($accessToken)
            || trim($accessToken) === ''
        ) {
            throw new InvalidArgumentException(
                'Telebirr returned an empty authentication token.'
            );
        }

        return new TelebirrToken(
            accessToken: trim($accessToken),
            expiresAt: $this->mapExpiration($response),
        );
    }

    /**
     * Map the C2B preorder response.
     *
     * Expected response commonly contains:
     *
     * {
     *     "biz_content": {
     *         "prepay_id": "..."
     *     }
     * }
     */
    public function mapPreOrderResponse(
        array $response
    ): TelebirrPreOrderResult {
        $this->assertSuccessfulResponse($response);

        $prepayId = $this->extractValue(
            $response,
            [
                'prepay_id',
                'prepayId',
            ],
        );

        if ($prepayId === null) {
            throw new InvalidArgumentException(
                'Telebirr preorder response does not contain a valid prepay ID.'
            );
        }

        $tradeStatus = $this->extractValue(
            $response,
            [
                'trade_status',
                'tradeStatus',
            ],
        );

        return new TelebirrPreOrderResult(
            prepayId: $prepayId,
            rawTradeStatus: $tradeStatus,
        );
    }

    /**
     * Map a transaction query response.
     *
     * Expected response commonly contains:
     *
     * {
     *     "biz_content": {
     *         "trade_status": "SUCCESS",
     *         "transaction_id": "...",
     *         "prepay_id": "..."
     *     }
     * }
     */
    public function mapQueryResponse(
        array $response
    ): TelebirrQueryResult {
        $this->assertSuccessfulResponse($response);

        $tradeStatus = $this->extractValue(
            $response,
            [
                'trade_status',
                'tradeStatus',
            ],
        );

        if ($tradeStatus === null) {
            throw new InvalidArgumentException(
                'Telebirr query response does not contain a transaction status.'
            );
        }

        $transactionId = $this->extractValue(
            $response,
            [
                'transaction_id',
                'trans_id',
                'transactionId',
                'transId',
            ],
        );

        $prepayId = $this->extractValue(
            $response,
            [
                'prepay_id',
                'prepayId',
            ],
        );

        return new TelebirrQueryResult(
            tradeStatus: strtoupper(trim($tradeStatus)),
            transactionId: $transactionId,
            prepayId: $prepayId,
        );
    }

    /**
     * Ensure Telebirr did not return an API or business error.
     *
     * Checks the root response and common nested containers.
     */
    private function assertSuccessfulResponse(
        array $response
    ): void {
        $containers = $this->getResponseContainers($response);

        foreach ($containers as $container) {
            $errorCode = $container['errorCode']
                ?? $container['error_code']
                ?? null;

            $errorMessage = $container['errorMsg']
                ?? $container['error_msg']
                ?? $container['message']
                ?? $container['msg']
                ?? null;

            if (
                $errorCode !== null
                && trim((string) $errorCode) !== ''
                && (string) $errorCode !== '0'
            ) {
                throw new InvalidArgumentException(
                    'Telebirr API error: '
                    . $this->safeErrorText($errorCode)
                    . (
                        is_string($errorMessage)
                        && trim($errorMessage) !== ''
                            ? ' - ' . $this->safeErrorText($errorMessage)
                            : ''
                    )
                );
            }

            // Some responses expose an explicit failure status.
            $success = $container['success'] ?? null;

            if ($success === false || $success === 0 || $success === 'false') {
                throw new InvalidArgumentException(
                    'Telebirr reported an unsuccessful API operation.'
                );
            }
        }
    }

    /**
     * Return root and nested response containers.
     *
     * Supports:
     * - Root response
     * - biz_content
     * - data
     * - data.biz_content
     */
    private function getResponseContainers(
        array $response
    ): array {
        $containers = [$response];

        foreach (['biz_content', 'data'] as $key) {
            if (is_array($response[$key] ?? null)) {
                $containers[] = $response[$key];

                if (is_array($response[$key]['biz_content'] ?? null)) {
                    $containers[] = $response[$key]['biz_content'];
                }
            }
        }

        return $containers;
    }

    /**
     * Extract a non-empty scalar value from the response.
     *
     * Nested biz_content and data values take precedence over root values.
     */
    private function extractValue(
        array $response,
        array $fields,
    ): ?string {
        foreach ($this->getResponseContainers($response) as $source) {
            foreach ($fields as $field) {
                $value = $source[$field] ?? null;

                if (is_string($value)) {
                    $value = trim($value);

                    if ($value !== '') {
                        return $value;
                    }
                }

                if (is_int($value) || is_float($value)) {
                    return (string) $value;
                }
            }
        }

        return null;
    }

    /**
     * Map token expiration.
     *
     * Supports:
     * - expirationDate: YYYYMMDDHHMMSS
     * - expiration_date: YYYYMMDDHHMMSS
     * - expires_in: seconds
     */
    private function mapExpiration(
        array $response
    ): ?DateTimeImmutable {
        $expirationDate = $this->extractValue(
            $response,
            [
                'expirationDate',
                'expiration_date',
            ],
        );

        if (
            $expirationDate !== null
            && preg_match('/^\d{14}$/', $expirationDate)
        ) {
            $date = DateTimeImmutable::createFromFormat(
                '!YmdHis',
                $expirationDate,
            );

            $errors = DateTimeImmutable::getLastErrors();

            if (
                $date !== false
                && (
                    $errors === false
                    || (
                        $errors['warning_count'] === 0
                        && $errors['error_count'] === 0
                    )
                )
                && $date->format('YmdHis') === $expirationDate
            ) {
                return $date;
            }
        }

        $expiresIn = $this->extractValue(
            $response,
            ['expires_in'],
        );

        if (
            $expiresIn !== null
            && ctype_digit($expiresIn)
            && (int) $expiresIn > 0
        ) {
            return (new DateTimeImmutable())->modify(
                '+' . (int) $expiresIn . ' seconds'
            );
        }

        return null;
    }

    /**
     * Sanitize provider error text.
     */
    private function safeErrorText(
        mixed $value
    ): string {
        if (! is_string($value) && ! is_numeric($value)) {
            return 'Unknown provider error';
        }

        $value = trim((string) $value);

        $value = preg_replace(
            '/(access_token|refresh_token|token|appsecret|app_secret|secret|password|authorization|signature|private_key)\s*[:=]\s*[^\s,;]+/i',
            '$1=[REDACTED]',
            $value,
        ) ?? '[REDACTED]';

        return mb_substr($value, 0, 300);
    }
}
