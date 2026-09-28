<?php

namespace App\Modules\PaymentSchedule\Services;

use App\Models\AssessmentService;
use App\Models\Invoice;
use App\Models\PaymentSchedule;
use App\Modules\Invoice\Services\InvoiceService;
use App\Modules\Invoice\Services\InvoiceIssuanceService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class PaymentScheduleManagementService
{
    /*
    |--------------------------------------------------------------------------
    | CONSTRUCTOR
    |--------------------------------------------------------------------------
    */

    public function __construct(
        protected InvoiceService $invoiceService,
        protected InvoiceIssuanceService $invoiceIssuanceService,
    ) {
        Log::debug(
            'PaymentScheduleManagementService initialized',
            [
                'service' => static::class,
            ]
        );
    }

    // ============================================================
    // GET PAYMENT SCHEDULE
    // ============================================================

    /**
     * Get payment schedules for an assessment service.
     *
     * READ ONLY.
     *
     * This method:
     * - validates the assessment service
     * - reads already-generated payment schedules
     * - loads invoice linkage information
     * - returns schedules ordered by installment number
     *
     * This method DOES NOT:
     * - create schedules
     * - calculate tariffs
     * - recalculate amounts
     * - modify payment schedules
     * - create invoices
     * - issue invoices
     */
    public function getSchedule(
        string $assessmentServiceId
    ): Collection {
        Log::info(
            'Payment schedule retrieval started',
            [
                'assessment_service_id' => $assessmentServiceId,
            ]
        );

        try {
            Log::debug(
                'Loading assessment service for payment schedule retrieval',
                [
                    'assessment_service_id' => $assessmentServiceId,
                ]
            );

            $assessmentService = AssessmentService::query()
                ->with([
                    'assessment',
                    'service.revenueCode',
                ])
                ->findOrFail($assessmentServiceId);

            Log::debug(
                'Assessment service loaded',
                [
                    'assessment_service_id' =>
                        $assessmentService->id,

                    'assessment_id' =>
                        $assessmentService->assessment_id ?? null,

                    'service_id' =>
                        $assessmentService->service_id ?? null,

                    'assessment_status' =>
                        $assessmentService->assessment?->status,

                    'revenue_code' =>
                        $assessmentService
                            ->service
                            ?->revenueCode
                            ?->code,
                ]
            );

            $this->validateAssessmentService(
                $assessmentService
            );

            Log::debug(
                'Loading generated payment schedules',
                [
                    'assessment_service_id' =>
                        $assessmentService->id,
                ]
            );

            $schedules = PaymentSchedule::query()
                ->where(
                    'assessment_service_id',
                    $assessmentService->id
                )
                ->with([
                    'invoiceItems',
                ])
                ->orderBy('installment_number')
                ->get();

            Log::info(
                'Payment schedule retrieval completed',
                [
                    'assessment_service_id' =>
                        $assessmentService->id,

                    'schedule_count' =>
                        $schedules->count(),

                    'installment_numbers' =>
                        $schedules
                            ->pluck('installment_number')
                            ->values()
                            ->all(),
                ]
            );

            return $schedules;
        } catch (Throwable $e) {
            Log::error(
                'Payment schedule retrieval failed',
                [
                    'assessment_service_id' =>
                        $assessmentServiceId,

                    'exception_class' =>
                        get_class($e),

                    'exception_message' =>
                        $e->getMessage(),

                    'exception_file' =>
                        $e->getFile(),

                    'exception_line' =>
                        $e->getLine(),
                ]
            );

            throw $e;
        }
    }

    // ============================================================
    // GET PAYMENT SCHEDULE CONTEXT
    // ============================================================

    /**
     * Get assessment service context together with its
     * already-generated payment schedules.
     *
     * READ ONLY.
     *
     * This method does not create or modify financial records.
     */
    public function getScheduleContext(
        string $assessmentServiceId
    ): array {
        Log::info(
            'Payment schedule context retrieval started',
            [
                'assessment_service_id' =>
                    $assessmentServiceId,
            ]
        );

        try {
            Log::debug(
                'Loading assessment service context',
                [
                    'assessment_service_id' =>
                        $assessmentServiceId,
                ]
            );

            $assessmentService = AssessmentService::query()
                ->with([
                    'assessment',
                    'service.revenueCode',
                ])
                ->findOrFail($assessmentServiceId);

            Log::debug(
                'Assessment service context loaded',
                [
                    'assessment_service_id' =>
                        $assessmentService->id,

                    'assessment_id' =>
                        $assessmentService->assessment_id ?? null,

                    'assessment_status' =>
                        $assessmentService->assessment?->status,

                    'service_id' =>
                        $assessmentService->service_id ?? null,

                    'revenue_code' =>
                        $assessmentService
                            ->service
                            ?->revenueCode
                            ?->code,
                ]
            );

            $this->validateAssessmentService(
                $assessmentService
            );

            Log::debug(
                'Loading payment schedules for context',
                [
                    'assessment_service_id' =>
                        $assessmentService->id,
                ]
            );

            $schedules = PaymentSchedule::query()
                ->where(
                    'assessment_service_id',
                    $assessmentService->id
                )
                ->with([
                    'invoiceItems',
                ])
                ->orderBy('installment_number')
                ->get();

            Log::info(
                'Payment schedule context retrieval completed',
                [
                    'assessment_service_id' =>
                        $assessmentService->id,

                    'schedule_count' =>
                        $schedules->count(),
                ]
            );

            return [
                'assessmentService' =>
                    $assessmentService,

                'schedules' =>
                    $schedules,
            ];
        } catch (Throwable $e) {
            Log::error(
                'Payment schedule context retrieval failed',
                [
                    'assessment_service_id' =>
                        $assessmentServiceId,

                    'exception_class' =>
                        get_class($e),

                    'exception_message' =>
                        $e->getMessage(),

                    'exception_file' =>
                        $e->getFile(),

                    'exception_line' =>
                        $e->getLine(),
                ]
            );

            throw $e;
        }
    }

    // ============================================================
    // CREATE + ISSUE INVOICE FROM PAYMENT SCHEDULES
    // ============================================================

    /**
     * Create and issue one invoice from selected payment schedules.
     *
     * WORKFLOW:
     *
     *     PAYMENT SCHEDULE
     *            ↓
     *     SELECT SCHEDULES
     *            ↓
     *     VALIDATE + LOCK
     *            ↓
     *     InvoiceService
     *            ↓
     *       CREATE DRAFT
     *            ↓
     *     InvoiceIssuanceService
     *            ↓
     *          ISSUED
     *            ↓
     *         PAYMENT
     *
     * IMPORTANT:
     *
     * This service is the workflow orchestrator.
     *
     * InvoiceService:
     * - constructs the invoice
     * - creates invoice items
     * - calculates invoice totals from persisted invoice items
     * - returns a DRAFT invoice
     *
     * InvoiceIssuanceService:
     * - validates issuance rules
     * - performs DRAFT → ISSUED
     * - records issued_by
     * - records issued_at
     *
     * This method does NOT:
     * - calculate tariffs
     * - calculate installment amounts
     * - modify amount_due
     * - modify amount_paid
     * - modify due_date
     * - rebuild schedules
     * - recalculate assessments
     * - calculate penalty
     * - calculate interest
     * - mark schedules as paid
     * - send SMS
     */
    public function createInvoiceFromPaymentSchedules(
        string $assessmentServiceId,
        array $paymentScheduleIds,
    ): Invoice {
        $normalizedPaymentScheduleIds = array_values(
            array_unique(
                array_map(
                    'strval',
                    $paymentScheduleIds
                )
            )
        );

        $requestedPaymentScheduleIds = array_values(
            array_map(
                'strval',
                $paymentScheduleIds
            )
        );

        Log::info(
            'Create and issue invoice from payment schedules started',
            [
                'assessment_service_id' =>
                    $assessmentServiceId,

                'requested_payment_schedule_ids' =>
                    $requestedPaymentScheduleIds,

                'normalized_payment_schedule_ids' =>
                    $normalizedPaymentScheduleIds,

                'requested_payment_schedule_count' =>
                    count($paymentScheduleIds),

                'normalized_payment_schedule_count' =>
                    count($normalizedPaymentScheduleIds),
            ]
        );

        // ------------------------------------------------------------
        // EMPTY SELECTION
        // ------------------------------------------------------------

        if ($normalizedPaymentScheduleIds === []) {
            Log::warning(
                'Invoice creation rejected because no payment schedules were selected',
                [
                    'assessment_service_id' =>
                        $assessmentServiceId,
                ]
            );

            throw ValidationException::withMessages([
                'payment_schedule_ids' =>
                    'At least one payment schedule must be selected.',
            ]);
        }

        // ------------------------------------------------------------
        // DUPLICATE SELECTION
        // ------------------------------------------------------------

        if (
            count($normalizedPaymentScheduleIds)
            !== count($paymentScheduleIds)
        ) {
            Log::warning(
                'Invoice creation rejected because duplicate payment schedules were selected',
                [
                    'assessment_service_id' =>
                        $assessmentServiceId,

                    'requested_payment_schedule_ids' =>
                        $requestedPaymentScheduleIds,
                ]
            );

            throw ValidationException::withMessages([
                'payment_schedule_ids' =>
                    'A payment schedule cannot be selected more than once.',
            ]);
        }

        return DB::transaction(
            function () use (
                $assessmentServiceId,
                $normalizedPaymentScheduleIds
            ): Invoice {
                Log::debug(
                    'Create and issue invoice transaction started',
                    [
                        'assessment_service_id' =>
                            $assessmentServiceId,

                        'payment_schedule_ids' =>
                            $normalizedPaymentScheduleIds,
                    ]
                );

                try {
                    // =================================================
                    // 1. LOAD AND LOCK ASSESSMENT SERVICE
                    // =================================================

                    Log::debug(
                        'Loading and locking assessment service',
                        [
                            'assessment_service_id' =>
                                $assessmentServiceId,
                        ]
                    );

                    $assessmentService = AssessmentService::query()
                        ->with([
                            'assessment',
                            'service.revenueCode',
                            'values',
                        ])
                        ->lockForUpdate()
                        ->findOrFail(
                            $assessmentServiceId
                        );

                    Log::debug(
                        'Assessment service locked',
                        [
                            'assessment_service_id' =>
                                $assessmentService->id,

                            'assessment_id' =>
                                $assessmentService->assessment_id
                                ?? null,

                            'assessment_status' =>
                                $assessmentService
                                    ->assessment
                                    ?->status,

                            'service_id' =>
                                $assessmentService->service_id
                                ?? null,

                            'revenue_code' =>
                                $assessmentService
                                    ->service
                                    ?->revenueCode
                                    ?->code,
                        ]
                    );

                    // =================================================
                    // 2. VALIDATE ASSESSMENT SERVICE
                    // =================================================

                    Log::debug(
                        'Validating assessment service',
                        [
                            'assessment_service_id' =>
                                $assessmentService->id,
                        ]
                    );

                    $this->validateAssessmentService(
                        $assessmentService
                    );

                    Log::debug(
                        'Assessment service validation passed',
                        [
                            'assessment_service_id' =>
                                $assessmentService->id,
                        ]
                    );

                    // =================================================
                    // 3. LOAD AND LOCK SELECTED PAYMENT SCHEDULES
                    // =================================================

                    Log::debug(
                        'Loading and locking selected payment schedules',
                        [
                            'assessment_service_id' =>
                                $assessmentService->id,

                            'payment_schedule_ids' =>
                                $normalizedPaymentScheduleIds,
                        ]
                    );

                    $schedules = PaymentSchedule::query()
                        ->where(
                            'assessment_service_id',
                            $assessmentService->id
                        )
                        ->whereIn(
                            'id',
                            $normalizedPaymentScheduleIds
                        )
                        ->with([
                            'invoiceItems',
                        ])
                        ->lockForUpdate()
                        ->orderBy(
                            'installment_number'
                        )
                        ->get();

                    Log::info(
                        'Selected payment schedules loaded',
                        [
                            'assessment_service_id' =>
                                $assessmentService->id,

                            'requested_count' =>
                                count(
                                    $normalizedPaymentScheduleIds
                                ),

                            'found_count' =>
                                $schedules->count(),

                            'found_payment_schedule_ids' =>
                                $schedules
                                    ->pluck('id')
                                    ->map(
                                        fn ($id) =>
                                            (string) $id
                                    )
                                    ->values()
                                    ->all(),

                            'found_installment_numbers' =>
                                $schedules
                                    ->pluck(
                                        'installment_number'
                                    )
                                    ->values()
                                    ->all(),
                        ]
                    );

                    // =================================================
                    // 4. VALIDATE OWNERSHIP
                    // =================================================

                    Log::debug(
                        'Validating selected payment schedule ownership',
                        [
                            'assessment_service_id' =>
                                $assessmentService->id,

                            'requested_payment_schedule_count' =>
                                count(
                                    $normalizedPaymentScheduleIds
                                ),

                            'found_payment_schedule_count' =>
                                $schedules->count(),
                        ]
                    );

                    $this->validateSelectedPaymentSchedules(
                        $normalizedPaymentScheduleIds,
                        $schedules,
                    );

                    Log::debug(
                        'Selected payment schedule ownership validation passed',
                        [
                            'assessment_service_id' =>
                                $assessmentService->id,
                        ]
                    );

                    // =================================================
                    // 5. LOG FINANCIAL SNAPSHOT
                    // =================================================

                    Log::info(
                        'Selected payment schedule financial snapshot',
                        [
                            'assessment_service_id' =>
                                $assessmentService->id,

                            'payment_schedules' =>
                                $schedules
                                    ->map(
                                        function (
                                            PaymentSchedule $schedule
                                        ): array {
                                            return [
                                                'id' =>
                                                    (string) $schedule->id,

                                                'installment_number' =>
                                                    $schedule
                                                        ->installment_number,

                                                'due_date' =>
                                                    $schedule
                                                        ->due_date,

                                                'rule_percentage' =>
                                                    $schedule
                                                        ->rule_percentage,

                                                'amount_due' =>
                                                    $schedule
                                                        ->amount_due,

                                                'amount_paid' =>
                                                    $schedule
                                                        ->amount_paid,

                                                'remaining_amount' =>
                                                    $this
                                                        ->resolveRemainingAmount(
                                                            $schedule
                                                        ),

                                                'status' =>
                                                    $schedule
                                                        ->status
                                                        ?->value,

                                                'paid_at' =>
                                                    $schedule
                                                        ->paid_at,

                                                'invoice_item_count' =>
                                                    $schedule
                                                        ->invoiceItems
                                                        ?->count()
                                                    ?? 0,
                                            ];
                                        }
                                    )
                                    ->values()
                                    ->all(),
                        ]
                    );

                    // =================================================
                    // 6. VALIDATE INVOICE ELIGIBILITY
                    // =================================================

                    Log::debug(
                        'Validating payment schedule invoice eligibility',
                        [
                            'assessment_service_id' =>
                                $assessmentService->id,

                            'payment_schedule_count' =>
                                $schedules->count(),
                        ]
                    );

                    $this->validateInvoiceEligibility(
                        $schedules
                    );

                    Log::info(
                        'Payment schedule invoice eligibility validation passed',
                        [
                            'assessment_service_id' =>
                                $assessmentService->id,

                            'payment_schedule_count' =>
                                $schedules->count(),
                        ]
                    );

                    // =================================================
                    // 7. CREATE DRAFT INVOICE
                    // =================================================

                    Log::info(
                        'Delegating invoice construction to InvoiceService',
                        [
                            'assessment_service_id' =>
                                $assessmentService->id,

                            'payment_schedule_ids' =>
                                $schedules
                                    ->pluck('id')
                                    ->map(
                                        fn ($id) =>
                                            (string) $id
                                    )
                                    ->values()
                                    ->all(),

                            'payment_schedule_count' =>
                                $schedules->count(),
                        ]
                    );

                    $invoice = $this->invoiceService
                        ->createFromPaymentSchedules(
                            $assessmentService,
                            $schedules,
                        );

                    Log::info(
                        'Draft invoice created successfully',
                        [
                            'assessment_service_id' =>
                                $assessmentService->id,

                            'invoice_id' =>
                                $invoice->id,

                            'invoice_number' =>
                                $invoice->invoice_number,

                            'invoice_status' =>
                                $invoice->status,

                            'total_amount' =>
                                $invoice->total_amount,

                            'selected_payment_schedule_count' =>
                                $schedules->count(),
                        ]
                    );

                    // =================================================
                    // 8. ISSUE INVOICE
                    // =================================================
                    //
                    // InvoiceIssuanceService is the SINGLE authority
                    // responsible for:
                    //
                    //     DRAFT → ISSUED
                    //
                    // Do not update invoice status directly here.
                    //
                    // =================================================

                    Log::info(
                        'Delegating invoice issuance to InvoiceIssuanceService',
                        [
                            'invoice_id' =>
                                $invoice->id,

                            'invoice_number' =>
                                $invoice->invoice_number,

                            'current_invoice_status' =>
                                $invoice->status,

                            'assessment_service_id' =>
                                $assessmentService->id,
                        ]
                    );

                    $invoice =
                        $this->invoiceIssuanceService
                            ->issue(
                                $invoice
                            );

                    Log::info(
                        'Invoice issued successfully',
                        [
                            'assessment_service_id' =>
                                $assessmentService->id,

                            'invoice_id' =>
                                $invoice->id,

                            'invoice_number' =>
                                $invoice->invoice_number,

                            'invoice_status' =>
                                $invoice->status,

                            'issued_by' =>
                                $invoice->issued_by
                                ?? null,

                            'issued_at' =>
                                $invoice->issued_at
                                ?->toDateTimeString(),

                            'total_amount' =>
                                $invoice->total_amount,

                            'selected_payment_schedule_count' =>
                                $schedules->count(),
                        ]
                    );

                    // =================================================
                    // 9. FINAL VALIDATION
                    // =================================================

                    if (
                        strtoupper(
                            (string) $invoice->status
                        ) !== 'ISSUED'
                    ) {
                        Log::error(
                            'Invoice issuance service returned a non-issued invoice',
                            [
                                'invoice_id' =>
                                    $invoice->id,

                                'invoice_number' =>
                                    $invoice->invoice_number,

                                'invoice_status' =>
                                    $invoice->status,
                            ]
                        );

                        throw ValidationException::withMessages([
                            'invoice' =>
                                'The invoice could not be issued successfully.',
                        ]);
                    }

                    // =================================================
                    // 10. LOG FINAL RESULT
                    // =================================================

                    Log::info(
                        'Create and issue invoice from payment schedules completed successfully',
                        [
                            'assessment_service_id' =>
                                $assessmentService->id,

                            'assessment_id' =>
                                $assessmentService->assessment_id,

                            'invoice_id' =>
                                $invoice->id,

                            'invoice_number' =>
                                $invoice->invoice_number,

                            'invoice_status' =>
                                $invoice->status,

                            'issued_by' =>
                                $invoice->issued_by
                                ?? null,

                            'issued_at' =>
                                $invoice->issued_at
                                ?->toDateTimeString(),

                            'total_amount' =>
                                $invoice->total_amount,

                            'selected_payment_schedule_count' =>
                                $schedules->count(),

                            'selected_payment_schedule_ids' =>
                                $schedules
                                    ->pluck('id')
                                    ->map(
                                        fn ($id) =>
                                            (string) $id
                                    )
                                    ->values()
                                    ->all(),
                        ]
                    );

                    return $invoice->fresh([
                        'items',
                        'items.service',
                        'items.paymentSchedule',
                        'assessment',
                        'citizen',
                        'creator',
                        'issuer',
                    ]);
                } catch (Throwable $e) {
                    Log::error(
                        'Create and issue invoice transaction failed',
                        [
                            'assessment_service_id' =>
                                $assessmentServiceId,

                            'payment_schedule_ids' =>
                                $normalizedPaymentScheduleIds,

                            'exception_class' =>
                                get_class($e),

                            'exception_message' =>
                                $e->getMessage(),

                            'exception_file' =>
                                $e->getFile(),

                            'exception_line' =>
                                $e->getLine(),
                        ]
                    );

                    throw $e;
                }
            }
        );
    }

    // ============================================================
    // VALIDATE ASSESSMENT SERVICE
    // ============================================================

    /**
     * Validate that the assessment service belongs to
     * an existing approved assessment.
     */
    protected function validateAssessmentService(
        AssessmentService $assessmentService
    ): void {
        Log::debug(
            'Assessment service validation started',
            [
                'assessment_service_id' =>
                    $assessmentService->id,

                'assessment_id' =>
                    $assessmentService->assessment_id
                    ?? null,

                'assessment_exists' =>
                    $assessmentService->assessment !== null,

                'assessment_status' =>
                    $assessmentService->assessment?->status,
            ]
        );

        // ------------------------------------------------------------
        // ASSESSMENT MUST EXIST
        // ------------------------------------------------------------

        if (! $assessmentService->assessment) {
            Log::warning(
                'Assessment service validation failed: assessment missing',
                [
                    'assessment_service_id' =>
                        $assessmentService->id,
                ]
            );

            throw ValidationException::withMessages([
                'assessment_service_id' =>
                    'The assessment service is not associated with an assessment.',
            ]);
        }

        // ------------------------------------------------------------
        // ASSESSMENT MUST BE APPROVED
        // ------------------------------------------------------------

        $assessmentStatus = strtoupper(
            (string) $assessmentService
                ->assessment
                ->status
        );

        if ($assessmentStatus !== 'APPROVED') {
            Log::warning(
                'Assessment service validation failed: assessment not approved',
                [
                    'assessment_service_id' =>
                        $assessmentService->id,

                    'assessment_id' =>
                        $assessmentService
                            ->assessment
                            ->id
                            ?? null,

                    'assessment_status' =>
                        $assessmentStatus,
                ]
            );

            throw ValidationException::withMessages([
                'assessment_service_id' =>
                    'Payment schedules can only be used for an approved assessment.',
            ]);
        }

        Log::debug(
            'Assessment service validation successful',
            [
                'assessment_service_id' =>
                    $assessmentService->id,

                'assessment_id' =>
                    $assessmentService
                        ->assessment
                        ->id
                        ?? null,

                'assessment_status' =>
                    $assessmentStatus,
            ]
        );
    }

    // ============================================================
    // VALIDATE SELECTED PAYMENT SCHEDULES
    // ============================================================

    /**
     * Ensure every requested payment schedule exists under
     * the requested assessment service.
     *
     * This prevents a caller from submitting a payment schedule
     * belonging to another assessment service.
     */
    protected function validateSelectedPaymentSchedules(
        array $requestedIds,
        Collection $schedules,
    ): void {
        $normalizedRequestedIds = array_values(
            array_map(
                'strval',
                $requestedIds
            )
        );

        $foundIds = $schedules
            ->pluck('id')
            ->map(
                fn ($id) =>
                    (string) $id
            )
            ->values()
            ->all();

        $missingIds = array_values(
            array_diff(
                $normalizedRequestedIds,
                $foundIds
            )
        );

        Log::debug(
            'Selected payment schedule ownership validation result',
            [
                'requested_ids' =>
                    $normalizedRequestedIds,

                'found_ids' =>
                    $foundIds,

                'missing_ids' =>
                    $missingIds,
            ]
        );

        if ($missingIds !== []) {
            Log::warning(
                'Selected payment schedule ownership validation failed',
                [
                    'missing_payment_schedule_ids' =>
                        $missingIds,

                    'found_payment_schedule_ids' =>
                        $foundIds,
                ]
            );

            throw ValidationException::withMessages([
                'payment_schedule_ids' =>
                    'One or more selected payment schedules do not belong to this assessment service.',
            ]);
        }

        Log::debug(
            'Selected payment schedule ownership validation successful',
            [
                'requested_count' =>
                    count($normalizedRequestedIds),

                'found_count' =>
                    count($foundIds),
            ]
        );
    }

    // ============================================================
    // VALIDATE INVOICE ELIGIBILITY
    // ============================================================

    /**
     * Validate whether selected payment schedules can be invoiced.
     *
     * Invoice linkage is determined through:
     *
     * invoice_items.payment_schedule_id
     *
     * PaymentSchedule itself does NOT contain invoice_id.
     *
     * IMPORTANT:
     *
     * This method does not change any payment schedule.
     */
    protected function validateInvoiceEligibility(
        Collection $schedules
    ): void {
        Log::debug(
            'Payment schedule invoice eligibility validation started',
            [
                'payment_schedule_count' =>
                    $schedules->count(),
            ]
        );

        if ($schedules->isEmpty()) {
            Log::warning(
                'Payment schedule invoice eligibility validation failed: no schedules supplied'
            );

            throw ValidationException::withMessages([
                'payment_schedule_ids' =>
                    'At least one payment schedule must be selected.',
            ]);
        }

        foreach ($schedules as $schedule) {
            $installmentNumber =
                $schedule->installment_number;

            /*
            |--------------------------------------------------------------------------
            | ENUM IS CAST TO PaymentScheduleStatus
            |--------------------------------------------------------------------------
            */

            $status = $schedule->status?->value;

            $amountDue = (float) (
                $schedule->amount_due ?? 0
            );

            $amountPaid = (float) (
                $schedule->amount_paid ?? 0
            );

            $remainingAmount =
                $this->resolveRemainingAmount(
                    $schedule
                );

            $invoiceItemCount =
                $schedule
                    ->invoiceItems
                    ?->count()
                ?? 0;

            Log::debug(
                'Checking payment schedule invoice eligibility',
                [
                    'payment_schedule_id' =>
                        (string) $schedule->id,

                    'installment_number' =>
                        $installmentNumber,

                    'due_date' =>
                        $schedule->due_date,

                    'rule_percentage' =>
                        $schedule->rule_percentage,

                    'amount_due' =>
                        $amountDue,

                    'amount_paid' =>
                        $amountPaid,

                    'remaining_amount' =>
                        $remainingAmount,

                    'status' =>
                        $status,

                    'invoice_item_count' =>
                        $invoiceItemCount,
                ]
            );

            // ========================================================
            // INVALID / MISSING STATUS
            // ========================================================

            if ($status === null) {
                Log::warning(
                    'Payment schedule invoice eligibility failed: status missing',
                    [
                        'payment_schedule_id' =>
                            (string) $schedule->id,

                        'installment_number' =>
                            $installmentNumber,
                    ]
                );

                throw ValidationException::withMessages([
                    'payment_schedule_ids' =>
                        "Payment schedule installment {$installmentNumber} has no valid status.",
                ]);
            }

            // ========================================================
            // ALREADY INVOICED
            // ========================================================

            if ($invoiceItemCount > 0) {
                Log::warning(
                    'Payment schedule invoice eligibility failed: already invoiced',
                    [
                        'payment_schedule_id' =>
                            (string) $schedule->id,

                        'installment_number' =>
                            $installmentNumber,

                        'invoice_item_count' =>
                            $invoiceItemCount,
                    ]
                );

                throw ValidationException::withMessages([
                    'payment_schedule_ids' =>
                        "Payment schedule installment {$installmentNumber} has already been invoiced.",
                ]);
            }

            // ========================================================
            // INVALID STATUS
            // ========================================================

            if (
                in_array(
                    $status,
                    [
                        'PAID',
                        'CANCELLED',
                    ],
                    true
                )
            ) {
                Log::warning(
                    'Payment schedule invoice eligibility failed: invalid status',
                    [
                        'payment_schedule_id' =>
                            (string) $schedule->id,

                        'installment_number' =>
                            $installmentNumber,

                        'status' =>
                            $status,
                    ]
                );

                throw ValidationException::withMessages([
                    'payment_schedule_ids' =>
                        "Payment schedule installment {$installmentNumber} cannot be invoiced because its status is {$status}.",
                ]);
            }

            // ========================================================
            // AMOUNT DUE CANNOT BE NEGATIVE
            // ========================================================

            if ($amountDue < 0) {
                Log::warning(
                    'Payment schedule invoice eligibility failed: negative amount due',
                    [
                        'payment_schedule_id' =>
                            (string) $schedule->id,

                        'installment_number' =>
                            $installmentNumber,

                        'amount_due' =>
                            $amountDue,
                    ]
                );

                throw ValidationException::withMessages([
                    'payment_schedule_ids' =>
                        "Payment schedule installment {$installmentNumber} has an invalid amount due.",
                ]);
            }

            // ========================================================
            // AMOUNT PAID CANNOT BE NEGATIVE
            // ========================================================

            if ($amountPaid < 0) {
                Log::warning(
                    'Payment schedule invoice eligibility failed: negative paid amount',
                    [
                        'payment_schedule_id' =>
                            (string) $schedule->id,

                        'installment_number' =>
                            $installmentNumber,

                        'amount_paid' =>
                            $amountPaid,
                    ]
                );

                throw ValidationException::withMessages([
                    'payment_schedule_ids' =>
                        "Payment schedule installment {$installmentNumber} has an invalid paid amount.",
                ]);
            }

            // ========================================================
            // PREVENT OVERPAYMENT
            // ========================================================

            if ($amountPaid > $amountDue) {
                Log::warning(
                    'Payment schedule invoice eligibility failed: overpayment',
                    [
                        'payment_schedule_id' =>
                            (string) $schedule->id,

                        'installment_number' =>
                            $installmentNumber,

                        'amount_due' =>
                            $amountDue,

                        'amount_paid' =>
                            $amountPaid,
                    ]
                );

                throw ValidationException::withMessages([
                    'payment_schedule_ids' =>
                        "Payment schedule installment {$installmentNumber} has a paid amount greater than its amount due.",
                ]);
            }

            // ========================================================
            // NO REMAINING AMOUNT
            // ========================================================

            if ($remainingAmount <= 0) {
                Log::warning(
                    'Payment schedule invoice eligibility failed: no remaining amount',
                    [
                        'payment_schedule_id' =>
                            (string) $schedule->id,

                        'installment_number' =>
                            $installmentNumber,

                        'amount_due' =>
                            $amountDue,

                        'amount_paid' =>
                            $amountPaid,

                        'remaining_amount' =>
                            $remainingAmount,
                    ]
                );

                throw ValidationException::withMessages([
                    'payment_schedule_ids' =>
                        "Payment schedule installment {$installmentNumber} has no remaining amount to invoice.",
                ]);
            }

            // ========================================================
            // VALID DUE DATE
            // ========================================================

            if (! $schedule->due_date) {
                Log::warning(
                    'Payment schedule invoice eligibility failed: due date missing',
                    [
                        'payment_schedule_id' =>
                            (string) $schedule->id,

                        'installment_number' =>
                            $installmentNumber,
                    ]
                );

                throw ValidationException::withMessages([
                    'payment_schedule_ids' =>
                        "Payment schedule installment {$installmentNumber} cannot be invoiced because its due date is missing.",
                ]);
            }

            Log::debug(
                'Payment schedule invoice eligibility passed',
                [
                    'payment_schedule_id' =>
                        (string) $schedule->id,

                    'installment_number' =>
                        $installmentNumber,

                    'remaining_amount' =>
                        $remainingAmount,

                    'status' =>
                        $status,
                ]
            );
        }

        Log::debug(
            'Payment schedule invoice eligibility validation completed successfully',
            [
                'payment_schedule_count' =>
                    $schedules->count(),
            ]
        );
    }

    // ============================================================
    // RESOLVE REMAINING AMOUNT
    // ============================================================

    /**
     * Resolve remaining principal for eligibility purposes.
     *
     * IMPORTANT:
     *
     * This is NOT tariff calculation.
     *
     * It only derives:
     *
     * amount_due - amount_paid
     *
     * from persisted payment schedule values.
     *
     * Penalty and interest are deliberately excluded.
     *
     * NOTE:
     *
     * This currently returns float because the existing financial
     * implementation uses numeric decimal values. For strict
     * financial precision, this should eventually be migrated to
     * decimal-string arithmetic.
     */
    protected function resolveRemainingAmount(
        PaymentSchedule $schedule
    ): float {
        $amountDue = (float) (
            $schedule->amount_due ?? 0
        );

        $amountPaid = (float) (
            $schedule->amount_paid ?? 0
        );

        $remainingAmount = max(
            $amountDue - $amountPaid,
            0
        );

        Log::debug(
            'Payment schedule remaining amount resolved',
            [
                'payment_schedule_id' =>
                    (string) $schedule->id,

                'installment_number' =>
                    $schedule->installment_number,

                'amount_due' =>
                    $amountDue,

                'amount_paid' =>
                    $amountPaid,

                'remaining_amount' =>
                    $remainingAmount,
            ]
        );

        return $remainingAmount;
    }
}