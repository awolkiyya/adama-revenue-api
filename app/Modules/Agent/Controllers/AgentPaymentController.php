<?php

namespace App\Modules\Agent\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Agent\Requests\AgentPaymentIndexRequest;
use App\Modules\Agent\Services\AgentPaymentService;
use App\Modules\Payment\Resources\PaymentResource;
use App\Services\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentPaymentController extends Controller
{
    public function __construct(
        private readonly AgentPaymentService $service,
    ) {}

    /**
     * List payments processed by the authenticated agent.
     */
    public function index(
        AgentPaymentIndexRequest $request,
    ): JsonResponse {
        /** @var User|null $agent */
        $agent = $request->user();

        $payments = $this->service->paginate(
            agent: $agent,
            perPage: $request->integer('per_page', 15),
            search: $request->string('search')->trim()->toString() ?: null,
            status: $request->validated('status'),
        );

        return ApiResponse::success(
            data: PaymentResource::collection($payments),
            message: 'Agent payments retrieved successfully.',
        );
    }

    /**
     * Return dashboard statistics and recent payments.
     */
    public function dashboard(Request $request): JsonResponse
    {
        /** @var User|null $agent */
        $agent = $request->user();

        $recentLimit = $request->integer('recent_limit', 5);
        $recentLimit = max(1, min($recentLimit, 20));

        $dashboard = $this->service->dashboard(
            agent: $agent,
            recentLimit: $recentLimit,
        );

        $dashboard['recent_payments'] = PaymentResource::collection(
            $dashboard['recent_payments'],
        )->resolve($request);

        return ApiResponse::success(
            data: $dashboard,
            message: 'Agent dashboard retrieved successfully.',
        );
    }

    /**
     * Return all-time payment statistics for the authenticated agent.
     */
    public function summary(Request $request): JsonResponse
    {
        /** @var User|null $agent */
        $agent = $request->user();

        return ApiResponse::success(
            data: $this->service->summary($agent),
            message: 'Agent payment summary retrieved successfully.',
        );
    }

    /**
     * Return today's payment statistics for the authenticated agent.
     */
    public function todaySummary(Request $request): JsonResponse
    {
        /** @var User|null $agent */
        $agent = $request->user();

        return ApiResponse::success(
            data: $this->service->todaySummary($agent),
            message: "Today's agent payment summary retrieved successfully.",
        );
    }
}