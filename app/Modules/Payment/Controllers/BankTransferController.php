<?php

namespace App\Modules\Payment\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payment\Requests\BankTransferPaymentRequest;
use App\Modules\Payment\Requests\RejectBankTransferRequest;
use App\Modules\Payment\Requests\VerifyBankTransferRequest;
use App\Modules\Payment\Resources\PaymentResource;
use App\Modules\Payment\Services\BankTransferService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

class BankTransferController extends Controller
{
    public function __construct(
        protected BankTransferService $bankTransferService,
    ) {}

    /**
     * Submit a bank transfer payment.
     *
     * POST /api/v1/bank-transfers
     *
     * Workflow:
     *
     *     Bank Transfer Submitted
     *              ↓
     *           PENDING
     *              ↓
     *      Waiting for Verification
     *
     * The payment is NOT financially completed
     * until an authorized officer verifies it.
     */
    public function store(
        BankTransferPaymentRequest $request
    ): JsonResponse {
        $requestId = $this->requestId($request);

        try {
            $user = $request->user();

            if (!$user) {
                return $this->unauthenticatedResponse($requestId);
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

        } catch (ValidationException $e) {

            return $this->validationErrorResponse(
                exception: $e,
                requestId: $requestId,
            );

        } catch (AuthorizationException $e) {

            return $this->authorizationErrorResponse(
                message: $e->getMessage() ?: 'You are not authorized to submit this payment.',
                requestId: $requestId,
            );

        } catch (ModelNotFoundException $e) {

            return $this->notFoundResponse(
                message: 'The requested payment or invoice was not found.',
                requestId: $requestId,
            );

        } catch (ConflictHttpException $e) {

            return $this->conflictResponse(
                message: $e->getMessage() ?: 'The payment could not be processed because of a conflict.',
                requestId: $requestId,
            );

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
     * POST /api/v1/bank-transfers/{payment}/verify
     *
     * Workflow:
     *
     *     PENDING
     *        ↓
     *   Officer Verification
     *        ↓
     *    COMPLETED
     *        ↓
     *   Invoice Updated
     *        ↓
     *   Receipt Generated
     *
     * Verification and financial completion are
     * one controlled business operation.
     */
    public function verify(
        VerifyBankTransferRequest $request,
        string $payment
    ): JsonResponse {
        $requestId = $this->requestId($request);

        try {
            $user = $request->user();

            if (!$user) {
                return $this->unauthenticatedResponse($requestId);
            }

            $paymentModel = $this->bankTransferService->verify(
                paymentId: $payment,
                user: $user,
                verificationNotes: $request->validated(
                    'verification_notes'
                ),
                requestId: $requestId,
            );

            return response()->json([
                'success' => true,
                'message' =>
                    'Bank transfer payment verified and completed successfully.',
                'data' => new PaymentResource($paymentModel),
                'request_id' => $requestId,
            ]);

        } catch (ValidationException $e) {

            return $this->validationErrorResponse(
                exception: $e,
                requestId: $requestId,
            );

        } catch (AuthorizationException $e) {

            return $this->authorizationErrorResponse(
                message: $e->getMessage() ?: 'You are not authorized to verify this payment.',
                requestId: $requestId,
            );

        } catch (ModelNotFoundException $e) {

            return $this->notFoundResponse(
                message: 'The requested bank transfer payment was not found.',
                requestId: $requestId,
            );

        } catch (ConflictHttpException $e) {

            return $this->conflictResponse(
                message: $e->getMessage() ?: 'The payment cannot be verified in its current state.',
                requestId: $requestId,
            );

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
     * POST /api/v1/bank-transfers/{payment}/reject
     *
     * Workflow:
     *
     *     PENDING
     *        ↓
     *   Officer Review
     *        ↓
     *      FAILED
     *
     * Rejection does not delete the payment.
     *
     * The failed payment remains in the financial
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
                return $this->unauthenticatedResponse($requestId);
            }

            $rejectedPayment = $this->bankTransferService->reject(
                paymentId: $payment,
                user: $user,
                reason: $request->validated('reason'),
                requestId: $requestId,
            );

            return response()->json([
                'success' => true,
                'message' =>
                    'Bank transfer payment has been rejected and marked as failed.',
                'data' => new PaymentResource($rejectedPayment),
                'request_id' => $requestId,
            ]);

        } catch (ValidationException $e) {

            return $this->validationErrorResponse(
                exception: $e,
                requestId: $requestId,
            );

        } catch (AuthorizationException $e) {

            return $this->authorizationErrorResponse(
                message: $e->getMessage() ?: 'You are not authorized to reject this payment.',
                requestId: $requestId,
            );

        } catch (ModelNotFoundException $e) {

            return $this->notFoundResponse(
                message: 'The requested bank transfer payment was not found.',
                requestId: $requestId,
            );

        } catch (ConflictHttpException $e) {

            return $this->conflictResponse(
                message: $e->getMessage() ?: 'The payment cannot be rejected in its current state.',
                requestId: $requestId,
            );

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
    | Response Helpers
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

    protected function validationErrorResponse(
        ValidationException $exception,
        string $requestId
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => 'The request could not be processed.',
            'errors' => $exception->errors(),
            'request_id' => $requestId,
        ], 422);
    }

    protected function authorizationErrorResponse(
        string $message,
        string $requestId
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => $message,
            'request_id' => $requestId,
        ], 403);
    }

    protected function notFoundResponse(
        string $message,
        string $requestId
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => $message,
            'request_id' => $requestId,
        ], 404);
    }

    protected function conflictResponse(
        string $message,
        string $requestId
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => $message,
            'request_id' => $requestId,
        ], 409);
    }

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

