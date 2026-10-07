<?php

declare(strict_types=1);

namespace App\Modules\Payment\Providers\Chapa;

use App\Models\Payment;
use App\Modules\Payment\DTOs\PaymentVerificationResult;
use App\Modules\Payment\Services\PaymentVerificationService;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ChapaWebhookService
{
    public function __construct(
        private readonly PaymentVerificationService $verificationService,
    ) {
    }

    /**
     * Handle a Chapa webhook payload.
     *
     * The webhook is only a trigger.
     *
     * We NEVER trust the payment status supplied by the webhook
     * as authoritative proof of payment.
     *
     * The local payment is independently verified against Chapa.
     */
    public function handle(
        array $payload,
        ?string $signature = null
    ): PaymentVerificationResult {
        $transactionReference =
            $this->extractTransactionReference($payload);

        Log::info(
            'Chapa webhook received.',
            [
                'transaction_reference' => $transactionReference,
            ]
        );

        /*
         * Validate webhook authenticity if explicitly enabled.
         */
        $this->verifySignatureIfRequired(
            payload: $payload,
            signature: $signature,
        );

        if (
            $transactionReference === null
            || $transactionReference === ''
        ) {
            throw new RuntimeException(
                'Chapa webhook does not contain a transaction reference.'
            );
        }

        /*
         * Find the local payment using our canonical
         * transaction_reference.
         *
         * This must be the same value sent to Chapa as tx_ref.
         */
        $payment = Payment::query()
            ->where(
                'transaction_reference',
                $transactionReference
            )
            ->first();

        if (! $payment) {
            Log::warning(
                'Chapa webhook received for unknown payment.',
                [
                    'transaction_reference' =>
                        $transactionReference,
                ]
            );

            throw new RuntimeException(
                'No local payment was found for the Chapa transaction reference.'
            );
        }

        Log::info(
            'Chapa webhook matched local payment.',
            [
                'payment_id' => $payment->getKey(),
                'payment_number' => $payment->payment_number,
                'transaction_reference' =>
                    $payment->transaction_reference,
                'current_status' =>
                    $payment->status?->value
                    ?? $payment->status,
            ]
        );

        /*
         * Store the webhook for audit/debugging.
         *
         * This does NOT change payment status.
         */
        $this->storeWebhookMetadata(
            payment: $payment,
            payload: $payload,
        );

        /*
         * IMPORTANT:
         *
         * Do not do this:
         *
         *     $payload['status'] === 'success'
         *
         * and then mark the payment as COMPLETED.
         *
         * Instead, ask Chapa directly for the authoritative
         * transaction status.
         *
         * PaymentVerificationService will:
         *
         * 1. Resolve CHAPA provider.
         * 2. Call ChapaPaymentProvider::verify().
         * 3. Call ChapaClient::verify().
         * 4. Validate amount/currency/reference.
         * 5. Lock the payment.
         * 6. Finalize payment.
         * 7. Update invoice.
         * 8. Create receipt when appropriate.
         */
        return $this->verificationService->verify(
            $payment->refresh()
        );
    }

    /**
     * Extract canonical transaction reference from Chapa payload.
     */
    private function extractTransactionReference(
        array $payload
    ): ?string {
        $data = $payload['data'] ?? [];

        if (! is_array($data)) {
            $data = [];
        }

        $reference =
            $payload['tx_ref']
            ?? $payload['trx_ref']
            ?? $payload['reference']
            ?? $data['tx_ref']
            ?? $data['trx_ref']
            ?? $data['transaction_reference']
            ?? $data['reference']
            ?? null;

        if ($reference === null) {
            return null;
        }

        $reference = trim(
            (string) $reference
        );

        return $reference !== ''
            ? $reference
            : null;
    }

    /**
     * Store webhook data for audit purposes.
     *
     * This assumes Payment has a JSON metadata column.
     */
    private function storeWebhookMetadata(
        Payment $payment,
        array $payload
    ): void {
        $metadata = $payment->metadata ?? [];

        if (! is_array($metadata)) {
            $metadata = [];
        }

        /*
         * Preserve previous webhook information if multiple
         * webhook deliveries are received.
         */
        $existingWebhooks =
            $metadata['chapa_webhooks'] ?? [];

        if (! is_array($existingWebhooks)) {
            $existingWebhooks = [];
        }

        $existingWebhooks[] = [
            'received_at' => now()->toISOString(),
            'payload' => $this->sanitizePayload(
                $payload
            ),
        ];

        /*
         * Keep only the latest 10 webhook payloads to avoid
         * unbounded growth of the Payment metadata JSON field.
         */
        $metadata['chapa_webhooks'] =
            array_slice(
                $existingWebhooks,
                -10
            );

        $payment->forceFill([
            'metadata' => $metadata,
        ])->save();
    }

    /**
     * Validate webhook signature when explicitly enabled.
     *
     * IMPORTANT:
     *
     * The HMAC implementation below must only be enabled if it
     * exactly matches the signing mechanism documented by Chapa
     * for your integration.
     */
    private function verifySignatureIfRequired(
        array $payload,
        ?string $signature
    ): void {
        $enabled = (bool) config(
            'services.chapa.verify_webhook_signature',
            false
        );

        if (! $enabled) {
            return;
        }

        if (
            $signature === null
            || trim($signature) === ''
        ) {
            throw new RuntimeException(
                'Chapa webhook signature is missing.'
            );
        }

        $secret = (string) config(
            'services.chapa.webhook_secret'
        );

        if ($secret === '') {
            throw new RuntimeException(
                'Chapa webhook secret is not configured.'
            );
        }

        $encodedPayload = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
        );

        if ($encodedPayload === false) {
            throw new RuntimeException(
                'Unable to encode Chapa webhook payload for signature verification.'
            );
        }

        $expected = hash_hmac(
            'sha256',
            $encodedPayload,
            $secret
        );

        if (! hash_equals(
            $expected,
            trim($signature)
        )) {
            throw new RuntimeException(
                'Invalid Chapa webhook signature.'
            );
        }
    }

    /**
     * Sanitize webhook payload before persistence.
     */
    private function sanitizePayload(
        array $payload
    ): array {
        $sensitiveKeys = [
            'authorization',
            'token',
            'secret',
            'secret_key',
            'password',
            'api_key',
            'access_token',
        ];

        return $this->sanitizeArray(
            $payload,
            $sensitiveKeys
        );
    }

    /**
     * Recursively sanitize nested arrays.
     */
    private function sanitizeArray(
        array $data,
        array $sensitiveKeys
    ): array {
        foreach ($data as $key => $value) {
            $normalizedKey = strtolower(
                str_replace(
                    ['-', ' '],
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
                $data[$key] = $this->sanitizeArray(
                    $value,
                    $sensitiveKeys
                );
            }
        }

        return $data;
    }
}

