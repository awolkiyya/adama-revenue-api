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

        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication is required.',
                'request_id' => $requestId,
            ], 401);
        }

        try {
            /*
             * The authenticated user is passed to the service.
             *
             * Do not accept:
             * - received_by
             * - processed_by
             * - verified_by
             * - payment_number
             * - transaction_reference
             * - cash_receipt_number
             *
             * from the frontend.
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
                'user_id' => $user->id,
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
     * Confirm and complete a cash payment.
     *
     * POST /api/cash-payments/{payment}/complete
     */
    public function complete(
        Request $request,
        string $payment
    ): JsonResponse {
        $requestId = $request->header('X-Request-ID')
            ?: (string) Str::uuid();

        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication is required.',
                'request_id' => $requestId,
            ], 401);
        }

        try {
            /*
             * The service is responsible for:
             *
             * 1. Finding the payment.
             * 2. Verifying that the payment method is CASH.
             * 3. Verifying the payment status.
             * 4. Checking the user's authorization.
             * 5. Confirming the cash.
             * 6. Marking the payment as COMPLETED.
             * 7. Setting verified_by / verified_at.
             * 8. Updating the invoice balance.
             * 9. Updating the payment schedule if applicable.
             * 10. Generating the official receipt.
             * 11. Recording audit information.
             *
             * These operations must be transactional.
             */
            $completedPayment = $this->cashPaymentService->complete(
                paymentId: $payment,
                user: $user,
                requestId: $requestId,
            );

            return response()->json([
                'success' => true,
                'message' => 'Cash payment completed successfully.',
                'data' => new PaymentResource($completedPayment),
                'request_id' => $requestId,
            ]);
        } catch (Throwable $e) {
            Log::error('Failed to complete cash payment.', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'payment_id' => $payment,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to complete the cash payment.',
                'request_id' => $requestId,
            ], 500);
        }
    }
}