<?php

namespace App\Modules\Assessment\Services;

use App\Models\Assessment;
use App\Modules\Assessment\Requests\StoreAssessmentRequest;
use App\Services\AssessmentCalculationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class AssessmentCreationService
{
    public function __construct(
        protected AssessmentServiceValueService $serviceValueService,
        protected AssessmentCalculationService $calculationService,
    ) {
    }

    /**
     * ============================================================
     * CREATE
     * ============================================================
     *
     * Responsibilities:
     *
     * - Create assessment header
     * - Generate assessment number
     * - Store assessment services
     * - Store dynamic service values
     * - Calculate financial values when submitted
     * - Commit only when the complete operation succeeds
     */
    public function create(StoreAssessmentRequest $request): Assessment
    {
        $assessment = DB::transaction(function () use ($request): Assessment {

            /*
            |--------------------------------------------------------------------------
            | Create Assessment
            |--------------------------------------------------------------------------
            */

            $assessment = Assessment::create([
                'id' => (string) Str::uuid(),

                'assessment_number' =>
                    $this->generateAssessmentNumber(),

                'citizen_id' =>
                    $request->taxpayerId(),

                'assessment_date' =>
                    now()->toDateString(),

                'status' =>
                    $request->status(),

                'notes' =>
                    $request->validated('notes'),

                'submitted_at' =>
                    $request->status() === 'PENDING_APPROVAL'
                        ? now()
                        : null,

                'created_by' =>
                    auth()->id(),
            ]);

            /*
            |--------------------------------------------------------------------------
            | Store Services
            |--------------------------------------------------------------------------
            */

            $this->serviceValueService->storeServices(
                $assessment,
                $request->services()
            );

            /*
            |--------------------------------------------------------------------------
            | CALCULATE BEFORE COMMIT
            |--------------------------------------------------------------------------
            |
            | If this fails, Laravel automatically rolls back:
            |
            | - assessment
            | - assessment services
            | - assessment values
            | - calculation changes
            |
            */

            if ($assessment->status === 'PENDING_APPROVAL') {
                $this->calculationService->calculate(
                    $assessment
                );
            }

            return $assessment;
        });

        /*
        |--------------------------------------------------------------------------
        | Return Fresh Assessment
        |--------------------------------------------------------------------------
        */

        return $assessment->fresh([
            'taxpayer',
            'creator',
            'updater',
            'decisionOfficer',
            'services',
            'services.service',
            'services.values',
            'services.values.files',
        ]);
    }

    /**
     * ============================================================
     * ASSESSMENT NUMBER
     * ============================================================
     */

    /**
     * Generate a unique assessment number.
     *
     * Format:
     *
     * ASM-2026-000001
     */
    protected function generateAssessmentNumber(): string
    {
        $year = now()->year;

        $lastNumber = Assessment::query()
            ->where(
                'assessment_number',
                'like',
                "ASM-{$year}-%"
            )
            ->orderByDesc('assessment_number')
            ->value('assessment_number');

        if (! $lastNumber) {
            $sequence = 1;
        } else {
            $sequence =
                ((int) Str::afterLast(
                    $lastNumber,
                    '-'
                )) + 1;
        }

        return sprintf(
            'ASM-%d-%06d',
            $year,
            $sequence
        );
    }
}