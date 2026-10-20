<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class DashboardService
{
    public function __construct(
        private readonly RevenueSummaryService $revenueSummaryService,
        private readonly SystemStatisticsService $systemStatisticsService,
        private readonly DashboardWorkQueueService $dashboardWorkQueueService,
        private readonly DashboardRecentActivityService $dashboardRecentActivityService,
    ) {
    }

    /**
     * Build the dashboard summary.
     *
     * @return array<string, mixed>
     */
    public function summarize(
        ?string $from = null,
        ?string $to = null,
    ): array {
        [$from, $to] = $this->resolvePeriod($from, $to);

        return [
            'revenue' => $this->revenueSummaryService->summarize(
                $from,
                $to,
            ),

            'systemStatistics' => $this->systemStatisticsService->summarize(),

            'workQueue' => $this->dashboardWorkQueueService->summarize(),

            'recentActivity' => $this->dashboardRecentActivityService->summarize(),
        ];
    }

    /**
     * Resolve a complete reporting period.
     *
     * If no dates are supplied, default to the current calendar month.
     * If only one date is supplied, reject the request.
     *
     * @return array{0: string, 1: string}
     */
    private function resolvePeriod(
        ?string $from,
        ?string $to,
    ): array {
        if (($from === null) !== ($to === null)) {
            throw new InvalidArgumentException(
                'Both from and to dates must be supplied together.'
            );
        }

        if ($from === null && $to === null) {
            $today = CarbonImmutable::today();

            return [
                $today->startOfMonth()->toDateString(),
                $today->toDateString(),
            ];
        }

        $start = $this->parseDate($from);
        $end = $this->parseDate($to);

        if ($start->greaterThan($end)) {
            throw new InvalidArgumentException(
                'The start date must not be after the end date.'
            );
        }

        if ($start->diffInDays($end) > 365) {
            throw new InvalidArgumentException(
                'The reporting period cannot exceed 366 calendar days.'
            );
        }

        return [
            $start->toDateString(),
            $end->toDateString(),
        ];
    }

    private function parseDate(?string $value): CarbonImmutable
    {
        if ($value === null) {
            throw new InvalidArgumentException(
                'A reporting date is required.'
            );
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        if (
            $date === false
            || $date->toDateString() !== $value
        ) {
            throw new InvalidArgumentException(
                'Dates must use the YYYY-MM-DD format.'
            );
        }

        return $date;
    }
}
