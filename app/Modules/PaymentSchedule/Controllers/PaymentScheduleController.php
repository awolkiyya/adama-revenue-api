<?php

namespace App\Modules\PaymentSchedule\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\PaymentSchedule\Requests\CreateInvoiceFromPaymentSchedulesRequest;
use App\Modules\PaymentSchedule\Resources\PaymentScheduleResource;
use App\Modules\PaymentSchedule\Services\PaymentScheduleManagementService;
use Illuminate\Http\JsonResponse;

class PaymentScheduleController extends Controller
{
    public function __construct(
        protected PaymentScheduleManagementService $managementService,
    ) {
    }

    /**
     * Display the payment schedule for an assessment service.
     */
    public function show(
        string $assessmentServiceId
    ): JsonResponse {
        // $this->authorize(
        //     'viewAny',
        //     \App\Models\PaymentSchedule::class
        // );

        $result = $this->managementService
            ->getScheduleContext($assessmentServiceId);

        return response()->json([
            'data' => [
                'assessmentService' => [
                    'id' =>
                        $result['assessmentService']->id,

                    'assessmentId' =>
                        $result['assessmentService']->assessment_id,

                    'serviceId' =>
                        $result['assessmentService']->service_id,

                    'serviceName' =>
                        $result['assessmentService']->service?->name,

                    'revenueCode' =>
                        $result['assessmentService']
                            ->service
                            ?->revenueCode
                            ?->code,
                ],

                'schedules' =>
                    PaymentScheduleResource::collection(
                        $result['schedules']
                    ),
            ],
        ]);
    }

    /**
     * Create an invoice from selected payment schedules.
     */
    public function createInvoice(
        CreateInvoiceFromPaymentSchedulesRequest $request,
        string $assessmentServiceId
    ): JsonResponse {
        $invoice = $this->managementService
            ->createInvoiceFromPaymentSchedules(
                $assessmentServiceId,
                $request->paymentScheduleIds(),
            );

        return response()->json([
            'message' => 'Invoice created successfully.',

            'data' => [
                'invoice' => $invoice,
            ],
        ], 201);
    }
}
