<?php

namespace App\Modules\Payment\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payment\Requests\CashPaymentRequest;
use App\Modules\Payment\Resources\PaymentResource;
use App\Modules\Payment\Services\CashPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class CashPaymentController extends Controller
{
    public function __construct(
        protected CashPaymentService $cashPaymentService,
    ) {}

    /**
     * Record a cash payment.
     *
     * POST /api/cash-payments
     */
    public function store(
        CashPaymentRequest $request
    ): JsonResponse {
        $requestId = $request->header('X-Request-ID')
            ?: (string) Str::uuid();

        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Authentication is required.',
                    'request_id' => $requestId,
                ], 401);
            }

            /*
             * IMPORTANT:
             *
             * The collector is derived from the authenticated user.
             * Do NOT accept collector_id from the frontend.
             */
            $payment = $this->cashPaymentService->record(
                user: $user,
                data: $request->validated(),
                requestId: $requestId,
            );

            return response()->json([
                'success' => true,
                'message' => 'Cash payment recorded successfully.',
                'data' => new PaymentResource($payment),
                'request_id' => $requestId,
            ], 201);
        } catch (Throwable $e) {
            Log::error('Failed to record cash payment.', [
                'request_id' => $requestId,
                'user_id' => $request->user()?->id,
                'invoice_id' => $request->input('invoice_id'),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to record the cash payment.',
                'request_id' => $requestId,
            ], 500);
        }
    }

    /**
     * Confirm and post a cash payment.
     *
     * POST /api/cash-payments/{payment}/post
     */
    public function post(
        Request $request,
        string $payment
    ): JsonResponse {
        $requestId = $request->header('X-Request-ID')
            ?: (string) Str::uuid();

        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Authentication is required.',
                    'request_id' => $requestId,
                ], 401);
            }

            /*
             * The service must:
             *
             * 1. Find the payment.
             * 2. Verify it belongs to CASH channel.
             * 3. Verify its current status.
             * 4. Check authorization of the authenticated user.
             * 5. Confirm the cash.
             * 6. Mark the payment as POSTED.
             * 7. Update invoice balance.
             * 8. Update payment schedule if applicable.
             * 9. Generate the official receipt.
             * 10. Record audit information.
             *
             * These operations should happen transactionally.
             */
            $postedPayment = $this->cashPaymentService->post(
                paymentId: $payment,
                user: $user,
                requestId: $requestId,
            );

            return response()->json([
                'success' => true,
                'message' => 'Cash payment confirmed and posted successfully.',
                'data' => new PaymentResource($postedPayment),
                'request_id' => $requestId,
            ]);
        } catch (Throwable $e) {
            Log::error('Failed to post cash payment.', [
                'request_id' => $requestId,
                'user_id' => $request->user()?->id,
                'payment_id' => $payment,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to post the cash payment.',
                'request_id' => $requestId,
            ], 500);
        }
    }
}