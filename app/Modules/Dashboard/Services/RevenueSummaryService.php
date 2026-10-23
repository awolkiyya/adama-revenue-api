<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services;

use App\Models\User;
use App\Services\Authorization\AdministrativeScopeService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;
use App\Models\Invoice;

final class RevenueSummaryService
{
    private const SOURCE_ASSESSMENT = 'ASSESSMENT';

    private const SOURCE_DIRECT = 'DIRECT_COLLECTION';

    private const COMPLETED_PAYMENT = 'COMPLETED';

    private const CURRENCY_ETB = 'ETB';

    private const OUTSTANDING_STATUSES = [
        'ISSUED',
        'PARTIALLY_PAID',
        'OVERDUE',
    ];

    private const LOG_CHANNEL = 'stack';

    /**
     * Tolerance for comparing PostgreSQL NUMERIC monetary values.
     */
    private const AMOUNT_TOLERANCE = '0.0001';

    public function __construct(
        private readonly AdministrativeScopeService $administrativeScope,
    ) {}

    /**
     * Return revenue totals, period-over-period trends, daily chart
     * data, and outstanding invoice reconciliation diagnostics.
     *
     * Dates are inclusive and must use YYYY-MM-DD.
     *
     * Revenue is based on completed, verified payments and the source
     * type of each associated invoice.
     *
     * All invoice-related calculations are restricted to the
     * authenticated user's accessible administrative units.
     *
     * Outstanding balances use the stored invoice balance_due.
     * Diagnostic calculations never modify invoice records.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    public function summarize(
        User $user,
        string $from,
        string $to,
    ): array {
        $startedAt = hrtime(true);

        $context = [
            'service' => self::class,
            'requested_from' => $from,
            'requested_to' => $to,
            'currency' => self::CURRENCY_ETB,
        ];

        try {
            $fromDate = $this->parseDate($from);
            $toDate = $this->parseDate($to);

            if ($fromDate->greaterThan($toDate)) {
                throw new InvalidArgumentException(
                    'The start date must not be after the end date.',
                );
            }

            $days = (int) $fromDate->diffInDays($toDate) + 1;

            $previousTo = $fromDate->subDay();
            $previousFrom = $previousTo->subDays($days - 1);

            $periodContext = [
                ...$context,
                'period_from' => $fromDate->toDateString(),
                'period_to' => $toDate->toDateString(),
                'period_days' => $days,
                'previous_period_from' => $previousFrom->toDateString(),
                'previous_period_to' => $previousTo->toDateString(),
            ];

            Log::channel(self::LOG_CHANNEL)->info(
                'Revenue summary calculation started.',
                $periodContext,
            );

            $current = $this->collectionTotals(
                $user,
                $fromDate,
                $toDate,
                'current',
            );

            $previous = $this->collectionTotals(
                $user,
                $previousFrom,
                $previousTo,
                'previous',
            );

            $chartSeries = $this->collectionChartSeries(
                $user,
                $fromDate,
                $toDate,
            );

            $outstanding = $this->outstandingSummary($user);

            $combinedTrend = $this->calculateTrend(
                $previous['combined'],
                $current['combined'],
            );

            $assessmentTrend = $this->calculateTrend(
                $previous['assessment'],
                $current['assessment'],
            );

            $directTrend = $this->calculateTrend(
                $previous['direct'],
                $current['direct'],
            );

            $result = [
                'period' => [
                    'from' => $fromDate->toDateString(),
                    'to' => $toDate->toDateString(),
                ],

                'totals' => [
                    'combined' => $current['combined'],
                    'assessment' => $current['assessment'],
                    'direct' => $current['direct'],

                    // Preserve the existing API fields.
                    'combinedTrend' => $combinedTrend['percentage'],
                    'assessmentTrend' => $assessmentTrend['percentage'],
                    'directTrend' => $directTrend['percentage'],

                    // Explain null percentages to the frontend.
                    'combinedTrendStatus' => $combinedTrend['status'],
                    'assessmentTrendStatus' => $assessmentTrend['status'],
                    'directTrendStatus' => $directTrend['status'],
                ],

                'chart' => [
                    'period' => [
                        'from' => $fromDate->toDateString(),
                        'to' => $toDate->toDateString(),
                    ],
                    'currency' => self::CURRENCY_ETB,
                    'interval' => 'day',
                    'series' => $chartSeries,
                ],

                'outstanding' => [
                    // Preserve the existing authoritative balance.
                    'amount' => $outstanding['balance_due'],
                    'breakdown' => $outstanding,
                ],
            ];

            Log::channel(self::LOG_CHANNEL)->info(
                'Revenue summary calculation completed.',
                [
                    ...$periodContext,
                    'current_revenue' => $current,
                    'previous_revenue' => $previous,
                    'chart_days' => count($chartSeries),
                    'outstanding_summary' => $outstanding,
                    'duration_ms' => $this->durationMs($startedAt),
                ],
            );

            return $result;
        } catch (Throwable $exception) {
            Log::channel(self::LOG_CHANNEL)->error(
                'Revenue summary calculation failed.',
                [
                    ...$context,
                    'duration_ms' => $this->durationMs($startedAt),
                    'exception_class' => $exception::class,
                    'exception_message' => $exception->getMessage(),
                ],
            );

            throw $exception;
        }
    }

   /**
     * Build an invoice-ID subquery restricted to the user's
     * accessible administrative units.
     *
     * Uses Eloquent Builder because AdministrativeScopeService
     * requires Illuminate\Database\Eloquent\Builder.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    private function scopedInvoiceIds(
        User $user,
    ): \Illuminate\Database\Eloquent\Builder {
        $query = Invoice::query()
            ->select('invoices.id')
            ->whereNull('invoices.deleted_at');

        $this->administrativeScope->applyAdministrativeScope(
            $query,
            $user,
            'invoices.administrative_unit_id',
        );

        return $query;
    }

    /**
     * Calculate collected revenue by invoice source for a date range.
     *
     * @return array{
     *     combined: float,
     *     assessment: float,
     *     direct: float
     * }
     */
    private function collectionTotals(
        User $user,
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $periodType,
    ): array {
        $startedAt = hrtime(true);

        $context = [
            'service' => self::class,
            'operation' => 'collection_totals',
            'period_type' => $periodType,
            'period_from' => $from->toDateString(),
            'period_to' => $to->toDateString(),
            'currency' => self::CURRENCY_ETB,
        ];

        try {
            $rows = DB::table('payments')
                ->join(
                    'invoices',
                    'invoices.id',
                    '=',
                    'payments.invoice_id',
                )
                ->whereIn(
                    'invoices.id',
                    $this->scopedInvoiceIds($user),
                )
                ->where(
                    'payments.status',
                    self::COMPLETED_PAYMENT,
                )
                ->whereNotNull('payments.verified_at')
                ->where(
                    'payments.verified_at',
                    '>=',
                    $from->startOfDay(),
                )
                ->where(
                    'payments.verified_at',
                    '<',
                    $to->addDay()->startOfDay(),
                )
                ->where(
                    'payments.currency',
                    self::CURRENCY_ETB,
                )
                ->where(
                    'invoices.currency',
                    self::CURRENCY_ETB,
                )
                ->whereIn('invoices.source_type', [
                    self::SOURCE_ASSESSMENT,
                    self::SOURCE_DIRECT,
                ])
                ->selectRaw(
                    'invoices.source_type,
                     COALESCE(SUM(payments.amount), 0) AS total',
                )
                ->groupBy('invoices.source_type')
                ->get()
                ->keyBy('source_type');

            $assessment = (float) (
                $rows->get(self::SOURCE_ASSESSMENT)?->total ?? 0
            );

            $direct = (float) (
                $rows->get(self::SOURCE_DIRECT)?->total ?? 0
            );

            $totals = [
                'combined' => round($assessment + $direct, 4),
                'assessment' => round($assessment, 4),
                'direct' => round($direct, 4),
            ];

            Log::channel(self::LOG_CHANNEL)->debug(
                'Completed payment totals calculated.',
                [
                    ...$context,
                    'totals' => $totals,
                    'duration_ms' => $this->durationMs($startedAt),
                ],
            );

            return $totals;
        } catch (Throwable $exception) {
            Log::channel(self::LOG_CHANNEL)->error(
                'Failed to calculate completed payment totals.',
                [
                    ...$context,
                    'duration_ms' => $this->durationMs($startedAt),
                    'exception_class' => $exception::class,
                    'exception_message' => $exception->getMessage(),
                ],
            );

            throw $exception;
        }
    }

