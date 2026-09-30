<?php

namespace App\Modules\Invoice\Controllers;

use App\Modules\Invoice\Requests\InvoiceIndexRequest;
use App\Modules\Invoice\Resources\InvoiceResource;
use App\Modules\Invoice\Services\InvoiceService;
use App\Services\ApiResponse;
use Illuminate\Http\JsonResponse;
use Throwable;
use App\Modules\Invoice\Resources\InvoiceDetailResource;
class InvoiceController
{
    /*
    |--------------------------------------------------------------------------
    | CONSTRUCTOR
    |--------------------------------------------------------------------------
    */

    public function __construct(
        protected InvoiceService $invoiceService,
    ) {
    }


    /*
    |--------------------------------------------------------------------------
    | INDEX
    |--------------------------------------------------------------------------
    |
    | Return paginated invoices together with a filtered invoice summary.
    |
    | The InvoiceResource controls the shape of each invoice returned
    | to the client.
    |
    | The InvoiceService controls:
    |
    | - filtering
    | - pagination
    | - summary aggregation
    |
    |--------------------------------------------------------------------------
    */

    public function index(
        InvoiceIndexRequest $request
    ): JsonResponse {

        try {

            /*
            |--------------------------------------------------------------------------
            | VALIDATED REQUEST
            |--------------------------------------------------------------------------
            */

            $validated = $request->validated();


            /*
            |--------------------------------------------------------------------------
            | PAGINATION
            |--------------------------------------------------------------------------
            */

            $perPage = (int) (
                $validated['per_page'] ?? 20
            );


            /*
            |--------------------------------------------------------------------------
            | REMOVE PAGINATION PARAMETERS
            |--------------------------------------------------------------------------
            */

            unset(
                $validated['page'],
                $validated['per_page'],
            );


            /*
            |--------------------------------------------------------------------------
            | PAGINATED INVOICES
            |--------------------------------------------------------------------------
            */

            $invoices = $this->invoiceService->paginate(
                filters: $validated,
                perPage: $perPage,
            );


            /*
            |--------------------------------------------------------------------------
            | INVOICE SUMMARY
            |--------------------------------------------------------------------------
            |
            | Uses exactly the same filters as the invoice list.
            |
            */

            $summary = $this->invoiceService->summary(
                filters: $validated,
            );


            /*
            |--------------------------------------------------------------------------
            | INVOICE RESOURCE
            |--------------------------------------------------------------------------
            |
            | Transform each invoice through InvoiceResource.
            |
            | Because $invoices is a LengthAwarePaginator,
            | InvoiceResource::collection() preserves pagination
            | information.
            |
            */

            $resource = InvoiceResource::collection(
                $invoices
            );


            /*
            |--------------------------------------------------------------------------
            | RESPONSE
            |--------------------------------------------------------------------------
            |
            | ApiResponse will:
            |
            | - normalize the ResourceCollection
            | - extract pagination metadata
            | - add summary to meta.summary
            |
            |--------------------------------------------------------------------------
            */

            return ApiResponse::success(
                data: $resource,
                message: 'Invoices retrieved successfully.',
                summary: $summary,
            );

        } catch (Throwable $e) {

            /*
            |--------------------------------------------------------------------------
            | ERROR HANDLING
            |--------------------------------------------------------------------------
            */

            report($e);

            return ApiResponse::serverError(
                message: 'Failed to retrieve invoices.',
                exception: $e,
            );
        }
    }


    /*
    |--------------------------------------------------------------------------
    | SHOW
    |--------------------------------------------------------------------------
    |
    | Return a single invoice with its complete detail information.
    |
    | The InvoiceService controls:
    |
    | - invoice lookup
    | - related data loading
    | - invoice detail preparation
    |
    | The InvoiceDetailResource controls the response shape.
    |
    |--------------------------------------------------------------------------
    */

    public function show(string $id): JsonResponse
    {
        try {

            /*
            |--------------------------------------------------------------------------
            | INVOICE DETAILS
            |--------------------------------------------------------------------------
            */

            $invoice = $this->invoiceService->findForDetails(
                id: $id,
            );


            /*
            |--------------------------------------------------------------------------
            | NOT FOUND
            |--------------------------------------------------------------------------
            */

            if (!$invoice) {
                return ApiResponse::notFound(
                    message: 'Invoice not found.',
                );
            }


            /*
            |--------------------------------------------------------------------------
            | RESPONSE
            |--------------------------------------------------------------------------
            |
            | Transform the invoice through InvoiceDetailResource
            | before returning it to the client.
            |
            */

            return ApiResponse::success(
                data: new InvoiceDetailResource($invoice),
                message: 'Invoice retrieved successfully.',
            );

        } catch (Throwable $e) {

            /*
            |--------------------------------------------------------------------------
            | ERROR HANDLING
            |--------------------------------------------------------------------------
            */

            report($e);

            return ApiResponse::serverError(
                message: 'Failed to retrieve invoice.',
                exception: $e,
            );
        }
    }
}