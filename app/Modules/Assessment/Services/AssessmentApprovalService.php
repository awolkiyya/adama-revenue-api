<?php

namespace App\Modules\Assessment\Services;

use App\Models\Assessment;
use App\Modules\Invoice\Services\InvoiceIssuanceService;
use App\Modules\Invoice\Services\InvoiceService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssessmentApprovalService
{
    public function __construct(
        protected InvoiceService $invoiceService,
        protected InvoiceIssuanceService $invoiceIssuanceService,
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | APPROVE ASSESSMENT
    |--------------------------------------------------------------------------
    |
    | Business flow:
    |
    | PENDING_APPROVAL
    |        ↓
    |     APPROVED
    |        ↓
    | Create Invoice
    |        ↓
    |   Issue Invoice
    |        ↓
    | Notify Taxpayer
    |
    |--------------------------------------------------------------------------
    |
    | Responsibilities:
    |
    | AssessmentApprovalService
    |     - validates approval state
    |     - records approval decision
    |     - records decision officer
    |     - records approval timestamp
    |     - creates invoice
    |     - issues invoice
    |
    | InvoiceService
    |     - creates invoice
    |     - creates invoice items
    |     - copies financial snapshots
    |     - aggregates totals
    |
    | InvoiceIssuanceService
    |     - validates invoice state
    |     - changes invoice to ISSUED
    |     - records issued_by
    |     - records issued_at
    |     - generates payment information
    |     - notifies taxpayer
    |
    | No tariff calculation is performed here.
    |
    |--------------------------------------------------------------------------
    */

    /**
     * Approve an assessment and complete its invoice workflow.
     *
     * @throws ValidationException
     */
    public function approve(
        string $assessmentId
    ): Assessment {
        return DB::transaction(function () use ($assessmentId): Assessment {

            /*
            |--------------------------------------------------------------------------
            | 1. LOAD + LOCK ASSESSMENT
            |--------------------------------------------------------------------------
            |
            | Prevent concurrent approval attempts against the same assessment.
            |
            */

            $assessment = Assessment::query()
                ->with([
                    'services',
                    'citizen',
                ])
                ->lockForUpdate()
                ->findOrFail($assessmentId);

            /*
            |--------------------------------------------------------------------------
            | 2. VALIDATE ASSESSMENT STATUS
            |--------------------------------------------------------------------------
            */

            if (!$assessment->isPendingApproval()) {
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
                throw ValidationException::withMessages([
                    'authorization' => [
                        'An authenticated user is required to approve an assessment.',
                    ],
                ]);
            }

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

            /*
            |--------------------------------------------------------------------------
            | 5. CREATE INVOICE
            |--------------------------------------------------------------------------
            |
            | InvoiceService receives the Assessment model.
            |
            | It must return an App\Models\Invoice instance.
            |
            */

            $invoice = $this->invoiceService->createFromAssessment(
                $assessment
            );

            /*
            |--------------------------------------------------------------------------
            | 6. ISSUE INVOICE
            |--------------------------------------------------------------------------
            |
            | IMPORTANT:
            |
            | $invoice is an Invoice Eloquent model, NOT an invoice UUID.
            |
            | InvoiceIssuanceService::issue() must therefore accept:
            |
            |     Invoice $invoice
            |
            | and if it needs to lock/reload the invoice, it must use:
            |
            |     $invoice->id
            |
            | NOT:
            |
            |     whereKey($invoice)
            |
            */

            $this->invoiceIssuanceService->issue(
                $invoice
            );

            /*
            |--------------------------------------------------------------------------
            | 7. RETURN FRESH ASSESSMENT
            |--------------------------------------------------------------------------
            |
            | Reload the assessment after the complete workflow.
            |
            */

            return $assessment->fresh([
                'services',
                'citizen',
            ]);
        });
    }
}
