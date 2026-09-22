<?php

declare(strict_types=1);

namespace App\Modules\DirectCollection\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\DirectCollection\Requests\CalculateDirectCollectionRequest;
use App\Modules\DirectCollection\Requests\StoreDirectCollectionRequest;
use App\Modules\DirectCollection\Requests\UpdateDirectCollectionRequest;
use App\Modules\DirectCollection\Resources\DirectCollectionResource;
use App\Modules\DirectCollection\Services\DirectCollectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class DirectCollectionController extends Controller
{
    public function __construct(
        protected DirectCollectionService $directCollectionService,
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | CALCULATE
    |--------------------------------------------------------------------------
    |
    | POST /api/v1/direct-collections/calculate
    |
    | Calculates the amount without creating an invoice.
    |
    |--------------------------------------------------------------------------
    */

    public function calculate(
        CalculateDirectCollectionRequest $request
    ): JsonResponse {
        try {
            $calculation =
                $this->directCollectionService->calculateAmount(
                    $request->validated()
                );

            return response()->json([
                'success' => true,

                'message' =>
                    'Direct collection amount calculated successfully.',

                'data' => $calculation,
            ], 200);

        } catch (ValidationException $exception) {
            throw $exception;

        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,

                'message' =>
                    'Unable to calculate the direct collection amount.',

                'error' =>
                    config('app.debug')
                        ? $exception->getMessage()
                        : null,
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | STORE
    |--------------------------------------------------------------------------
    |
    | POST /api/v1/direct-collections
    |
    | Server-side calculation
    |        ↓
    | Create DRAFT invoice
    |        ↓
    | Create InvoiceItem
    |        ↓
    | InvoiceIssuanceService
    |        ↓
    | ISSUED invoice
    |
    |--------------------------------------------------------------------------
    */

    public function store(
        StoreDirectCollectionRequest $request
    ): JsonResponse {
        try {
            $invoice =
                $this->directCollectionService->create(
                    $request->validated()
                );

            return response()->json([
                'success' => true,

                'message' =>
                    'Direct collection invoice created and issued successfully.',

                'data' =>
                    new DirectCollectionResource(
                        $invoice
                    ),
            ], 201);

        } catch (ValidationException $exception) {
            throw $exception;

        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,

                'message' =>
                    'Unable to create the direct collection.',

                'error' =>
                    config('app.debug')
                        ? $exception->getMessage()
                        : null,
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | UPDATE
    |--------------------------------------------------------------------------
    |
    | PUT /api/v1/direct-collections/{invoice}
    |
    | Only ISSUED / pending-payment invoices can be edited.
    |
    | PARTIALLY_PAID
    | PAID
    | CANCELLED
    |
    | cannot be edited.
    |
    | The new amount is NEVER trusted from the client.
    | The service recalculates the amount from the submitted fields.
    |
    |--------------------------------------------------------------------------
    */

    public function update(
        UpdateDirectCollectionRequest $request,
        string $invoice
    ): JsonResponse {
        try {
            $updatedInvoice =
                $this->directCollectionService->update(
                    $invoice,
                    $request->validated()
                );

            return response()->json([
                'success' => true,

                'message' =>
                    'Direct collection updated successfully.',

                'data' =>
                    new DirectCollectionResource(
                        $updatedInvoice
                    ),
            ], 200);

        } catch (ValidationException $exception) {
            throw $exception;

        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,

                'message' =>
                    'Unable to update the direct collection.',

                'error' =>
                    config('app.debug')
                        ? $exception->getMessage()
                        : null,
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | INDEX
    |--------------------------------------------------------------------------
    |
    | GET /api/v1/direct-collections
    |
    | Returns paginated direct collection invoices.
    |
    |--------------------------------------------------------------------------
    */

    public function index(
        Request $request
    ): JsonResponse {
        try {
            $result =
                $this->directCollectionService->paginate(
                    $request->all()
                );

            return response()->json([
                'success' => true,

                'message' =>
                    'Direct collections retrieved successfully.',

                'data' =>
                    DirectCollectionResource::collection(
                        $result->items()
                    ),

                'meta' => [
                    'current_page' =>
                        $result->currentPage(),

                    'per_page' =>
                        $result->perPage(),

                    'last_page' =>
                        $result->lastPage(),

                    'total' =>
                        $result->total(),

                    'from' =>
                        $result->firstItem(),

                    'to' =>
                        $result->lastItem(),
                ],
            ], 200);

        } catch (ValidationException $exception) {
            throw $exception;

        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,

                'message' =>
                    'Unable to retrieve direct collections.',

                'error' =>
                    config('app.debug')
                        ? $exception->getMessage()
                        : null,
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | SHOW
    |--------------------------------------------------------------------------
    |
    | GET /api/v1/direct-collections/{invoice}
    |
    | Returns one direct collection in detail.
    |
    |--------------------------------------------------------------------------
    */

    public function show(
        string $invoice
    ): JsonResponse {
        try {
            $directCollection =
                $this->directCollectionService->find(
                    $invoice
                );

            return response()->json([
                'success' => true,

                'message' =>
                    'Direct collection retrieved successfully.',

                'data' =>
                    new DirectCollectionResource(
                        $directCollection
                    ),
            ], 200);

        } catch (ValidationException $exception) {
            throw $exception;

        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,

                'message' =>
                    'Unable to retrieve the direct collection.',

                'error' =>
                    config('app.debug')
                        ? $exception->getMessage()
                        : null,
            ], 500);
        }
    }
}