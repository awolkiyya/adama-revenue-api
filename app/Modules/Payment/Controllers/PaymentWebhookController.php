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

    /**
     * Handle Telebirr server-to-server payment notification.
     *
     * POST:
     * /api/v1/payments/webhooks/telebirr
     *
     * IMPORTANT:
     * - This endpoint must remain outside auth:sanctum.
     * - Validate Telebirr notification authenticity before processing.
     * - Never trust notification status as proof of successful payment.
     * - Independently verify the transaction with Telebirr.
     * - Finalize payment through PaymentVerificationService.
     *
     * TODO:
     * Implement Telebirr-specific signature/authentication validation
     * according to the actual Telebirr integration documentation.
     */
    public function telebirr(
        Request $request
    ): JsonResponse {
        try {
            /*
            * ---------------------------------------------------------
            * 1. Capture notification payload
            * ---------------------------------------------------------
            */

            $payload = $request->all();

            Log::info(
                'Telebirr webhook notification received.',
                [
                    'request_id' => $request->header('X-Request-ID'),
                    'method' => $request->method(),
                    'ip' => $request->ip(),
                    'content_type' => $request->header('Content-Type'),
                ]
            );

            /*
            * ---------------------------------------------------------
            * 2. Validate notification authenticity
            * ---------------------------------------------------------
            *
            * TODO:
            *
            * Implement the actual Telebirr notification verification.
            *
            * Depending on the integration, this may require:
            * - Signature validation.
            * - Public-key verification.
            * - Decryption of notification data.
            * - Provider-specific authentication headers.
            *
            * Do not process unverified notifications as trusted events.
            */

            // TODO: Replace with actual Telebirr notification validation.
            //
            // if (! $this->telebirrService->validateNotification($request)) {
            //     Log::warning('Invalid Telebirr webhook notification.');
            //
            //     return response()->json([
            //         'success' => false,
            //         'message' => 'Invalid notification.',
            //     ], 401);
            // }

            /*
            * ---------------------------------------------------------
            * 3. Extract local transaction reference
            * ---------------------------------------------------------
            *
            * TODO:
            *
            * Replace these candidate keys with the exact field
            * specified by your Telebirr integration.
            *
            * The value must match:
            *
            * payments.transaction_reference
            */

            $transactionReference =
                $payload['transaction_reference']
                ?? $payload['outTradeNo']
                ?? $payload['out_trade_no']
                ?? data_get($payload, 'data.transaction_reference')
                ?? data_get($payload, 'data.outTradeNo')
                ?? data_get($payload, 'data.out_trade_no')
                ?? null;

            if (! is_string($transactionReference) &&
                ! is_numeric($transactionReference)) {
                Log::warning(
                    'Telebirr notification missing transaction reference.'
                );

                return response()->json([
                    'success' => false,
                    'message' => 'Transaction reference is required.',
                ], 400);
            }

            $transactionReference = trim(
                (string) $transactionReference
            );

            if ($transactionReference === '') {
                return response()->json([
                    'success' => false,
                    'message' => 'Transaction reference is required.',
                ], 400);
            }

            /*
            * ---------------------------------------------------------
            * 4. Find local payment
            * ---------------------------------------------------------
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
                    'Telebirr notification received for unknown payment.',
                    [
                        'transaction_reference' => $transactionReference,
                    ]
                );

                return response()->json([
                    'success' => true,
                    'message' => 'Notification received.',
                ]);
            }

            /*
            * ---------------------------------------------------------
            * 5. Confirm provider ownership
            * ---------------------------------------------------------
            *
            * TODO:
            *
            * Confirm that this payment belongs to Telebirr using
            * your actual payment provider relationship/identifier.
            *
            * Never allow a Telebirr notification to trigger verification
            * of a payment belonging to another provider.
            */

            // Example only; replace with your actual provider identifier.
            //
            // if ($payment->onlineDetails?->paymentProvider?->code !== 'TELEBIRR') {
            //     return response()->json([
            //         'success' => false,
            //         'message' => 'Payment provider mismatch.',
            //     ], 422);
            // }

            /*
            * ---------------------------------------------------------
            * 6. Store notification information
            * ---------------------------------------------------------
            *
            * Notification status is informational only.
            * It is NOT proof of successful payment.
            */

            $onlineDetails = $payment->onlineDetails;

            if ($onlineDetails) {
                $onlineDetails->update([
                    'callback_received_at' => now(),
                    'provider_response' => $payload,
                ]);
            }

            /*
            * ---------------------------------------------------------
            * 7. Independently verify payment
            * ---------------------------------------------------------
            *
            * PaymentVerificationService must resolve Telebirr
            * and perform provider-side transaction verification.
            */

            $this->verificationService->verify(
                $payment->refresh()
            );

            $freshPayment = $payment->refresh();

            Log::info(
                'Telebirr webhook notification processed.',
                [
                    'payment_id' => $freshPayment->getKey(),
                    'payment_number' => $freshPayment->payment_number,
                    'transaction_reference' => $transactionReference,
                    'payment_status' => $freshPayment->status?->value
                        ?? $freshPayment->status
                        ?? null,
                ]
            );

            /*
            * ---------------------------------------------------------
            * 8. Acknowledge notification
            * ---------------------------------------------------------
            */

            return response()->json([
                'success' => true,
                'message' => 'Notification received successfully.',
            ]);

        } catch (Throwable $exception) {
            Log::error(
                'Telebirr webhook processing failed.',
                [
                    'request_id' => $request->header('X-Request-ID'),
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]
            );

            return response()->json([
                'success' => false,
                'message' => 'Notification processing failed.',
            ], 500);
        }
    }

    /**
     * Handle Telebirr browser callback.
     *
     * GET:
     * /api/v1/payments/callback/telebirr
     *
     * IMPORTANT:
     * - Browser callback is not proof of payment.
     * - Independently verify the transaction with Telebirr.
     * - Never mark payment as successful using callback parameters.
     *
     * TODO:
     * Configure the frontend payment-result URL.
     */
    public function telebirrCallback(
        Request $request
    ): JsonResponse {
        try {
            /*
            * ---------------------------------------------------------
            * 1. Capture callback parameters
            * ---------------------------------------------------------
            */

            $payload = $request->query();

            Log::info(
                'Telebirr browser callback received.',
                [
                    'request_id' => $request->header('X-Request-ID'),
                    'ip' => $request->ip(),
                ]
            );

            /*
            * ---------------------------------------------------------
            * 2. Extract transaction reference
            * ---------------------------------------------------------
            *
            * TODO:
            *
            * Replace these candidate parameter names with the exact
            * parameter returned by your Telebirr integration.
            */

            $transactionReference =
                $payload['transaction_reference']
                ?? $payload['outTradeNo']
                ?? $payload['out_trade_no']
                ?? $payload['reference']
                ?? null;

            if (! is_string($transactionReference) &&
                ! is_numeric($transactionReference)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Transaction reference is required.',
                ], 400);
            }

            $transactionReference = trim(
                (string) $transactionReference
            );

            if ($transactionReference === '') {
                return response()->json([
                    'success' => false,
                    'message' => 'Transaction reference is required.',
                ], 400);
            }

            /*
            * ---------------------------------------------------------
            * 3. Find local payment
            * ---------------------------------------------------------
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
                    'Telebirr callback received for unknown payment.',
                    [
                        'transaction_reference' => $transactionReference,
                    ]
                );

                return response()->json([
                    'success' => false,
                    'message' => 'Payment not found.',
                ], 404);
            }

            /*
            * ---------------------------------------------------------
            * 4. Confirm provider ownership
            * ---------------------------------------------------------
            *
            * TODO:
            * Check that the payment belongs to Telebirr.
            */

            /*
            * ---------------------------------------------------------
            * 5. Independently verify payment
            * ---------------------------------------------------------
            */

            $this->verificationService->verify(
                $payment->refresh()
            );

            $freshPayment = $payment->refresh();

            Log::info(
                'Telebirr browser callback processed.',
                [
                    'payment_id' => $freshPayment->getKey(),
                    'payment_number' => $freshPayment->payment_number,
                    'transaction_reference' => $transactionReference,
                    'payment_status' => $freshPayment->status?->value
                        ?? $freshPayment->status
                        ?? null,
                ]
            );

            /*
            * ---------------------------------------------------------
            * 6. Return payment result
            * ---------------------------------------------------------
            *
            * TODO:
            *
            * Replace this JSON response with a redirect to your
            * frontend payment-result page.
            *
            * Example:
            *
            * return redirect()->away(
            *     config('app.frontend_url')
            *     . '/payment/result?reference='
            *     . urlencode($transactionReference)
            * );
            */

            return response()->json([
                'success' => true,
                'message' => 'Payment callback processed.',
                'data' => [
                    'payment_id' => $freshPayment->getKey(),
                    'transaction_reference' => $transactionReference,
                    'status' => $freshPayment->status?->value
                        ?? $freshPayment->status
                        ?? null,
                ],
            ]);

        } catch (Throwable $exception) {
            Log::error(
                'Telebirr browser callback processing failed.',
                [
                    'request_id' => $request->header('X-Request-ID'),
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]
            );

            return response()->json([
                'success' => false,
                'message' => 'Payment callback processing failed.',
            ], 500);
        }
    }
}
