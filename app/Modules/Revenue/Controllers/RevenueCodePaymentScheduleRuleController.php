<?php

namespace App\Modules\Revenue\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Revenue\Requests\StoreRevenueCodePaymentScheduleRuleRequest;
use App\Modules\Revenue\Requests\UpdateRevenueCodePaymentScheduleRuleRequest;
use App\Modules\Revenue\Resources\RevenueCodePaymentScheduleRuleResource;
use App\Models\RevenueCodePaymentScheduleRule;
use App\Modules\Revenue\Services\RevenueCodePaymentScheduleRuleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;
use App\Services\ApiResponse;


class RevenueCodePaymentScheduleRuleController extends Controller
{
    public function __construct(
        protected RevenueCodePaymentScheduleRuleService $service
    ) {
    }

    /**
     * Display a paginated list of payment schedule rules.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $rules = $this->service->paginate(
                $request->only([
                    'search',
                    'is_enabled',
                    'revenue_code_id',
                    'has_first_installment_percentage',
                    'sort_by',
                    'sort_direction',
                    'per_page',
                ])
            );

            return ApiResponse::success(
                data: RevenueCodePaymentScheduleRuleResource::collection(
                    $rules
                ),
                message: 'Payment schedule rules retrieved successfully.'
            );
        } catch (Throwable $e) {
            Log::error(
                'Failed to retrieve payment schedule rules.',
                [
                    'exception' => $e,
                ]
            );

            return ApiResponse::serverError(
                'Failed to retrieve payment schedule rules.'
            );
        }
    }

    /**
     * Display payment schedule rule summary.
     */
    public function summary(): JsonResponse
    {
        try {
            return ApiResponse::success(
                data: $this->service->summary(),
                message: 'Payment schedule rule summary retrieved successfully.'
            );
        } catch (Throwable $e) {
            Log::error(
                'Failed to retrieve payment schedule rule summary.',
                [
                    'exception' => $e,
                ]
            );

            return ApiResponse::serverError(
                'Failed to retrieve payment schedule rule summary.'
            );
        }
    }

    /**
     * Store a newly created payment schedule rule.
     */
    public function store(
        StoreRevenueCodePaymentScheduleRuleRequest $request
    ): JsonResponse {
        try {
            $rule = $this->service->create(
                $request->validated()
            );

            return ApiResponse::created(
                data: new RevenueCodePaymentScheduleRuleResource(
                    $rule
                ),
                message: 'Payment schedule rule created successfully.'
            );
        } catch (Throwable $e) {
            Log::error(
                'Failed to create payment schedule rule.',
                [
                    'user_id' => $request->user()?->id,
                    'data' => $request->validated(),
                    'exception' => $e,
                ]
            );

            return ApiResponse::serverError(
                'Failed to create payment schedule rule.'
            );
        }
    }

    /**
     * Display a specific payment schedule rule.
     */
    public function show(
        RevenueCodePaymentScheduleRule $paymentScheduleRule
    ): JsonResponse {
        try {
            $rule = $this->service->find(
                $paymentScheduleRule->id
            );

            return ApiResponse::success(
                data: new RevenueCodePaymentScheduleRuleResource(
                    $rule
                ),
                message: 'Payment schedule rule retrieved successfully.'
            );
        } catch (Throwable $e) {
            Log::error(
                'Failed to retrieve payment schedule rule.',
                [
                    'payment_schedule_rule_id' =>
                        $paymentScheduleRule->id,
                    'exception' => $e,
                ]
            );

            return ApiResponse::serverError(
                'Failed to retrieve payment schedule rule.'
            );
        }
    }

    /**
     * Update a payment schedule rule.
     */
    public function update(
        UpdateRevenueCodePaymentScheduleRuleRequest $request,
        RevenueCodePaymentScheduleRule $paymentScheduleRule
    ): JsonResponse {
        try {
            $rule = $this->service->update(
                $paymentScheduleRule,
                $request->validated()
            );

            return ApiResponse::updated(
                data: new RevenueCodePaymentScheduleRuleResource(
                    $rule
                ),
                message: 'Payment schedule rule updated successfully.'
            );
        } catch (Throwable $e) {
            Log::error(
                'Failed to update payment schedule rule.',
                [
                    'user_id' => $request->user()?->id,
                    'payment_schedule_rule_id' =>
                        $paymentScheduleRule->id,
                    'data' => $request->validated(),
                    'exception' => $e,
                ]
            );

            return ApiResponse::serverError(
                'Failed to update payment schedule rule.'
            );
        }
    }

    /**
     * Activate a payment schedule rule.
     */
    public function activate(
        Request $request,
        RevenueCodePaymentScheduleRule $paymentScheduleRule
    ): JsonResponse {
        if (! $request->user()?->can(
            'payment_schedule_rules.activate'
        )) {
            return ApiResponse::forbidden(
                'You are not authorized to activate payment schedule rules.'
            );
        }

        try {
            $rule = $this->service->activate(
                $paymentScheduleRule
            );

            return ApiResponse::updated(
                data: new RevenueCodePaymentScheduleRuleResource(
                    $rule
                ),
                message: 'Payment schedule rule activated successfully.'
            );
        } catch (Throwable $e) {
            Log::error(
                'Failed to activate payment schedule rule.',
                [
                    'user_id' => $request->user()?->id,
                    'payment_schedule_rule_id' =>
                        $paymentScheduleRule->id,
                    'exception' => $e,
                ]
            );

            return ApiResponse::serverError(
                'Failed to activate payment schedule rule.'
            );
        }
    }

    /**
     * Deactivate a payment schedule rule.
     */
    public function deactivate(
        Request $request,
        RevenueCodePaymentScheduleRule $paymentScheduleRule
    ): JsonResponse {
        if (! $request->user()?->can(
            'payment_schedule_rules.deactivate'
        )) {
            return ApiResponse::forbidden(
                'You are not authorized to deactivate payment schedule rules.'
            );
        }

        try {
            $rule = $this->service->deactivate(
                $paymentScheduleRule
            );

            return ApiResponse::updated(
                data: new RevenueCodePaymentScheduleRuleResource(
                    $rule
                ),
                message: 'Payment schedule rule deactivated successfully.'
            );
        } catch (Throwable $e) {
            Log::error(
                'Failed to deactivate payment schedule rule.',
                [
                    'user_id' => $request->user()?->id,
                    'payment_schedule_rule_id' =>
                        $paymentScheduleRule->id,
                    'exception' => $e,
                ]
            );

            return ApiResponse::serverError(
                'Failed to deactivate payment schedule rule.'
            );
        }
    }
}