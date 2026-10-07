<?php

declare(strict_types=1);

namespace App\Modules\Payment\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Modules\Payment\Services\PaymentVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class PaymentWebhookController extends Controller
{
    public function __construct(
        protected PaymentVerificationService $verificationService,
    ) {
    }

    /**
     * Handle Chapa payment callback.
     *
     * GET:
     * /api/v1/payments/callback/chapa
     *
     * Chapa redirects/calls this endpoint with parameters such as:
     *
     * - trx_ref
     * - ref_id
     * - status
     *
     * IMPORTANT:
     * - Do not protect this route with auth:sanctum.
     * - Do not trust the callback status as final proof of payment.
     * - Independently verify the transaction with Chapa.
     * - The local payment is identified by transaction_reference.
     */
    public function chapaCallback(
        Request $request
    ): JsonResponse {
        try {
            /*
             * ---------------------------------------------------------
             * 1. Capture callback payload
             * ---------------------------------------------------------
             */
            $payload = $request->all();

            Log::info(
                'Chapa callback received.',
                [
                    'request_id' => $request->header(
                        'X-Request-ID'
                    ),

                    'method' => $request->method(),

                    'url' => $request->fullUrl(),

                    'payload' => $payload,

                    'ip' => $request->ip(),
                ]
            );

            /*
             * ---------------------------------------------------------
             * 2. Extract transaction reference
             * ---------------------------------------------------------
             *
             * Chapa may provide the transaction reference as:
             *
             * - tx_ref
             * - trx_ref
             * - reference
             *
             * The canonical local field is:
             *
             *     payments.transaction_reference
             *
             * This is the value originally sent to Chapa as tx_ref.
             */
            $transactionReference =
                $payload['tx_ref']
                ?? $payload['trx_ref']
                ?? $payload['reference']
                ?? data_get(
                    $payload,
                    'data.tx_ref'
                )
                ?? data_get(
                    $payload,
                    'data.trx_ref'
                )
                ?? data_get(
                    $payload,
                    'data.reference'
                )
                ?? null;

            if ($transactionReference === null) {
                Log::warning(
                    'Chapa callback received without transaction reference.',
                    [
                        'payload' => $payload,
                    ]
                );

                return response()->json([
                    'success' => false,
                    'message' =>
                        'Transaction reference is required.',
                ], 400);
            }

            $transactionReference = trim(
                (string) $transactionReference
            );

            if ($transactionReference === '') {
                Log::warning(
                    'Chapa callback received with empty transaction reference.',
                    [
                        'payload' => $payload,
                    ]
                );

                return response()->json([
                    'success' => false,
                    'message' =>
                        'Transaction reference is required.',
                ], 400);
            }

            /*
             * ---------------------------------------------------------
             * 3. Extract provider information
             * ---------------------------------------------------------
             *
             * ref_id is a Chapa-side provider reference.
             *
             * It is NOT used to identify our local Payment.
             */
            $providerReference =
                $payload['ref_id']
                ?? data_get(
                    $payload,
                    'data.ref_id'
                )
                ?? null;

            if ($providerReference !== null) {
                $providerReference = trim(
                    (string) $providerReference
                );

                if ($providerReference === '') {
                    $providerReference = null;
                }
            }

            /*
             * Chapa callback status is informational only.
             *
             * We do NOT use this value to mark the payment as paid.
             */
            $providerStatus =
                $payload['status']
                ?? data_get(
                    $payload,
                    'data.status'
                )
                ?? null;

            if ($providerStatus !== null) {
                $providerStatus = trim(
                    (string) $providerStatus
                );

                if ($providerStatus === '') {
                    $providerStatus = null;
                }
            }

            /*
             * ---------------------------------------------------------
             * 4. Find local payment
             * ---------------------------------------------------------
             *
             * IMPORTANT:
             *
             * Chapa sends:
             *
             *     trx_ref=PAY-01M4BB...
             *
             * Our Payment stores:
             *
             *     transaction_reference=PAY-01M4BB...
             *
             * Therefore we MUST search the Payment table directly.
             *
             * Do NOT search:
             *
             *     onlineDetails.checkout_reference
             *
             * because checkout_reference is a different concept.
             */
            $payment = Payment::query()
                ->where(
                    'transaction_reference',
                    $transactionReference
                )
                ->with([
                    'onlineDetails.paymentProvider',
                ])
                ->first();

            if (! $payment) {
                Log::warning(
                    'Chapa callback received for unknown payment.',
                    [
                        'transaction_reference' =>
                            $transactionReference,

                        'provider_reference' =>
                            $providerReference,

                        'provider_status' =>
                            $providerStatus,
                    ]
                );

                /*
                 * We acknowledge the callback.
                 *
                 * There is no local payment that can safely be
                 * processed for this transaction reference.
                 */
                return response()->json([
                    'success' => true,
                    'message' => 'Callback received.',
                ]);
            }

            Log::info(
                'Chapa callback matched local payment.',
                [
                    'payment_id' =>
                        $payment->getKey(),

                    'payment_number' =>
                        $payment->payment_number,

                    'transaction_reference' =>
                        $payment->transaction_reference,

                    'provider_reference' =>
                        $providerReference,

                    'provider_status' =>
                        $providerStatus,

                    'current_payment_status' =>
                        $payment->status?->value
                        ?? $payment->status
                        ?? null,
                ]
            );

            /*
             * ---------------------------------------------------------
             * 5. Store provider callback information
             * ---------------------------------------------------------
             *
             * This information is useful for audit/debugging.
             *
             * It is NOT considered proof that the payment succeeded.
             */
            $onlineDetails = $payment->onlineDetails;

            if ($onlineDetails) {
                $updateData = [
                    'callback_received_at' => now(),

                    'provider_status' =>
                        $providerStatus
                        ?? $onlineDetails->provider_status,

                    'provider_response' => $payload,
                ];

                /*
                 * Store Chapa ref_id if the column exists.
                 */
                if (
                    $providerReference !== null
                    && array_key_exists(
                        'provider_reference',
                        $onlineDetails->getAttributes()
                    )
                ) {
                    $updateData['provider_reference'] =
                        $providerReference;
                }

                $onlineDetails->update(
                    $updateData
                );
            }

            /*
             * ---------------------------------------------------------
             * 6. Independently verify payment with Chapa
             * ---------------------------------------------------------
             *
             * NEVER finalize payment simply because:
             *
             *     status=success
             *
             * was included in the callback.
             *
             * PaymentVerificationService will:
             *
             * 1. Resolve the correct provider.
             * 2. Call Chapa's verification API.
             * 3. Validate the provider response.
             * 4. Validate amount/currency/reference.
             * 5. Update the local Payment.
             * 6. Recalculate the Invoice.
             * 7. Create the receipt when appropriate.
             */
            $result = $this->verificationService->verify(
                $payment->refresh()
            );

            /*
             * ---------------------------------------------------------
             * 7. Refresh finalized payment
             * ---------------------------------------------------------
             */
            $freshPayment = $payment->refresh();

            $verificationStatus =
                $result->status ?? null;

            /*
             * Support both enum and string status values.
             */
            if (
                is_object($verificationStatus)
                && method_exists(
                    $verificationStatus,
                    'value'
                )
            ) {
                $verificationStatus =
                    $verificationStatus->value;
            }

            $paymentStatus =
                $freshPayment->status ?? null;

            if (
                is_object($paymentStatus)
                && method_exists(
                    $paymentStatus,
                    'value'
                )
            ) {
                $paymentStatus =
                    $paymentStatus->value;
            }

            Log::info(
                'Chapa callback processed.',
                [
                    'payment_id' =>
                        $freshPayment->getKey(),

                    'payment_number' =>
                        $freshPayment->payment_number,

                    'transaction_reference' =>
                        $transactionReference,

                    'provider_reference' =>
                        $providerReference
                        ?? $freshPayment->provider_reference,

                    'provider_status' =>
                        $providerStatus,

                    'verification_status' =>
                        $verificationStatus,

                    'payment_status' =>
                        $paymentStatus,

                    'paid_at' =>
                        $freshPayment->paid_at,
                ]
            );

            /*
             * ---------------------------------------------------------
             * 8. Acknowledge callback
             * ---------------------------------------------------------
             */
            return response()->json([
                'success' => true,
                'message' =>
                    'Callback processed successfully.',
            ]);
        } catch (Throwable $exception) {
            /*
             * ---------------------------------------------------------
             * Error handling
             * ---------------------------------------------------------
             */
            Log::error(
                'Chapa callback processing failed.',
                [
                    'request_id' =>
                        $request->header(
                            'X-Request-ID'
                        ),

                    'exception' =>
                        $exception::class,

                    'message' =>
                        $exception->getMessage(),

                    'file' =>
                        $exception->getFile(),

                    'line' =>
                        $exception->getLine(),

                    'payload' =>
                        $request->all(),
                ]
            );

            /*
             * 500 indicates that callback processing failed.
             *
             * This allows the provider to retry according to its
             * callback/retry behavior.
             */
            return response()->json([
                'success' => false,
                'message' =>
                    'Callback processing failed.',
            ], 500);
        }
    }
}