    /**
     * Build daily revenue chart data for the selected period.
     *
     * Each calendar date is included, even when revenue is zero.
     *
     * @return list<array{
     *     date: string,
     *     assessment: float,
     *     direct: float,
     *     combined: float
     * }>
     */
    private function collectionChartSeries(
        User $user,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): array {
        $startedAt = hrtime(true);

        $context = [
            'service' => self::class,
            'operation' => 'collection_chart_series',
            'period_from' => $from->toDateString(),
            'period_to' => $to->toDateString(),
            'currency' => self::CURRENCY_ETB,
        ];

        try {
            $rows = DB::table('payments')
                ->join(
                    'invoices',
                    'invoices.id',
                    '=',
                    'payments.invoice_id',
                )
                ->whereIn(
                    'invoices.id',
                    $this->scopedInvoiceIds($user),
                )
                ->where(
                    'payments.status',
                    self::COMPLETED_PAYMENT,
                )
                ->whereNotNull('payments.verified_at')
                ->where(
                    'payments.verified_at',
                    '>=',
                    $from->startOfDay(),
                )
                ->where(
                    'payments.verified_at',
                    '<',
                    $to->addDay()->startOfDay(),
                )
                ->where(
                    'payments.currency',
                    self::CURRENCY_ETB,
                )
                ->where(
                    'invoices.currency',
                    self::CURRENCY_ETB,
                )
                ->whereIn('invoices.source_type', [
                    self::SOURCE_ASSESSMENT,
                    self::SOURCE_DIRECT,
                ])
                ->selectRaw("
                    DATE(payments.verified_at) AS revenue_date,
                    invoices.source_type,
                    COALESCE(SUM(payments.amount), 0) AS total
                ")
                ->groupByRaw(
                    'DATE(payments.verified_at), invoices.source_type',
                )
                ->orderBy('revenue_date')
                ->get();

            $dailyTotals = [];

            foreach ($rows as $row) {
                $date = (string) $row->revenue_date;

                $dailyTotals[$date] ??= [
                    'assessment' => 0.0,
                    'direct' => 0.0,
                ];

                if ($row->source_type === self::SOURCE_ASSESSMENT) {
                    $dailyTotals[$date]['assessment'] = (float) $row->total;
                } elseif ($row->source_type === self::SOURCE_DIRECT) {
                    $dailyTotals[$date]['direct'] = (float) $row->total;
                }
            }

            $series = [];

            for (
                $date = $from;
                $date->lessThanOrEqualTo($to);
                $date = $date->addDay()
            ) {
                $dateKey = $date->toDateString();

                $assessment = $dailyTotals[$dateKey]['assessment'] ?? 0.0;
                $direct = $dailyTotals[$dateKey]['direct'] ?? 0.0;

                $series[] = [
                    'date' => $dateKey,
                    'assessment' => round($assessment, 4),
                    'direct' => round($direct, 4),
                    'combined' => round($assessment + $direct, 4),
                ];
            }

            Log::channel(self::LOG_CHANNEL)->debug(
                'Revenue chart series calculated.',
                [
                    ...$context,
                    'days_in_series' => count($series),
                    'days_with_revenue' => count($dailyTotals),
                    'duration_ms' => $this->durationMs($startedAt),
                ],
            );

            return $series;
        } catch (Throwable $exception) {
            Log::channel(self::LOG_CHANNEL)->error(
                'Failed to calculate revenue chart series.',
                [
                    ...$context,
                    'duration_ms' => $this->durationMs($startedAt),
                    'exception_class' => $exception::class,
                    'exception_message' => $exception->getMessage(),
                ],
            );

            throw $exception;
        }
    }

    /**
     * Calculate outstanding invoice balances and reconciliation metrics.
     *
     * The diagnostic formula is:
     * subtotal - discount + penalty + interest.
     *
     * Verify that this formula matches your invoice issuance rules
     * before treating its variances as accounting errors.
     *
     * @return array<string, int|float>
     */
    private function outstandingSummary(User $user): array
    {
        $startedAt = hrtime(true);

        $context = [
            'service' => self::class,
            'operation' => 'outstanding_summary',
            'statuses' => self::OUTSTANDING_STATUSES,
            'currency' => self::CURRENCY_ETB,
        ];

        try {
            $calculatedTotal = '
                COALESCE(subtotal, 0)
                - COALESCE(discount_amount, 0)
                + COALESCE(penalty_amount, 0)
                + COALESCE(interest_amount, 0)
            ';

            $totalVariance = "
                COALESCE(total_amount, 0) - ({$calculatedTotal})
            ";

            $balanceVariance = '
                COALESCE(balance_due, 0)
                - (
                    COALESCE(total_amount, 0)
                    - COALESCE(paid_amount, 0)
                )
            ';

            $tolerance = self::AMOUNT_TOLERANCE;

            $row = DB::table('invoices')
                ->whereIn(
                    'invoices.id',
                    $this->scopedInvoiceIds($user),
                )
                ->where('invoices.currency', self::CURRENCY_ETB)
                ->whereIn('invoices.status', self::OUTSTANDING_STATUSES)
                ->where('invoices.balance_due', '>', 0)
                ->selectRaw("
                    COUNT(*) AS invoice_count,
                    COALESCE(SUM(subtotal), 0) AS subtotal,
                    COALESCE(SUM(discount_amount), 0) AS discount_amount,
                    COALESCE(SUM(penalty_amount), 0) AS penalty_amount,
                    COALESCE(SUM(interest_amount), 0) AS interest_amount,
                    COALESCE(SUM({$calculatedTotal}), 0) AS calculated_total,
                    COALESCE(SUM(total_amount), 0) AS total_amount,
                    COALESCE(SUM(paid_amount), 0) AS paid_amount,
                    COALESCE(SUM(balance_due), 0) AS balance_due,
                    COALESCE(SUM({$totalVariance}), 0)
                        AS total_amount_variance,

                    COUNT(*) FILTER (
                        WHERE ABS({$totalVariance}) >= ?
                    ) AS inconsistent_invoice_count,

                    COALESCE(
                        MAX(ABS({$totalVariance})),
                        0
                    ) AS max_abs_invoice_variance,

                    COUNT(*) FILTER (
                        WHERE ABS({$balanceVariance}) >= ?
                    ) AS balance_mismatch_count,

                    COALESCE(
                        MAX(ABS({$balanceVariance})),
                        0
                    ) AS max_abs_balance_variance
                ", [$tolerance, $tolerance])
                ->first();

            $summary = [
                'invoice_count' => (int) ($row->invoice_count ?? 0),
                'subtotal' => (float) ($row->subtotal ?? 0),
                'discount_amount' => (float) ($row->discount_amount ?? 0),
                'penalty_amount' => (float) ($row->penalty_amount ?? 0),
                'interest_amount' => (float) ($row->interest_amount ?? 0),
                'calculated_total' => (float) ($row->calculated_total ?? 0),
                'total_amount' => (float) ($row->total_amount ?? 0),
                'paid_amount' => (float) ($row->paid_amount ?? 0),
                'balance_due' => (float) ($row->balance_due ?? 0),
                'total_amount_variance' => (float) (
                    $row->total_amount_variance ?? 0
                ),
                'inconsistent_invoice_count' => (int) (
                    $row->inconsistent_invoice_count ?? 0
                ),
                'max_abs_invoice_variance' => (float) (
                    $row->max_abs_invoice_variance ?? 0
                ),
                'balance_mismatch_count' => (int) (
                    $row->balance_mismatch_count ?? 0
                ),
                'max_abs_balance_variance' => (float) (
                    $row->max_abs_balance_variance ?? 0
                ),
            ];

            if ($summary['inconsistent_invoice_count'] > 0) {
                Log::channel(self::LOG_CHANNEL)->warning(
                    'Outstanding invoices have total calculation variances.',
                    [
                        ...$context,
                        'inconsistent_invoice_count' => $summary[
                            'inconsistent_invoice_count'
                        ],
                        'aggregate_total_variance' => $summary[
                            'total_amount_variance'
                        ],
                        'max_abs_invoice_variance' => $summary[
                            'max_abs_invoice_variance'
                        ],
                    ],
                );
            }

            if ($summary['balance_mismatch_count'] > 0) {
                Log::channel(self::LOG_CHANNEL)->warning(
                    'Outstanding invoices have balance reconciliation variances.',
                    [
                        ...$context,
                        'balance_mismatch_count' => $summary[
                            'balance_mismatch_count'
                        ],
                        'max_abs_balance_variance' => $summary[
                            'max_abs_balance_variance'
                        ],
                    ],
                );
            }

            Log::channel(self::LOG_CHANNEL)->info(
                'Outstanding invoice summary calculated.',
                [
                    ...$context,
                    'summary' => $summary,
                    'duration_ms' => $this->durationMs($startedAt),
                ],
            );

            return $summary;
        } catch (Throwable $exception) {
            Log::channel(self::LOG_CHANNEL)->error(
                'Failed to calculate outstanding invoice summary.',
                [
                    ...$context,
                    'duration_ms' => $this->durationMs($startedAt),
                    'exception_class' => $exception::class,
                    'exception_message' => $exception->getMessage(),
                ],
            );

            throw $exception;
        }
    }

    /**
     * Calculate period-over-period percentage change.
     *
     * A percentage is undefined when previous revenue is zero and
     * current revenue is positive. In that case, return null and
     * NEW_REVENUE so the frontend can display an appropriate label.
     *
     * @return array{percentage: ?float, status: string}
     */
    private function calculateTrend(
        float $previous,
        float $current,
    ): array {
        if (
            ! is_finite($previous)
            || ! is_finite($current)
            || $previous < 0
            || $current < 0
        ) {
            throw new InvalidArgumentException(
                'Revenue amounts must be finite and non-negative.',
            );
        }

        if ($previous == 0.0) {
            if ($current == 0.0) {
                return [
                    'percentage' => 0.0,
                    'status' => 'NO_REVENUE',
                ];
            }

            return [
                'percentage' => null,
                'status' => 'NEW_REVENUE',
            ];
        }

        $percentage = round(
            (($current - $previous) / $previous) * 100,
            2,
        );

        if ($current > $previous) {
            $status = 'INCREASED';
        } elseif ($current < $previous) {
            $status = 'DECREASED';
        } else {
            $status = 'UNCHANGED';
        }

        return [
            'percentage' => $percentage,
            'status' => $status,
        ];
    }

    /**
     * Strictly parse YYYY-MM-DD and reject invalid calendar dates.
     */
    private function parseDate(string $value): CarbonImmutable
    {
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException(
                'Dates must use the YYYY-MM-DD format and be valid calendar dates.',
                previous: $exception,
            );
        }

        if (
            $date === false
            || $date->toDateString() !== $value
        ) {
            throw new InvalidArgumentException(
                'Dates must use the YYYY-MM-DD format and be valid calendar dates.',
            );
        }

        return $date;
    }

    /**
     * Return elapsed execution time in milliseconds.
     */
    private function durationMs(int $startedAt): float
    {
        return round(
            (hrtime(true) - $startedAt) / 1_000_000,
            2,
        );
    }
}