<?php

namespace App\Console\Commands;

use App\Jobs\CalculateAssessmentOverdueBalanceJob;
use App\Models\Assessment;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

class CalculateOverdueBalancesCommand extends Command
{
    /**
     * ============================================================
     * COMMAND SIGNATURE
     * ============================================================
     *
     * Examples:
     *
     * php artisan revenue:calculate-overdue-balances
     *
     * php artisan revenue:calculate-overdue-balances \
     *     --date=2026-09-12
     *
     * php artisan revenue:calculate-overdue-balances \
     *     --assessment=UUID
     *
     * php artisan revenue:calculate-overdue-balances \
     *     --assessment=UUID \
     *     --date=2026-09-12
     */
    protected $signature = 'revenue:calculate-overdue-balances
                            {--date= : Calculate balances as of a specific date (Y-m-d)}
                            {--assessment= : Dispatch only one assessment UUID}';

    /**
     * ============================================================
     * DESCRIPTION
     * ============================================================
     */
    protected $description =
        'Dispatch jobs to calculate current outstanding balances for overdue revenue assessments';

    /**
     * ============================================================
     * HANDLE
     * ============================================================
     */
    public function handle(): int
    {
        try {
            $asOfDate = $this->resolveAsOfDate();

            $this->info(
                sprintf(
                    'Finding overdue assessments as of %s...',
                    $asOfDate->toDateString(),
                )
            );

            $query = $this->buildQuery($asOfDate);

            /*
             * ----------------------------------------------------
             * OPTIONAL SINGLE-ASSESSMENT FILTER
             * ----------------------------------------------------
             */
            $assessmentId = $this->option('assessment');

            if ($assessmentId !== null) {
                $query->whereKey($assessmentId);
            }

            $dispatched = 0;

            /*
             * ----------------------------------------------------
             * PROCESS IN CHUNKS
             * ----------------------------------------------------
             *
             * We do not load the entire assessment table into memory.
             */
            $query->chunkById(
                100,
                function ($assessments) use (
                    $asOfDate,
                    &$dispatched,
                ): void {
                    foreach ($assessments as $assessment) {
                        try {
                            /*
                             * ------------------------------------------------
                             * DISPATCH FINANCIAL CALCULATION JOB
                             * ------------------------------------------------
                             */
                            CalculateAssessmentOverdueBalanceJob::dispatch(
                                assessmentId: $assessment->id,
                                asOfDate: $asOfDate->toDateString(),
                            );

                            $dispatched++;

                            $this->line(
                                sprintf(
                                    '[%s] Overdue balance job dispatched.',
                                    $assessment->id,
                                )
                            );
                        } catch (Throwable $exception) {
                            /*
                             * --------------------------------------------
                             * One dispatch failure should not stop the
                             * entire batch.
                             * --------------------------------------------
                             */
                            Log::error(
                                'Failed to dispatch revenue overdue balance job.',
                                [
                                    'assessment_id' => $assessment->id,
                                    'as_of_date' => $asOfDate->toDateString(),
                                    'exception' => $exception::class,
                                    'message' => $exception->getMessage(),
                                ],
                            );

                            $this->error(
                                sprintf(
                                    '[%s] Failed to dispatch job: %s',
                                    $assessment->id,
                                    $exception->getMessage(),
                                )
                            );
                        }
                    }
                },
                'id',
                'id',
            );

            $this->newLine();

            $this->info(
                sprintf(
                    'Overdue balance jobs dispatched successfully. Total: %d.',
                    $dispatched,
                )
            );

            return self::SUCCESS;
        } catch (Throwable $exception) {
            Log::error(
                'Revenue overdue balance command failed.',
                [
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ],
            );

            $this->error(
                sprintf(
                    'Command failed: %s',
                    $exception->getMessage(),
                )
            );

            return self::FAILURE;
        }
    }

    /**
     * ============================================================
     * BUILD ELIGIBLE ASSESSMENT QUERY
     * ============================================================
     *
     * Only assessments that:
     *
     * - are financially active
     * - have completed assessment services
     * - have a principal amount
     * - have a persisted due date
     * - are already past the due date
     *
     * are dispatched.
     */
    protected function buildQuery(
        CarbonInterface $asOfDate,
    ): Builder {
        return Assessment::query()
            ->whereIn('status', [
                'APPROVED',
                'INVOICE',
                'ISSUED',
            ])
            ->whereHas(
                'services',
                function (Builder $query) use ($asOfDate): void {
                    $query
                        ->where('status', 'COMPLETED')
                        ->whereNotNull('computed_amount')
                        ->where('computed_amount', '>', 0)
                        ->whereNotNull('due_date')
                        ->whereDate(
                            'due_date',
                            '<',
                            $asOfDate->toDateString(),
                        );
                }
            )
            ->orderBy('id');
    }

    /**
     * ============================================================
     * RESOLVE AS-OF DATE
     * ============================================================
     */
    protected function resolveAsOfDate(): Carbon
    {
        $date = $this->option('date');

        /*
         * No date supplied:
         *
         * Use today's application date.
         */
        if ($date === null) {
            return now()->startOfDay();
        }

        try {
            $parsed = Carbon::createFromFormat(
                'Y-m-d',
                $date,
            );

            /*
             * createFromFormat() can normalize invalid dates.
             *
             * Therefore compare the formatted value with the
             * original input.
             */
            if ($parsed->format('Y-m-d') !== $date) {
                throw new \InvalidArgumentException(
                    'Invalid calendar date.',
                );
            }

            return $parsed->startOfDay();
        } catch (Throwable) {
            $this->error(
                sprintf(
                    'Invalid date [%s]. Expected a valid date in Y-m-d format.',
                    $date,
                )
            );

            throw new \InvalidArgumentException(
                sprintf(
                    'Invalid --date value [%s].',
                    $date,
                )
            );
        }
    }
}