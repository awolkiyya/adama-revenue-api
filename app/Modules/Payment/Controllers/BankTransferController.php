<?php

namespace App\Modules\Payment\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payment\Requests\BankTransferPaymentRequest;
use App\Modules\Payment\Requests\RejectBankTransferRequest;
use App\Modules\Payment\Requests\VerifyBankTransferRequest;
use App\Modules\Payment\Resources\PaymentResource;
use App\Modules\Payment\Services\BankTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class BankTransferController extends Controller
{
    public function __construct(
        protected BankTransferService $bankTransferService,
    ) {}

    /**
     * Submit a bank transfer payment for verification.
     *
     * POST /api/bank-transfers
     *
     * Workflow:
     *
     *     Bank transfer
     *          ↓
     *     Create payment
     *          ↓
     *     PENDING_VERIFICATION
     *
     * The payment is NOT posted at this stage.
     *
     * The authenticated user is recorded as the user who
     * submitted/recorded the payment.
     */
    public function store(
        BankTransferPaymentRequest $request
    ): JsonResponse {
        $requestId = $this->requestId($request);

        try {
            $user = $request->user();

            if (!$user) {
                return $this->unauthenticatedResponse(
                    $requestId
                );
            }

            $payment = $this->bankTransferService->record(
                user: $user,
                data: $request->validated(),
                requestId: $requestId,
            );

            return response()->json([
                'success' => true,
                'message' =>
                    'Bank transfer payment submitted successfully and is pending verification.',
                'data' => new PaymentResource($payment),
                'request_id' => $requestId,
            ], 201);

        } catch (Throwable $e) {

            Log::error(
                'Failed to submit bank transfer payment.',
                [
                    'request_id' => $requestId,
                    'user_id' => $request->user()?->id,
                    'invoice_id' => $request->input('invoice_id'),
                    'transfer_reference' =>
                        $request->input('transfer_reference'),
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]
            );

            return $this->serverErrorResponse(
                message:
                    'Unable to submit the bank transfer payment.',
                requestId:
                    $requestId,
            );
        }
    }

    /**
     * Verify a bank transfer payment.
     *
     * POST /api/bank-transfers/{payment}/verify
     *
     * Workflow:
     *
     *     PENDING_VERIFICATION
     *             ↓
     *       Revenue Officer
     *             ↓
     *       Verify bank evidence
     *             ↓
     *          VERIFIED
     *             ↓
     *           POSTED
     *             ↓
     *       Invoice updated
     *             ↓
     *       Receipt generated
     *
     * IMPORTANT:
     *
     * The payment ID comes from the route.
     *
     * It must NOT be supplied in the request body.
     *
     * The service is responsible for:
     *
     * 1. Authorizing the verifying user.
     * 2. Locking the payment.
     * 3. Confirming it is a bank transfer.
     * 4. Confirming it is PENDING_VERIFICATION.
     * 5. Verifying the bank evidence.
     * 6. Marking it VERIFIED.
     * 7. Posting the payment.
     * 8. Updating the invoice.
     * 9. Updating the payment schedule if applicable.
     * 10. Generating the official payment/receipt numbers.
     */
    public function verify(
        VerifyBankTransferRequest $request,
        string $payment
    ): JsonResponse {
        $requestId = $this->requestId($request);

        try {
            $user = $request->user();

            if (!$user) {
                return $this->unauthenticatedResponse(
                    $requestId
                );
            }

            $paymentModel =
                $this->bankTransferService->verify(
                    paymentId:
                        $payment,

                    user:
                        $user,

                    verificationNotes:
                        $request->validated(
                            'verification_notes'
                        ),

                    requestId:
                        $requestId,
                );

            return response()->json([
                'success' => true,
                'message' =>
                    'Bank transfer payment verified and posted successfully.',
                'data' =>
                    new PaymentResource(
                        $paymentModel
                    ),
                'request_id' => $requestId,
            ]);

        } catch (Throwable $e) {

            Log::error(
                'Failed to verify bank transfer payment.',
                [
                    'request_id' => $requestId,
                    'user_id' => $request->user()?->id,
                    'payment_id' => $payment,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]
            );

            return $this->serverErrorResponse(
                message:
                    'Unable to verify the bank transfer payment.',
                requestId:
                    $requestId,
            );
        }
    }

    /**
     * Reject a bank transfer payment.
     *
     * POST /api/bank-transfers/{payment}/reject
     *
     * Workflow:
     *
     *     PENDING_VERIFICATION
     *             ↓
     *       Revenue Officer
     *             ↓
     *           REJECTED
     *
     * Rejection does NOT delete the payment.
     *
     * The rejected payment remains in the financial
     * audit trail.
     */
    public function reject(
        RejectBankTransferRequest $request,
        string $payment
    ): JsonResponse {
        $requestId = $this->requestId($request);

        try {
            $user = $request->user();

            if (!$user) {
                return $this->unauthenticatedResponse(
                    $requestId
                );
            }

            $rejectedPayment =
                $this->bankTransferService->reject(
                    paymentId:
                        $payment,

                    user:
                        $user,

                    reason:
                        $request->validated('reason'),

                    requestId:
                        $requestId,
                );

            return response()->json([
                'success' => true,
                'message' =>
                    'Bank transfer payment has been rejected.',
                'data' =>
                    new PaymentResource(
                        $rejectedPayment
                    ),
                'request_id' => $requestId,
            ]);

        } catch (Throwable $e) {

            Log::error(
                'Failed to reject bank transfer payment.',
                [
                    'request_id' => $requestId,
                    'user_id' => $request->user()?->id,
                    'payment_id' => $payment,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]
            );

            return $this->serverErrorResponse(
                message:
                    'Unable to reject the bank transfer payment.',
                requestId:
                    $requestId,
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Request ID
    |--------------------------------------------------------------------------
    */

    protected function requestId(
        Request $request
    ): string {
        return $request->header('X-Request-ID')
            ?: (string) Str::uuid();
    }

    /*
    |--------------------------------------------------------------------------
    | Authentication Response
    |--------------------------------------------------------------------------
    */

    protected function unauthenticatedResponse(
        string $requestId
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => 'Authentication is required.',
            'request_id' => $requestId,
        ], 401);
    }

    /*
    |--------------------------------------------------------------------------
    | Server Error Response
    |--------------------------------------------------------------------------
    */

    protected function serverErrorResponse(
        string $message,
        string $requestId
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => $message,
            'request_id' => $requestId,
        ], 500);
    }
}