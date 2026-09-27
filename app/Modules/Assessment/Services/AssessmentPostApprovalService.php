<?php

namespace App\Modules\Assessment\Services;

use App\Models\Assessment;
use App\Models\AssessmentService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class AssessmentPostApprovalService
{
    public function __construct(
        protected PaymentScheduleService $paymentScheduleService,
        protected AssessmentInvoiceService $assessmentInvoiceService,
    ) {
    }

    /**
     * Process all assessment services after assessment approval.
     *
     * Each assessment service is classified independently:
     *
     *     Scheduled → payment schedules
     *     One-time  → collected for one consolidated invoice
     *
     * Important:
     *
     * - Scheduled services are handled by PaymentScheduleService.
     * - One-time services are NOT invoiced individually.
     * - All one-time services belonging to this assessment are
     *   consolidated into ONE invoice.
     * - This service does not calculate tariffs.
     * - This service does not calculate penalties.
     * - This service does not calculate interest.
     */
    public function process(
        Assessment $assessment,
    ): void {
        $assessment->loadMissing([
            'services.service.revenueCode.paymentScheduleRule',
        ]);

        Log::info(
            'Assessment post-approval processing started.',
            [
                'assessment_id' => $assessment->id,
                'assessment_status' => $assessment->status,
                'service_count' => $assessment->services->count(),
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Collect services requiring immediate invoicing.
        |--------------------------------------------------------------------------
        |
        | Scheduled services are processed immediately and are NOT added here.
        |
        */

        $invoiceableServices = collect();

        /*
        |--------------------------------------------------------------------------
        | Classify and process every assessment service.
        |--------------------------------------------------------------------------
        */

        foreach ($assessment->services as $assessmentService) {
            $requiresSchedule = $this->processAssessmentService(
                $assessmentService,
            );

            if (! $requiresSchedule) {
                $invoiceableServices->push($assessmentService);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Create ONE consolidated invoice for all one-time services.
        |--------------------------------------------------------------------------
        */

        if ($invoiceableServices->isNotEmpty()) {
            $invoice = $this->assessmentInvoiceService
                ->createAndIssueForAssessmentServices(
                    $assessment,
                    $invoiceableServices,
                );

            Log::info(
                'Consolidated assessment invoice created and issued.',
                [
                    'assessment_id' => $assessment->id,
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'invoiceable_service_count' =>
                        $invoiceableServices->count(),
                    'assessment_service_ids' =>
                        $invoiceableServices
                            ->pluck('id')
                            ->values()
                            ->all(),
                ]
            );
        }

        Log::info(
            'Assessment post-approval processing completed.',
            [
                'assessment_id' => $assessment->id,
                'service_count' => $assessment->services->count(),
                'invoiceable_service_count' =>
                    $invoiceableServices->count(),
            ]
        );
    }

    /**
     * Classify and process one assessment service.
     *
     * Returns:
     *
     *     true  = scheduled
     *     false = one-time / immediately invoiceable
     *
     * The classification is based on:
     *
     *     AssessmentService
     *         → RevenueService
     *         → RevenueCode
     *         → PaymentScheduleRule
     */
    protected function processAssessmentService(
        AssessmentService $assessmentService,
    ): bool {
        /*
        |--------------------------------------------------------------------------
        | Resolve Revenue Service
        |--------------------------------------------------------------------------
        |
        | AssessmentService defines the relationship as:
        |
        |     service()
        |
        | NOT:
        |
        |     revenueService()
        |
        */

        $revenueService = $assessmentService->service;

        if (! $revenueService) {
            throw new RuntimeException(
                sprintf(
                    'Revenue service not found for assessment service [%s].',
                    $assessmentService->id,
                )
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Resolve Revenue Code
        |--------------------------------------------------------------------------
        */

        $revenueCode = $revenueService->revenueCode;

        if (! $revenueCode) {
            throw new RuntimeException(
                sprintf(
                    'Revenue code not found for revenue service [%s] while processing assessment service [%s].',
                    $revenueService->id,
                    $assessmentService->id,
                )
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Resolve Payment Schedule Rule
        |--------------------------------------------------------------------------
        |
        | No rule means:
        |
        |     one-time / immediately invoiceable
        |
        | An existing rule with is_enabled = true means:
        |
        |     scheduled
        |
        */

        $paymentScheduleRule = $revenueCode->paymentScheduleRule;

        $usesPaymentSchedule = (bool) (
            $paymentScheduleRule?->is_enabled
        );

        Log::info(
            'Assessment service post-approval workflow determined.',
            [
                'assessment_service_id' =>
                    $assessmentService->id,

                'assessment_id' =>
                    $assessmentService->assessment_id,

                'revenue_service_id' =>
                    $revenueService->id,

                'revenue_code_id' =>
                    $revenueCode->id,

                'revenue_code' =>
                    $revenueCode->code,

                'payment_schedule_rule_id' =>
                    $paymentScheduleRule?->id,

                'payment_schedule_enabled' =>
                    $usesPaymentSchedule,
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Scheduled Service
        |--------------------------------------------------------------------------
        */

        if ($usesPaymentSchedule) {
            $this->processScheduled(
                $assessmentService,
            );

            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | One-Time Service
        |--------------------------------------------------------------------------
        |
        | Do NOT create an invoice here.
        |
        | The caller collects all one-time services and creates
        | one consolidated invoice after the classification loop.
        |
        */

        Log::info(
            'Assessment service classified as one-time invoiceable service.',
            [
                'assessment_service_id' =>
                    $assessmentService->id,

                'assessment_id' =>
                    $assessmentService->assessment_id,
            ]
        );

        return false;
    }

    /**
     * Create payment schedules for a scheduled assessment service.
     */
    protected function processScheduled(
        AssessmentService $assessmentService,
    ): void {
        Log::info(
            'Processing scheduled assessment service.',
            [
                'assessment_service_id' =>
                    $assessmentService->id,

                'assessment_id' =>
                    $assessmentService->assessment_id,
            ]
        );

        $paymentSchedules = $this->paymentScheduleService
            ->createForAssessmentService(
                $assessmentService,
            );

        Log::info(
            'Scheduled assessment service payment schedule workflow completed.',
            [
                'assessment_service_id' =>
                    $assessmentService->id,

                'assessment_id' =>
                    $assessmentService->assessment_id,

                'schedule_count' =>
                    $paymentSchedules->count(),

                'schedule_ids' =>
                    $paymentSchedules
                        ->pluck('id')
                        ->values()
                        ->all(),
            ]
        );
    }
}