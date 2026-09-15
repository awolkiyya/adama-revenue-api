<?php

namespace App\Console\Commands;

use App\Jobs\CalculateInvoiceOverdueBalanceJob;
use App\Models\Invoice;
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
     *     --date=2026-09-15
     *
     * php artisan revenue:calculate-overdue-balances \
     *     --invoice=UUID
     *
     * php artisan revenue:calculate-overdue-balances \
     *     --invoice=UUID \
     *     --date=2026-09-15
     */
    protected $signature = 'revenue:calculate-overdue-balances
                            {--date= : Calculate balances as of a specific date (Y-m-d)}
                            {--invoice= : Dispatch only one invoice UUID}';

    /**
     * ============================================================
     * DESCRIPTION
     * ============================================================
     */
    protected $description =
        'Dispatch jobs to calculate overdue balances for eligible invoices';

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
                    'Finding overdue invoices as of %s...',
                    $asOfDate->toDateString(),
                )
            );

            $query = $this->buildQuery($asOfDate);

            /*
             * ----------------------------------------------------
             * OPTIONAL SINGLE-INVOICE FILTER
             * ----------------------------------------------------
             */
            $invoiceId = $this->option('invoice');

            if ($invoiceId !== null) {
                $query->whereKey($invoiceId);
            }

            $dispatched = 0;

            /*
             * ----------------------------------------------------
             * PROCESS IN CHUNKS
             * ----------------------------------------------------
             *
             * We do not load the entire invoice table into memory.
             */
            $query->chunkById(
                100,
                function ($invoices) use (
                    $asOfDate,
                    &$dispatched,
                ): void {
                    foreach ($invoices as $invoice) {
                        try {
                            /*
                             * ------------------------------------------------
                             * DISPATCH INVOICE ACCRUAL JOB
                             * ------------------------------------------------
                             */
                            CalculateInvoiceOverdueBalanceJob::dispatch(
                                invoiceId: $invoice->id,
                                asOfDate: $asOfDate->toDateString(),
                            );

                            $dispatched++;

                            $this->line(
                                sprintf(
                                    '[%s] Invoice overdue balance job dispatched.',
                                    $invoice->id,
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
                                'Failed to dispatch invoice overdue balance job.',
                                [
                                    'invoice_id' => $invoice->id,
                                    'as_of_date' => $asOfDate->toDateString(),
                                    'exception' => $exception::class,
                                    'message' => $exception->getMessage(),
                                ],
                            );

                            $this->error(
                                sprintf(
                                    '[%s] Failed to dispatch job: %s',
                                    $invoice->id,
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
                    'Invoice overdue balance jobs dispatched successfully. Total: %d.',
                    $dispatched,
                )
            );

            return self::SUCCESS;
        } catch (Throwable $exception) {
            Log::error(
                'Invoice overdue balance command failed.',
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
     * BUILD ELIGIBLE INVOICE QUERY
     * ============================================================
     *
     * Only invoices that:
     *
     * - are financially active
     * - have a persisted due date
     * - are already past the due date
     * - contain at least one invoice item
     * - contain an invoice item with a positive amount
     *
     * are dispatched.
     */
    protected function buildQuery(
        CarbonInterface $asOfDate,
    ): Builder {
        return Invoice::query()
            ->whereIn('status', [
                'ISSUED',
                'PARTIALLY_PAID',
                'OVERDUE',
            ])
            ->whereNotNull('due_date')
            ->whereDate(
                'due_date',
                '<',
                $asOfDate->toDateString(),
            )
            ->whereHas(
                'items',
                function (Builder $query): void {
                    $query
                        ->where('amount', '>', 0);
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
         * --------------------------------------------------------
         * No date supplied:
         *
         * Use today's application date.
         * --------------------------------------------------------
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