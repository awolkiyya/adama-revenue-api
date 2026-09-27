<?php

namespace App\Modules\Assessment\Services;

use App\Models\Assessment;
use App\Models\AssessmentService;
use App\Models\Invoice;
use App\Modules\Invoice\Services\InvoiceIssuanceService;
use App\Modules\Invoice\Services\InvoiceService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class AssessmentInvoiceService
{
    public function __construct(
        protected InvoiceService $invoiceService,
        protected InvoiceIssuanceService $invoiceIssuanceService,
    ) {
    }

    /**
     * Create and issue one invoice containing the supplied
     * one-time assessment services.
     *
     * The supplied services must already have been classified
     * as immediately payable by the post-approval workflow.
     *
     * This service does not:
     *
     * - classify services
     * - resolve payment schedules
     * - calculate tariffs
     * - calculate penalties
     * - calculate interest
     * - recalculate assessment amounts
     */
    public function createAndIssueForAssessmentServices(
        Assessment $assessment,
        Collection $invoiceableServices,
    ): Invoice {
        $this->validateInput(
            $assessment,
            $invoiceableServices,
        );

        Log::info(
            'Creating assessment invoice.',
            [
                'assessment_id' => $assessment->id,
                'service_count' => $invoiceableServices->count(),
                'assessment_service_ids' => $invoiceableServices
                    ->pluck('id')
                    ->values()
                    ->all(),
            ]
        );

        try {
            /*
             * InvoiceService owns invoice construction.
             *
             * It receives only the services that are immediately
             * payable. Scheduled services must never reach this point.
             */
            $invoice = $this->invoiceService
                ->createFromAssessmentServices(
                    $assessment,
                    $invoiceableServices,
                );

            /*
             * Issuance is deliberately separate from creation.
             */
            $this->invoiceIssuanceService->issue($invoice);

            $invoice = $invoice->fresh([
                'items',
                'citizen',
                'assessment',
            ]);

            if (! $invoice) {
                throw new RuntimeException(
                    'Assessment invoice could not be reloaded after issuance.'
                );
            }

            Log::info(
                'Assessment invoice created and issued successfully.',
                [
                    'assessment_id' => $assessment->id,
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'status' => $invoice->status,
                    'item_count' => $invoice->items->count(),
                ]
            );

            return $invoice;
        } catch (Throwable $exception) {
            Log::error(
                'Assessment invoice creation failed.',
                [
                    'assessment_id' => $assessment->id,
                    'assessment_service_ids' => $invoiceableServices
                        ->pluck('id')
                        ->values()
                        ->all(),
                    'exception_class' => $exception::class,
                    'exception_message' => $exception->getMessage(),
                ]
            );

            throw $exception;
        }
    }

    /**
     * Determine whether an immediate invoice is required.
     */
    public function requiresInvoice(
        Collection $invoiceableServices,
    ): bool {
        return $invoiceableServices->isNotEmpty();
    }

    private function validateInput(
        Assessment $assessment,
        Collection $invoiceableServices,
    ): void {
        if ($invoiceableServices->isEmpty()) {
            throw new RuntimeException(
                'Cannot create an assessment invoice because no invoiceable services were supplied.'
            );
        }

        $invalidType = $invoiceableServices->first(
            fn ($service): bool =>
                ! $service instanceof AssessmentService
        );

        if ($invalidType) {
            throw new RuntimeException(
                'Assessment invoice services must contain only AssessmentService instances.'
            );
        }

        $belongsToAnotherAssessment = $invoiceableServices->contains(
            fn (AssessmentService $service): bool =>
                (string) $service->assessment_id !== (string) $assessment->id
        );

        if ($belongsToAnotherAssessment) {
            throw new RuntimeException(
                'One or more assessment services do not belong to the supplied assessment.'
            );
        }
    }
}