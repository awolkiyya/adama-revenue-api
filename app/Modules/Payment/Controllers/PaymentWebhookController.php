<?php

namespace App\Modules\Payment\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Modules\Payment\Services\PaymentVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
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
     * Handle Chapa server-to-server webhook.
     *
     * POST:
     * /api/v1/payments/webhooks/chapa
     *
     * IMPORTANT:
     * This endpoint must NOT be protected by auth:sanctum.
     *
     * Financial processing is delegated to
     * PaymentVerificationService.
     */
    public function chapa(Request $request): JsonResponse
    {
        Log::emergency(
            '🔥 CHAPA WEBHOOK CONTROLLER EXECUTED'
        );

        try {
            /*
             * ---------------------------------------------------------
             * 1. Capture the complete webhook request
             * ---------------------------------------------------------
             */
            $payload = $request->all();

            Log::info(
                'Chapa webhook received.',
                [
                    'request_id' => $request->header(
                        'X-Request-ID'
                    ),
                    'method' => $request->method(),
                    'url' => $request->fullUrl(),
                    'payload' => $payload,
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'headers' => $request->headers->all(),
                ]
            );

            /*
             * ---------------------------------------------------------
             * 2. Extract transaction reference
             * ---------------------------------------------------------
             *
             * Support:
             *
             * tx_ref
             * trx_ref
             * reference
             *
             * and nested data.* variants.
             */
            $txRef =
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

            if (!$txRef) {
                Log::warning(
                    'Chapa webhook received without transaction reference.',
                    [
                        'payload' => $payload,
                    ]
                );

                /*
                 * Acknowledge the webhook.
                 *
                 * There is no local payment that can safely be
                 * verified without a transaction reference.
                 */
                return response()->json([
                    'success' => true,
                    'message' => 'Webhook received.',
                ]);
            }

            /*
             * ---------------------------------------------------------
             * 3. Extract provider reference
             * ---------------------------------------------------------
             */
            $providerReference =
                $payload['ref_id']
                ?? data_get(
                    $payload,
                    'data.ref_id'
                )
                ?? null;

            /*
             * ---------------------------------------------------------
             * 4. Extract provider notification status
             * ---------------------------------------------------------
             *
             * IMPORTANT:
             *
             * This status is only recorded for audit purposes.
             * We DO NOT trust it to complete the payment.
             */
            $providerStatus =
                $payload['status']
                ?? data_get(
                    $payload,
                    'data.status'
                )
                ?? null;

            /*
             * ---------------------------------------------------------
             * 5. Find local payment
             * ---------------------------------------------------------
             *
             * Chapa's trx_ref corresponds to our
             * online_details.checkout_reference.
             */
            $payment = Payment::query()
                ->whereHas(
                    'onlineDetails',
                    function ($query) use ($txRef) {
                        $query->where(
                            'checkout_reference',
                            $txRef
                        );
                    }
                )
                ->with([
                    'onlineDetails.paymentProvider',
                ])
                ->first();

            if (!$payment) {
                Log::warning(
                    'Chapa webhook received for unknown payment.',
                    [
                        'tx_ref' => $txRef,
                        'provider_reference' =>
                            $providerReference,
                        'provider_status' =>
                            $providerStatus,
                    ]
                );

                /*
                 * Acknowledge the webhook.
                 */
                return response()->json([
                    'success' => true,
                    'message' => 'Webhook received.',
                ]);
            }

            Log::info(
                'Chapa webhook matched local payment.',
                [
                    'payment_id' => $payment->id,
                    'payment_uuid' =>
                        $payment->uuid ?? null,
                    'payment_number' =>
                        $payment->payment_number,
                    'tx_ref' => $txRef,
                    'provider_reference' =>
                        $providerReference,
                    'provider_status' =>
                        $providerStatus,
                    'payment_status' =>
                        $payment->status?->value
                        ?? $payment->status
                        ?? null,
                ]
            );

            /*
             * ---------------------------------------------------------
             * 6. Persist webhook information
             * ---------------------------------------------------------
             *
             * Store the provider notification BEFORE independent
             * verification.
             *
             * This gives us an audit trail even when provider
             * verification fails.
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
                 * Only write provider_reference if the column
                 * actually exists on online_payment_details.
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
             * 7. Independently verify with Chapa
             * ---------------------------------------------------------
             *
             * VERY IMPORTANT:
             *
             * We do NOT use:
             *
             *     status=success
             *
             * from the webhook as proof of payment.
             *
             * PaymentVerificationService calls the configured
             * provider and performs independent verification.
             */
            $result = $this->verificationService->verify(
                $payment->refresh()
            );

            /*
             * ---------------------------------------------------------
             * 8. Normalize verification status for logging
             * ---------------------------------------------------------
             */
            $verificationStatus = $result->status ?? null;

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

            /*
             * ---------------------------------------------------------
             * 9. Refresh payment after verification/finalization
             * ---------------------------------------------------------
             *
             * PaymentVerificationService may have changed:
             *
             * - payment status
             * - provider reference
             * - transaction reference
             * - paid_at
             * - provider response
             */
            $freshPayment = $payment->refresh();

            /*
             * ---------------------------------------------------------
             * 10. Final processing log
             * ---------------------------------------------------------
             */
            Log::info(
                'Chapa webhook processed successfully.',
                [
                    'payment_id' => $freshPayment->id,
                    'payment_uuid' =>
                        $freshPayment->uuid ?? null,
                    'payment_number' =>
                        $freshPayment->payment_number,

                    'tx_ref' => $txRef,

                    'provider_reference' =>
                        $providerReference
                        ?? $freshPayment->provider_reference,

                    'provider_status' =>
                        $providerStatus,

                    'verification_status' =>
                        $verificationStatus,

                    'payment_status' =>
                        $freshPayment->status?->value
                        ?? $freshPayment->status
                        ?? null,

                    'paid_at' =>
                        $freshPayment->paid_at,

                    'amount' =>
                        $freshPayment->amount,
                ]
            );

            /*
             * ---------------------------------------------------------
             * 11. Acknowledge Chapa
             * ---------------------------------------------------------
             *
             * The financial work has already been completed by
             * PaymentVerificationService.
             */
            return response()->json([
                'success' => true,
                'message' => 'Webhook processed successfully.',
            ]);
        } catch (Throwable $exception) {
            Log::error(
                'Chapa webhook processing failed.',
                [
                    'request_id' => $request->header(
                        'X-Request-ID'
                    ),
                    'method' => $request->method(),
                    'url' => $request->fullUrl(),

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
             * Return HTTP 500 so Chapa can retry when appropriate.
             */
            return response()->json([
                'success' => false,
                'message' =>
                    'Webhook processing failed.',
            ], 500);
        }
    }

    /**
     * Handle Chapa browser callback / redirect.
     *
     * GET:
     * /api/v1/payments/callback/chapa
     *
     * IMPORTANT:
     *
     * This endpoint does NOT complete the payment.
     *
     * It only identifies the transaction and redirects
     * the customer to the frontend.
     *
     * The server-side webhook / verification process is
     * responsible for financial finalization.
     */
    public function chapaCallback(
        Request $request
    ): RedirectResponse {
        try {
            /*
             * ---------------------------------------------------------
             * 1. Capture the complete callback request
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
                    'query' => $request->query(),
                    'body' => $request->all(),
                    'payload' => $payload,
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'headers' => $request->headers->all(),
                ]
            );

            /*
             * ---------------------------------------------------------
             * 2. Extract transaction reference
             * ---------------------------------------------------------
             */
            $txRef =
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
                ?? $request->query('tx_ref')
                ?? $request->query('trx_ref')
                ?? $request->query('reference')
                ?? null;

            /*
             * ---------------------------------------------------------
             * 3. Extract provider reference
             * ---------------------------------------------------------
             */
            $providerReference =
                $payload['ref_id']
                ?? data_get(
                    $payload,
                    'data.ref_id'
                )
                ?? $request->query('ref_id')
                ?? null;

            /*
             * ---------------------------------------------------------
             * 4. Extract provider status
             * ---------------------------------------------------------
             */
            $providerStatus =
                $payload['status']
                ?? data_get(
                    $payload,
                    'data.status'
                )
                ?? $request->query('status')
                ?? null;

            /*
             * ---------------------------------------------------------
             * 5. Validate transaction reference
             * ---------------------------------------------------------
             */
            if (!$txRef) {
                Log::warning(
                    'Chapa callback received without transaction reference.',
                    [
                        'method' =>
                            $request->method(),

                        'url' =>
                            $request->fullUrl(),

                        'payload' =>
                            $payload,

                        'query' =>
                            $request->query(),
                    ]
                );

                return redirect()->away(
                    $this->frontendPaymentResultUrl()
                );
            }

            /*
             * ---------------------------------------------------------
             * 6. Log identified transaction
             * ---------------------------------------------------------
             *
             * We intentionally do NOT complete the payment here.
             */
            Log::info(
                'Chapa callback identified transaction.',
                [
                    'tx_ref' => $txRef,
                    'provider_reference' =>
                        $providerReference,
                    'provider_status' =>
                        $providerStatus,
                ]
            );

            /*
             * ---------------------------------------------------------
             * 7. Redirect browser to frontend
             * ---------------------------------------------------------
             *
             * The frontend should use tx_ref to request the
             * authoritative payment state from our backend.
             */
            return redirect()->away(
                $this->frontendPaymentResultUrl(
                    $txRef
                )
            );
        } catch (Throwable $exception) {
            Log::error(
                'Chapa callback processing failed.',
                [
                    'request_id' => $request->header(
                        'X-Request-ID'
                    ),
                    'method' => $request->method(),
                    'url' => $request->fullUrl(),

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

                    'query' =>
                        $request->query(),
                ]
            );

            return redirect()->away(
                $this->frontendPaymentResultUrl()
            );
        }
    }

    /**
     * Build the frontend payment result URL.
     */
    protected function frontendPaymentResultUrl(
        ?string $txRef = null
    ): string {
        $baseUrl = config(
            'app.frontend_url',
            env(
                'FRONTEND_URL',
                'http://192.168.3.1:3000'
            )
        );

        $url = rtrim(
            $baseUrl,
            '/'
        ) . '/en/payment/result';

        if ($txRef) {
            $url .= '?tx_ref=' . urlencode(
                $txRef
            );
        }

        return $url;
    }
}

