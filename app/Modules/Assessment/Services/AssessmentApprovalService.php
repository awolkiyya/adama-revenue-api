<?php

namespace App\Modules\Assessment\Services;

use App\Models\Assessment;
use App\Modules\Invoice\Services\InvoiceIssuanceService;
use App\Modules\Invoice\Services\InvoiceService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class AssessmentApprovalService
{
    public function __construct(
        protected InvoiceService $invoiceService,
        protected InvoiceIssuanceService $invoiceIssuanceService,
        protected PaymentScheduleService $paymentScheduleService,
    ) {
    }

    /**
     * Approve an assessment and complete its post-approval workflow.
     *
     * Business flow:
     *
     * PENDING_APPROVAL
     *        ↓
     *     APPROVED
     *        ↓
     * Create applicable payment schedules
     *        ↓
     * Create Invoice
     *        ↓
     * Issue Invoice
     *
     * Responsibilities:
     *
     * AssessmentApprovalService
     *     - validates approval state
     *     - records approval decision
     *     - records decision officer
     *     - records approval timestamp
     *     - orchestrates payment schedule creation
     *     - orchestrates invoice creation
     *     - orchestrates invoice issuance
     *
     * PaymentScheduleService
     *     - manages payment schedules only
     *
     * InvoiceService
     *     - creates invoice and invoice items
     *
     * InvoiceIssuanceService
     *     - issues the invoice
     *
     * No tariff, penalty, or interest calculation is performed here.
     *
     * @throws ValidationException
     * @throws Throwable
     */
    public function approve(
        string $assessmentId
    ): Assessment {
        Log::info(
            'Assessment approval workflow started.',
            [
                'assessment_id' => $assessmentId,
            ]
        );

        try {
            return DB::transaction(
                function () use ($assessmentId): Assessment {
                    /*
                    |--------------------------------------------------------------------------
                    | 1. LOAD + LOCK ASSESSMENT
                    |--------------------------------------------------------------------------
                    */

                    $assessment = Assessment::query()
                        ->with([
                            'services',
                            'citizen',
                        ])
                        ->lockForUpdate()
                        ->findOrFail($assessmentId);

                    $logContext = [
                        'assessment_id' => $assessment->id,
                        'assessment_status' => $assessment->status,
                        'service_count' => $assessment->services->count(),
                    ];

                    Log::info(
                        'Assessment loaded and locked for approval.',
                        $logContext
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | 2. VALIDATE ASSESSMENT STATUS
                    |--------------------------------------------------------------------------
                    */

                    if (!$assessment->isPendingApproval()) {
                        Log::warning(
                            'Assessment approval rejected because assessment is not pending approval.',
                            [
                                ...$logContext,
                                'current_status' => $assessment->status,
                                'required_status' => 'PENDING_APPROVAL',
                            ]
                        );

                        throw ValidationException::withMessages([
                            'status' => [
                                'Only assessments pending approval can be approved.',
                            ],
                        ]);
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | 3. GET AUTHENTICATED USER
                    |--------------------------------------------------------------------------
                    */

                    $userId = Auth::id();

                    if (!$userId) {
                        Log::warning(
                            'Assessment approval rejected because no authenticated user was found.',
                            $logContext
                        );

                        throw ValidationException::withMessages([
                            'authorization' => [
                                'An authenticated user is required to approve an assessment.',
                            ],
                        ]);
                    }

                    $logContext['approved_by'] = $userId;

                    Log::info(
                        'Assessment approval authorization validated.',
                        $logContext
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | 4. RECORD APPROVAL DECISION
                    |--------------------------------------------------------------------------
                    */

                    $now = now();

                    $assessment->update([
                        'status' => 'APPROVED',
                        'decision' => 'APPROVED',
                        'decision_notes' => null,
                        'decided_by' => $userId,
                        'decided_at' => $now,
                        'approved_at' => $now,
                        'rejected_at' => null,
                    ]);

                    Log::info(
                        'Assessment approved successfully.',
                        [
                            ...$logContext,
                            'approved_at' => $now->toISOString(),
                        ]
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | 5. CREATE PAYMENT SCHEDULES
                    |--------------------------------------------------------------------------
                    |
                    | PaymentScheduleService decides which assessment services
                    | require payment scheduling.
                    |
                    | Current rule:
                    |
                    |     LIZZ -> schedule
                    |     Other services -> skip
                    |
                    | The approval service does not inspect service codes.
                    |
                    */

                    Log::info(
                        'Starting payment schedule workflow.',
                        [
                            ...$logContext,
                            'workflow' => 'payment_schedule_creation',
                        ]
                    );

                    $paymentSchedules = $this->paymentScheduleService
                        ->createForAssessment(
                            $assessment
                        );

                    Log::info(
                        'Payment schedule workflow completed.',
                        [
                            ...$logContext,
                            'schedule_count' => $paymentSchedules->count(),
                            'schedule_ids' => $paymentSchedules
                                ->pluck('id')
                                ->values()
                                ->all(),
                        ]
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | 6. CREATE INVOICE
                    |--------------------------------------------------------------------------
                    */

                    Log::info(
                        'Starting invoice creation workflow.',
                        [
                            ...$logContext,
                            'workflow' => 'invoice_creation',
                        ]
                    );

                    $invoice = $this->invoiceService
                        ->createFromAssessment(
                            $assessment
                        );

                    Log::info(
                        'Invoice created successfully.',
                        [
                            ...$logContext,
                            'invoice_id' => $invoice->id,
                        ]
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | 7. ISSUE INVOICE
                    |--------------------------------------------------------------------------
                    */

                    Log::info(
                        'Starting invoice issuance workflow.',
                        [
                            ...$logContext,
                            'invoice_id' => $invoice->id,
                        ]
                    );

                    $this->invoiceIssuanceService->issue(
                        $invoice
                    );

                    Log::info(
                        'Invoice issued successfully.',
                        [
                            ...$logContext,
                            'invoice_id' => $invoice->id,
                        ]
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | 8. REFRESH ASSESSMENT
                    |--------------------------------------------------------------------------
                    */

                    $freshAssessment = $assessment->fresh([
                        'services',
                        'citizen',
                    ]);

                    Log::info(
                        'Assessment approval workflow completed successfully.',
                        [
                            ...$logContext,
                            'final_status' => $freshAssessment?->status,
                            'invoice_id' => $invoice->id,
                            'payment_schedule_count' =>
                                $paymentSchedules->count(),
                        ]
                    );

                    return $freshAssessment;
                }
            );
        } catch (Throwable $exception) {
            Log::error(
                'Assessment approval workflow failed.',
                [
                    'assessment_id' => $assessmentId,
                    'exception_class' => $exception::class,
                    'exception_message' => $exception->getMessage(),
                    'exception_code' => $exception->getCode(),
                    'exception_file' => $exception->getFile(),
                    'exception_line' => $exception->getLine(),
                ]
            );

            throw $exception;
        }
    }
}
