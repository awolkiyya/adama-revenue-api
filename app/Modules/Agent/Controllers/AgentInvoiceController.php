<?php

namespace App\Modules\Agent\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Agent\Requests\AgentPendingInvoiceRequest;
use App\Modules\Agent\Resources\AgentPendingInvoiceResource;
use App\Modules\Agent\Services\AgentInvoiceService;
use App\Services\ApiResponse;
use Illuminate\Http\JsonResponse;

class AgentInvoiceController extends Controller
{
    public function __construct(
        private readonly AgentInvoiceService $service,
    ) {}

    /**
     * Get pending invoices available for agent-assisted payment.
     *
     * Invoices are not assigned to agents.
     * The authenticated agent is identified only when
     * the actual payment is created.
     */
    public function pending(
        AgentPendingInvoiceRequest $request,
    ): JsonResponse {
        $invoices = $this->service->pending(
            perPage: $request->integer('per_page', 15),
            search: $request->string('search')->trim()->toString() ?: null,
        );

        return ApiResponse::success(
            data: AgentPendingInvoiceResource::collection($invoices),
            message: 'Pending invoices retrieved successfully.',
        );
    }
}