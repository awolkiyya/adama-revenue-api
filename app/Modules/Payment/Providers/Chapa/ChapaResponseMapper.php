<?php

declare(strict_types=1);

namespace App\Modules\Payment\Providers\Chapa;

use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Modules\Payment\DTOs\InitializePaymentData;
use App\Modules\Payment\DTOs\PaymentResult;
use App\Modules\Payment\DTOs\PaymentVerificationResult;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class ChapaResponseMapper
{
    private const MAX_ERROR_MESSAGE_LENGTH = 1000;

    private const EXPECTED_CURRENCY = 'ETB';

    /**
     * Map Chapa initialization response to the application's
     * generic PaymentResult.
     */
    public function mapInitializationResponse(
        array $response,
        InitializePaymentData $data
    ): PaymentResult {
        $status = $this->normalizeStatus(
            $response['status'] ?? null
        );

        if ($status !== 'success') {
            throw new RuntimeException(
                $this->extractErrorMessage(
                    $response,
                    'Chapa payment initialization failed.'
                )
            );
        }

        $providerData = $response['data'] ?? null;

        if (!is_array($providerData)) {
            throw new RuntimeException(
                'Chapa initialization succeeded but returned invalid response data.'
            );
        }

        $checkoutUrl = $this->extractString(
            $providerData['checkout_url'] ?? null
        );

        if ($checkoutUrl === null) {
            throw new RuntimeException(
                'Chapa initialization succeeded but no checkout URL was returned.'
            );
        }

        if (!$this->isValidHttpUrl($checkoutUrl)) {
            throw new RuntimeException(
                'Chapa returned an invalid checkout URL.'
            );
        }

        /*
         * This is our municipal transaction reference.
         *
         * It is sent to Chapa as tx_ref.
         */
        $transactionReference = trim(
            $data->paymentReference
        );

        if ($transactionReference === '') {
            throw new RuntimeException(
                'Payment initialization data does not contain a transaction reference.'
            );
        }

        /*
         * Initialization normally does not provide the final
         * provider-side transaction ID.
         */
        $providerTransactionId = $this->extractString(
            $providerData['transaction_id']
                ?? $providerData['transaction_reference']
                ?? $providerData['reference']
                ?? null
        );

        return PaymentResult::success(
            provider: PaymentProvider::CHAPA,

            paymentReference:
                $data->paymentReference,

            amount:
                (float) $data->amount,

            currency:
                $data->currency,

            providerReference:
                null,

            checkoutUrl:
                $checkoutUrl,

            providerTransactionId:
                $providerTransactionId,

            message:
                $this->extractString(
                    $response['message'] ?? null
                )
                ?? 'Payment initialized successfully.',

            metadata: [
                'provider' => 'chapa',

                'initialization' => [
                    'status' =>
                        $status,

                    'transaction_reference' =>
                        $transactionReference,

                    'checkout_url' =>
                        $checkoutUrl,

                    'provider_transaction_id' =>
                        $providerTransactionId,
                ],
            ],
        );
    }

    /**
     * Map Chapa verification response to the application's
     * generic PaymentVerificationResult.
     *
     * This method does NOT modify the Payment model.
     */
    public function mapVerificationResponse(
        Payment $payment,
        array $response
    ): PaymentVerificationResult {
        $data = $response['data'] ?? null;

        if (!is_array($data)) {
            $data = [];
        }

        /*
         * Normalize provider status.
         */
        $status = $this->normalizeStatus(
            $data['status']
                ?? $data['payment_status']
                ?? $data['transaction_status']
                ?? $response['status']
                ?? null
        );

        /*
         * Municipality transaction reference.
         *
         * Chapa calls this tx_ref / trx_ref.
         */
        $transactionReference = $this->extractString(
            $data['tx_ref']
                ?? $data['trx_ref']
                ?? $data['transaction_reference']
                ?? $response['tx_ref']
                ?? $response['trx_ref']
                ?? null
        );

        /*
         * Provider reference.
         *
         * Example:
         *
         * APmyXpD0rSIZv
         */
        $providerReference = $this->extractString(
            $data['ref_id']
                ?? $data['provider_reference']
                ?? $response['ref_id']
                ?? null
        );

        /*
         * Provider-side transaction ID.
         *
         * Keep this separate from our municipal transaction reference.
         */
        $transactionId = $this->extractString(
            $data['transaction_id']
                ?? $data['transactionId']
                ?? $data['reference']
                ?? $response['transaction_id']
                ?? $response['reference']
                ?? null
        );

        /*
         * Financial values.
         */
        $amount = $data['amount']
            ?? $response['amount']
            ?? null;

        $currency = $this->extractString(
            $data['currency']
                ?? $response['currency']
                ?? null
        );

        /*
         * Provider payment timestamp.
         */
        $paidAt = $this->parseDate(
            $data['paid_at']
                ?? $data['completed_at']
                ?? $data['updated_at']
                ?? $response['paid_at']
                ?? $response['completed_at']
                ?? null
        );

        /*
         * SUCCESS
         *
         * Chapa has confirmed successful payment.
         *
         * IMPORTANT:
         *
         * PaymentStatus::COMPLETED is the application's
         * canonical successful payment status.
         *
         * PaymentStatus::PAID does not exist.
         */
        if ($this->isSuccessfulStatus($status)) {
            $this->validateSuccessfulTransaction(
                payment: $payment,
                transactionReference: $transactionReference,
                amount: $amount,
                currency: $currency,
            );

            /*
             * If Chapa doesn't return a payment timestamp,
             * use the local verification time.
             */
            $effectivePaidAt = $paidAt ?? now();

            $normalizedAmount = $this->normalizeMoney(
                $amount
            );

            $normalizedCurrency = strtoupper(
                trim((string) $currency)
            );

            return PaymentVerificationResult::success(
                status:
                    PaymentStatus::COMPLETED,

                transactionReference:
                    $transactionReference,

                transactionId:
                    $transactionId,

                providerReference:
                    $providerReference,

                amount:
                    (float) $normalizedAmount,

                currency:
                    $normalizedCurrency,

                paidAt:
                    $effectivePaidAt,

                metadata: [
                    'provider' => 'chapa',

                    'verification' => [
                        'status' =>
                            $status,

                        'transaction_reference' =>
                            $transactionReference,

                        'provider_reference' =>
                            $providerReference,

                        'transaction_id' =>
                            $transactionId,

                        'amount' =>
                            (float) $normalizedAmount,

                        'currency' =>
                            $normalizedCurrency,

                        'paid_at' =>
                            $paidAt?->toISOString(),
                    ],
                ],

                message:
                    $this->extractString(
                        $response['message'] ?? null
                    )
                    ?? 'Chapa payment verified successfully.',
            );
        }

        /*
         * FAILED
         *
         * Chapa explicitly reports that the transaction failed.
         */
        if ($this->isFailedStatus($status)) {
            return PaymentVerificationResult::failed(
                status:
                    PaymentStatus::FAILED,

                message:
                    $this->extractErrorMessage(
                        $response,
                        'Chapa payment failed.'
                    ),

                transactionReference:
                    $transactionReference,

                transactionId:
                    $transactionId,

                providerReference:
                    $providerReference,

                amount:
                    $this->normalizeOptionalMoney(
                        $amount
                    ),

                currency:
                    $currency !== null
                        ? strtoupper($currency)
                        : null,

                metadata: [
                    'provider' => 'chapa',

                    'verification' => [
                        'status' =>
                            $status,

                        'transaction_reference' =>
                            $transactionReference,

                        'provider_reference' =>
                            $providerReference,

                        'transaction_id' =>
                            $transactionId,
                    ],
                ],
            );
        }

        /*
         * PENDING / UNKNOWN
         *
         * Unknown provider statuses are deliberately treated
         * as pending rather than success.
         */
        return PaymentVerificationResult::pending(
            message:
                'Chapa payment is not yet completed.',

            transactionReference:
                $transactionReference,

            transactionId:
                $transactionId,

            providerReference:
                $providerReference,

            amount:
                $this->normalizeOptionalMoney(
                    $amount
                ),

            currency:
                $currency !== null
                    ? strtoupper($currency)
                    : null,

            metadata: [
                'provider' => 'chapa',

                'verification' => [
                    'status' =>
                        $status,

                    'transaction_reference' =>
                        $transactionReference,

                    'provider_reference' =>
                        $providerReference,

                    'transaction_id' =>
                        $transactionId,
                ],
            ],
        );
    }

    /**
     * Validate all financially important values from a successful
     * Chapa verification response.
     */
    private function validateSuccessfulTransaction(
        Payment $payment,
        ?string $transactionReference,
        mixed $amount,
        ?string $currency
    ): void {
        /*
         * 1. Provider transaction reference must exist.
         */
        if (
            $transactionReference === null
            || $transactionReference === ''
        ) {
            throw new RuntimeException(
                'Chapa verification succeeded but transaction reference is missing.'
            );
        }

        /*
         * 2. Provider tx_ref must exactly match our local tx_ref.
         */
        $localTransactionReference = trim(
            (string) $payment->transaction_reference
        );

        if (
            $localTransactionReference === ''
            || !hash_equals(
                $localTransactionReference,
                $transactionReference
            )
        ) {
            Log::error(
                'Chapa transaction reference mismatch.',
                [
                    'payment_id' =>
                        $payment->getKey(),

                    'payment_number' =>
                        $payment->payment_number,

                    'expected_transaction_reference' =>
                        $localTransactionReference,

                    'provider_transaction_reference' =>
                        $transactionReference,
                ]
            );

            throw new RuntimeException(
                'Chapa transaction reference does not match the local payment.'
            );
        }

        /*
         * 3. Amount must exist.
         */
        if (
            $amount === null
            || $amount === ''
        ) {
            throw new RuntimeException(
                'Chapa verification succeeded but transaction amount is missing.'
            );
        }

        /*
         * 4. Amount must be a valid positive decimal.
         */
        $normalizedProviderAmount =
            $this->normalizeMoney($amount);

        if (
            function_exists('bccomp')
            && bccomp(
                $normalizedProviderAmount,
                '0.00',
                2
            ) <= 0
        ) {
            throw new RuntimeException(
                'Chapa returned an invalid transaction amount.'
            );
        }

        if (
            !function_exists('bccomp')
            && (float) $normalizedProviderAmount <= 0
        ) {
            throw new RuntimeException(
                'Chapa returned an invalid transaction amount.'
            );
        }

        /*
         * 5. Provider amount must match local payment.
         */
        if (
            !$this->compareMoney(
                $amount,
                $payment->amount
            )
        ) {
            Log::error(
                'Chapa payment amount mismatch.',
                [
                    'payment_id' =>
                        $payment->getKey(),

                    'payment_number' =>
                        $payment->payment_number,

                    'expected_amount' =>
                        $this->normalizeMoney(
                            $payment->amount
                        ),

                    'provider_amount' =>
                        $normalizedProviderAmount,
                ]
            );

            throw new RuntimeException(
                'Chapa transaction amount does not match the local payment amount.'
            );
        }

        /*
         * 6. Currency must exist.
         */
        if (
            $currency === null
            || $currency === ''
        ) {
            throw new RuntimeException(
                'Chapa verification succeeded but transaction currency is missing.'
            );
        }

        /*
         * 7. Currency must be valid.
         */
        $normalizedProviderCurrency =
            strtoupper(
                trim($currency)
            );

        $localCurrency = strtoupper(
            trim(
                (string) $payment->currency
            )
        );

        if (
            $normalizedProviderCurrency === ''
            || !preg_match(
                '/^[A-Z]{3}$/',
                $normalizedProviderCurrency
            )
        ) {
            throw new RuntimeException(
                'Chapa returned an invalid transaction currency.'
            );
        }

        /*
         * 8. Currency must match local payment.
         */
        if (
            $normalizedProviderCurrency !==
            $localCurrency
        ) {
            Log::error(
                'Chapa payment currency mismatch.',
                [
                    'payment_id' =>
                        $payment->getKey(),

                    'payment_number' =>
                        $payment->payment_number,

                    'expected_currency' =>
                        $localCurrency,

                    'provider_currency' =>
                        $normalizedProviderCurrency,
                ]
            );

            throw new RuntimeException(
                'Chapa transaction currency does not match the local payment currency.'
            );
        }

        /*
         * 9. Municipality online payments must be ETB.
         */
        if (
            $normalizedProviderCurrency !==
            self::EXPECTED_CURRENCY
        ) {
            throw new RuntimeException(
                sprintf(
                    'Unsupported Chapa payment currency. Expected %s, received %s.',
                    self::EXPECTED_CURRENCY,
                    $normalizedProviderCurrency
                )
            );
        }
    }

    /**
     * Normalize provider status.
     */
    private function normalizeStatus(
        mixed $status
    ): string {
        if (
            $status === null
            || is_array($status)
            || is_object($status)
            || is_bool($status)
        ) {
            return '';
        }

        return strtolower(
            trim(
                (string) $status
            )
        );
    }

    /**
     * Determine whether a provider status represents success.
     */
    private function isSuccessfulStatus(
        string $status
    ): bool {
        return in_array(
            $status,
            [
                'success',
                'successful',
                'completed',
            ],
            true
        );
    }

    /**
     * Determine whether a provider status represents failure.
     */
    private function isFailedStatus(
        string $status
    ): bool {
        return in_array(
            $status,
            [
                'failed',
                'cancelled',
                'canceled',
            ],
            true
        );
    }

    /**
     * Compare monetary values without relying on binary
     * floating-point arithmetic when BCMath is available.
     */
    private function compareMoney(
        mixed $left,
        mixed $right
    ): bool {
        try {
            $left = $this->normalizeMoney(
                $left
            );

            $right = $this->normalizeMoney(
                $right
            );

            if (function_exists('bccomp')) {
                return bccomp(
                    $left,
                    $right,
                    2
                ) === 0;
            }

            return abs(
                ((float) $left)
                - ((float) $right)
            ) < 0.005;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Normalize a monetary value to exactly two decimal places.
     */
    private function normalizeMoney(
        mixed $value
    ): string {
        if (
            $value === null
            || $value === ''
        ) {
            throw new RuntimeException(
                'Invalid monetary value.'
            );
        }

        if (
            is_array($value)
            || is_object($value)
            || is_bool($value)
        ) {
            throw new RuntimeException(
                'Invalid monetary value.'
            );
        }

        $value = trim(
            str_replace(
                ',',
                '',
                (string) $value
            )
        );

        if ($value === '') {
            throw new RuntimeException(
                'Invalid monetary value.'
            );
        }

        if (
            preg_match(
                '/^-?\d+(?:\.\d+)?$/',
                $value
            ) !== 1
        ) {
            throw new RuntimeException(
                'Invalid monetary value returned by Chapa.'
            );
        }

        if (function_exists('bcadd')) {
            return bcadd(
                $value,
                '0',
                2
            );
        }

        return number_format(
            (float) $value,
            2,
            '.',
            ''
        );
    }

    /**
     * Normalize an optional monetary value.
     *
     * Used for pending/failed responses where Chapa may omit
     * the amount.
     */
    private function normalizeOptionalMoney(
        mixed $value
    ): ?float {
        if (
            $value === null
            || $value === ''
        ) {
            return null;
        }

        try {
            return (float) $this->normalizeMoney(
                $value
            );
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Parse provider date safely.
     */
    private function parseDate(
        mixed $value
    ): ?Carbon {
        if (
            $value === null
            || $value === ''
        ) {
            return null;
        }

        if (
            is_array($value)
            || is_object($value)
            || is_bool($value)
        ) {
            Log::warning(
                'Invalid Chapa payment date value.',
                [
                    'payment_date_type' =>
                        get_debug_type($value),
                ]
            );

            return null;
        }

        try {
            return Carbon::parse(
                (string) $value
            );
        } catch (Throwable $e) {
            Log::warning(
                'Unable to parse Chapa payment date.',
                [
                    'error' =>
                        $e->getMessage(),
                ]
            );

            return null;
        }
    }

    /**
     * Extract a trimmed scalar string.
     */
    private function extractString(
        mixed $value
    ): ?string {
        if (
            $value === null
            || is_array($value)
            || is_object($value)
            || is_bool($value)
        ) {
            return null;
        }

        $value = trim(
            (string) $value
        );

        return $value !== ''
            ? $value
            : null;
    }

    /**
     * Validate HTTP(S) URLs.
     */
    private function isValidHttpUrl(
        string $url
    ): bool {
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

        return in_array(
            $scheme,
            [
                'http',
                'https',
            ],
            true
        );
    }

    /**
     * Extract a useful provider error message.
     */
    private function extractErrorMessage(
        array $response,
        string $default = 'Chapa payment operation failed.'
    ): string {
        $message = $response['message'] ?? null;

        if ($message === null) {
            $message = $response['error'] ?? null;
        }

        if (
            $message === null
            && isset($response['data'])
            && is_array($response['data'])
        ) {
            $message =
                $response['data']['message']
                ?? null;
        }

        $message = $this->flattenErrorMessage(
            $message
        );

        if (
            $message === null
            || $message === ''
        ) {
            return $default;
        }

        return mb_substr(
            $message,
            0,
            self::MAX_ERROR_MESSAGE_LENGTH
        );
    }

    /**
     * Convert Chapa validation/error structures into one message.
     */
    private function flattenErrorMessage(
        mixed $message
    ): ?string {
        if (
            $message === null
            || $message === ''
        ) {
            return null;
        }

        if (
            is_string($message)
            || is_numeric($message)
        ) {
            return trim(
                (string) $message
            );
        }

        if (is_array($message)) {
            $parts = [];

            foreach ($message as $key => $value) {
                if (is_array($value)) {
                    $nested =
                        $this->flattenErrorMessage(
                            $value
                        );

                    if (
                        $nested !== null
                        && $nested !== ''
                    ) {
                        $parts[] = sprintf(
                            '%s: %s',
                            (string) $key,
                            $nested
                        );
                    }

                    continue;
                }

                if (
                    is_string($value)
                    || is_numeric($value)
                ) {
                    $value = trim(
                        (string) $value
                    );

                    if ($value !== '') {
                        $parts[] = sprintf(
                            '%s: %s',
                            (string) $key,
                            $value
                        );
                    }
                }
            }

            if ($parts === []) {
                return null;
            }

            return implode(
                '; ',
                $parts
            );
        }

        return null;
    }
}