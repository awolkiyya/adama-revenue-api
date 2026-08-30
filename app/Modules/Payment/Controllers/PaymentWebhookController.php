<?php

namespace App\Modules\Payment\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payment\Models\Payment;
use App\Modules\Payment\Services\PaymentReceiptService;
use App\Modules\Payment\Services\PaymentVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class PaymentWebhookController extends Controller
{
    public function __construct(
        protected PaymentVerificationService $verificationService,
        protected PaymentReceiptService $receiptService,
    ) {
    }

    /**
     * Handle Chapa webhook.
     *
     * IMPORTANT:
     * The webhook must NOT be protected by auth:sanctum.
     *
     * Chapa calls this endpoint directly.
     */
    public function chapa(Request $request): JsonResponse
    {
        try {
            /*
             * Keep the raw payload for logging/debugging.
             */
            $payload = $request->all();

            Log::info('Chapa webhook received.', [
                'payload' => $payload,
                'ip' => $request->ip(),
            ]);

            /*
             * Chapa transaction reference.
             *
             * Depending on the webhook payload/version,
             * tx_ref may be available directly or inside data.
             */
            $txRef =
                $payload['tx_ref']
                ?? data_get($payload, 'data.tx_ref')
                ?? null;

            if (!$txRef) {
                Log::warning(
                    'Chapa webhook received without transaction reference.',
                    [
                        'payload' => $payload,
                    ]
                );

                /*
                 * Return 200 so the provider does not continuously
                 * retry a malformed webhook forever.
                 *
                 * The event is logged for investigation.
                 */
                return response()->json([
                    'success' => true,
                    'message' => 'Webhook received.',
                ]);
            }

            /*
             * Find our local payment.
             *
             * Never trust the amount/status from the webhook alone.
             * The verification service will ask Chapa directly.
             */
            $payment = Payment::query()
                ->where(
                    'provider_reference',
                    $txRef
                )
                ->orWhere(
                    'transaction_reference',
                    $txRef
                )
                ->first();

            if (!$payment) {
                Log::warning(
                    'Chapa webhook received for unknown payment.',
                    [
                        'tx_ref' => $txRef,
                    ]
                );

                /*
                 * Again, return 200 because the webhook was received.
                 * There is no valid local payment to process.
                 */
                return response()->json([
                    'success' => true,
                    'message' => 'Webhook received.',
                ]);
            }

            /*
             * IMPORTANT:
             *
             * Do not trust:
             *
             *   status = success
             *
             * from the webhook.
             *
             * Verify the transaction directly with Chapa.
             */
            $result = $this->verificationService->verify(
                $payment
            );

            /*
             * Generate receipt only after the payment has been
             * independently verified as successful.
             *
             * PaymentReceiptService is idempotent, so repeated
             * webhooks will not create duplicate receipts.
             */
            if ($result->isSuccessful()) {
                $this->receiptService->create(
                    $payment->refresh()
                );
            }

            Log::info(
                'Chapa webhook processed successfully.',
                [
                    'payment_id' => $payment->id,
                    'tx_ref' => $txRef,
                    'status' => $result->status->value
                        ?? $result->status
                        ?? null,
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'Webhook processed successfully.',
            ]);
        } catch (Throwable $exception) {
            Log::error(
                'Chapa webhook processing failed.',
                [
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                    'payload' => $request->all(),
                ]
            );

            /*
             * Return 500 so Chapa can retry the webhook when the
             * failure is caused by our infrastructure/application.
             */
            return response()->json([
                'success' => false,
                'message' => 'Webhook processing failed.',
            ], 500);
        }
    }
}