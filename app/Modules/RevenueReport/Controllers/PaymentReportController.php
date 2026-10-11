<?php

namespace App\Modules\RevenueReport\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\RevenueReport\Requests\PaymentReportIndexRequest;
use App\Modules\RevenueReport\Resources\PaymentReportResource;
use App\Models\Payment;
use App\Modules\RevenueReport\Services\PaymentReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PaymentReportController extends Controller
{
    public function __construct(
        private readonly PaymentReportService $paymentReportService
    ) {}

    /**
     * GET /api/v1/payment-reports
     *
     * Return a filtered, paginated payment report.
     */
    public function index(
        PaymentReportIndexRequest $request
    ): AnonymousResourceCollection {
        $payments = $this->paymentReportService->paginate(
            $request->filters()
        );

        return PaymentReportResource::collection($payments);
    }

    /**
     * GET /api/v1/payment-reports/summary
     *
     * Return aggregate payment report statistics.
     */
    public function summary(
        PaymentReportIndexRequest $request
    ): JsonResponse {
        return response()->json([
            'message' => 'Payment report summary retrieved successfully.',
            'data' => $this->paymentReportService->summary(
                $request->filters()
            ),
        ]);
    }

    /**
     * GET /api/v1/payment-reports/{payment}
     *
     * Return one payment report.
     */
    public function show(
        Payment $payment
    ): PaymentReportResource {
        $payment = $this->paymentReportService->findById(
            $payment->getKey()
        );

        return new PaymentReportResource($payment);
    }
}
