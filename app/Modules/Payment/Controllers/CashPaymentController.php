<?php

namespace App\Modules\Payment\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payment\Requests\CashPaymentRequest;
use App\Modules\Payment\Resources\PaymentResource;
use App\Modules\Payment\Services\CashPaymentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class CashPaymentController extends Controller
{
    public function __construct(
        protected CashPaymentService $cashPaymentService,
    ) {}

    /**
     * Record and complete a cash payment.
     *
     * POST /api/cash-payments
     *
     * Expected workflow:
     * 1. Authenticate the user.
     * 2. Validate the request.
     * 3. Record the cash payment as COMPLETED.
     * 4. Update the invoice balance and status.
     * 5. Update the relevant payment schedule, if applicable.
     * 6. Generate the official receipt.
     * 7. Record audit information.
     *
     * Payment completion and invoice updates must be handled
     * transactionally by CashPaymentService.
     *
     * Cash reconciliation is performed separately.
     */
    public function store(
        CashPaymentRequest $request
    ): JsonResponse {
        $requestId = $request->header('X-Request-ID')
            ?: (string) Str::uuid();

        $user = $request->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication is required.',
                'request_id' => $requestId,
            ], 401);
        }

        try {
            /*
             * The authenticated user is the source of identity
             * for the payment operation.
             *
             * Never trust the frontend to supply:
             * - received_by
             * - processed_by
             * - verified_by
             * - payment_number
             * - transaction_reference
             * - cash_receipt_number
             * - payment status
             *
             * CashPaymentService must complete the payment,
             * update the invoice, generate the receipt, and
             * persist audit information transactionally.
             */
            $payment = $this->cashPaymentService->record(
                user: $user,
                data: $request->validated(),
                requestId: $requestId,
            );

            return response()->json([
                'success' => true,
                'message' => 'Cash payment completed successfully.',
                'data' => new PaymentResource($payment),
                'request_id' => $requestId,
            ], 201);
        } catch (ValidationException $e) {
            // Let Laravel return the standard validation response.
            throw $e;
        } catch (AuthorizationException $e) {
            // Preserve the standard authorization response.
            throw $e;
        } catch (HttpExceptionInterface $e) {
            // Preserve intentional HTTP errors raised by the service.
            throw $e;
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
                'message' => 'Unable to complete the cash payment.',
                'request_id' => $requestId,
            ], 500);
        }
    }
}
