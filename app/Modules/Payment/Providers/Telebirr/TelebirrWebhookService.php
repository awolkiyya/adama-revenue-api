<?php

declare(strict_types=1);

namespace App\Modules\Payment\Providers\Telebirr;

use App\Enums\PaymentProvider;
use App\Models\Payment;
use RuntimeException;

final readonly class TelebirrWebhookService
{
    /**
     * Resolve the local payment associated with a Telebirr callback.
     *
     * A callback is a notification, not proof of payment.
     * The actual transaction must be verified using Telebirr QueryOrder.
     */
    public function resolvePayment(array $payload): Payment
    {
        $merchantOrderId = $this->extractMerchantOrderId($payload);

        $payment = Payment::query()
            ->where('provider', PaymentProvider::TELEBIRR->value)
            ->where('provider_reference', $merchantOrderId)
            ->first();

        if (! $payment) {
            throw new RuntimeException(
                'No local Telebirr payment matches merchant order ID: '
                . $merchantOrderId
            );
        }

        return $payment;
    }

    /**
     * Extract the merchant order ID from supported callback structures.
     */
    private function extractMerchantOrderId(array $payload): string
    {
        $possibleFields = [
            'merch_order_id',
            'merchant_order_id',
            'merchantOrderId',
        ];

        foreach ($this->getPayloadContainers($payload) as $container) {
            foreach ($possibleFields as $field) {
                $value = $container[$field] ?? null;

                if (
                    is_string($value)
                    && trim($value) !== ''
                ) {
                    return trim($value);
                }

                if (is_int($value) && $value > 0) {
                    return (string) $value;
                }
            }
        }

        throw new RuntimeException(
            'Telebirr callback does not contain a valid merchant order ID.'
        );
    }

    /**
     * Return supported callback containers.
     */
    private function getPayloadContainers(array $payload): array
    {
        $containers = [$payload];

        foreach (['biz_content', 'data'] as $key) {
            if (is_array($payload[$key] ?? null)) {
                $nested = $payload[$key];

                $containers[] = $nested;

                if (is_array($nested['biz_content'] ?? null)) {
                    $containers[] = $nested['biz_content'];
                }

                if (is_array($nested['data'] ?? null)) {
                    $containers[] = $nested['data'];
                }
            }
        }

        return $containers;
    }
}
