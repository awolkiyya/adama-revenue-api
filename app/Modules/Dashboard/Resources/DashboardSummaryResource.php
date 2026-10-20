<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Transform the dashboard summary into its public API response.
 *
 * @property array<string, mixed> $resource
 */
final class DashboardSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $revenue = $this->resource['revenue'] ?? [];

        $totals = $revenue['totals'] ?? [];
        $period = $revenue['period'] ?? [];
        $chart = $revenue['chart'] ?? [];
        $outstanding = $revenue['outstanding'] ?? [];

        $statistics = $this->resource['systemStatistics'] ?? [];
        $workQueue = $this->resource['workQueue'] ?? [];
        $recentActivity = $this->resource['recentActivity'] ?? [];

        $chartPeriod = $chart['period'] ?? $period;
        $chartSeries = $chart['series'] ?? [];

        return [
            'revenue' => [
                'period' => [
                    'from' => $period['from'] ?? null,
                    'to' => $period['to'] ?? null,
                ],

                'totals' => [
                    'combined' => (float) ($totals['combined'] ?? 0),
                    'assessment' => (float) ($totals['assessment'] ?? 0),
                    'direct' => (float) ($totals['direct'] ?? 0),

                    'combinedTrend' => $this->nullableFloat(
                        $totals['combinedTrend'] ?? null,
                    ),

                    'assessmentTrend' => $this->nullableFloat(
                        $totals['assessmentTrend'] ?? null,
                    ),

                    'directTrend' => $this->nullableFloat(
                        $totals['directTrend'] ?? null,
                    ),

                    'combinedTrendStatus' => $totals[
                        'combinedTrendStatus'
                    ] ?? null,

                    'assessmentTrendStatus' => $totals[
                        'assessmentTrendStatus'
                    ] ?? null,

                    'directTrendStatus' => $totals[
                        'directTrendStatus'
                    ] ?? null,
                ],

                'chart' => [
                    'period' => [
                        'from' => $chartPeriod['from'] ?? null,
                        'to' => $chartPeriod['to'] ?? null,
                    ],

                    'currency' => $chart['currency'] ?? 'ETB',
                    'interval' => $chart['interval'] ?? 'day',

                    'series' => $this->normalizeChartSeries(
                        $chartSeries,
                    ),
                ],

                'outstanding' => [
                    'amount' => (float) ($outstanding['amount'] ?? 0),
                ],
            ],

            'systemStatistics' => [
                'totalUsers' => (int) (
                    $statistics['totalUsers'] ?? 0
                ),

                'totalTaxpayers' => (int) (
                    $statistics['totalTaxpayers'] ?? 0
                ),

                'totalAgents' => (int) (
                    $statistics['totalAgents'] ?? 0
                ),

                'totalAssessments' => (int) (
                    $statistics['totalAssessments'] ?? 0
                ),

                'totalAssessmentInvoices' => (int) (
                    $statistics['totalAssessmentInvoices'] ?? 0
                ),

                'totalDirectCollectionInvoices' => (int) (
                    $statistics['totalDirectCollectionInvoices'] ?? 0
                ),
            ],

            'workQueue' => $this->normalizeWorkQueue($workQueue),

            'recentActivity' => $this->normalizeRecentActivity(
                $recentActivity,
            ),
        ];
    }

    /**
     * Normalize daily chart points for a stable frontend contract.
     *
     * @param mixed $series
     * @return list<array{
     *     date: string,
     *     assessment: float,
     *     direct: float,
     *     combined: float
     * }>
     */
    private function normalizeChartSeries(mixed $series): array
    {
        if (! is_array($series)) {
            return [];
        }

        $normalized = [];

        foreach ($series as $point) {
            if (
                ! is_array($point)
                || ! isset($point['date'])
                || ! is_string($point['date'])
            ) {
                continue;
            }

            $assessment = (float) ($point['assessment'] ?? 0);
            $direct = (float) ($point['direct'] ?? 0);

            $normalized[] = [
                'date' => $point['date'],
                'assessment' => $assessment,
                'direct' => $direct,
                'combined' => (float) (
                    $point['combined'] ?? ($assessment + $direct)
                ),
            ];
        }

        return $normalized;
    }

    /**
     * Normalize the work queue.
     *
     * The service is responsible for authorization and database counts.
     * This resource validates and shapes the public response.
     *
     * @param mixed $workQueue
     * @return array{
     *     total: int,
     *     items: list<array{
     *         key: string,
     *         title: string,
     *         description: string,
     *         count: int,
     *         href: string
     *     }>
     * }
     */
    private function normalizeWorkQueue(mixed $workQueue): array
    {
        if (! is_array($workQueue)) {
            return [
                'total' => 0,
                'items' => [],
            ];
        }

        $items = [];
        $calculatedTotal = 0;
        $seenKeys = [];

        $rawItems = $workQueue['items'] ?? [];

        if (is_array($rawItems)) {
            foreach ($rawItems as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $key = $item['key'] ?? null;
                $title = $item['title'] ?? null;
                $description = $item['description'] ?? null;
                $count = $item['count'] ?? null;
                $href = $item['href'] ?? null;

                if (
                    ! is_string($key)
                    || $key === ''
                    || isset($seenKeys[$key])
                    || ! is_string($title)
                    || ! is_string($description)
                    || ! is_numeric($count)
                    || ! is_string($href)
                    || ! $this->isSafeInternalPath($href)
                ) {
                    continue;
                }

                // Validate the original numeric value before casting.
                $numericCount = (float) $count;

                if (
                    ! is_finite($numericCount)
                    || $numericCount <= 0
                    || floor($numericCount) !== $numericCount
                    || $numericCount > PHP_INT_MAX
                ) {
                    continue;
                }

                $count = (int) $numericCount;

                $seenKeys[$key] = true;

                $items[] = [
                    'key' => $key,
                    'title' => $title,
                    'description' => $description,
                    'count' => $count,
                    'href' => $href,
                ];

                $calculatedTotal += $count;
            }
        }

        return [
            'total' => $calculatedTotal,
            'items' => $items,
        ];
    }

    /**
     * Normalize recent payment activity for the dashboard.
     *
     * Activity is supplied by DashboardRecentActivityService.
     * This resource validates the public response contract.
     *
     * @param mixed $recentActivity
     * @return array{
     *     items: list<array{
     *         reference: string,
     *         title: string,
     *         description: string,
     *         status: string,
     *         source: string,
     *         occurredAt: string
     *     }>
     * }
     */
    private function normalizeRecentActivity(mixed $recentActivity): array
    {
        if (! is_array($recentActivity)) {
            return ['items' => []];
        }

        $rawItems = $recentActivity['items'] ?? [];

        if (! is_array($rawItems)) {
            return ['items' => []];
        }

        $allowedStatuses = [
            'INITIATED',
            'PENDING',
            'COMPLETED',
            'FAILED',
            'REVERSED',
            'CANCELLED',
        ];

        $allowedSources = [
            'ASSESSMENT',
            'DIRECT',
        ];

        $items = [];
        $seenReferences = [];

        foreach ($rawItems as $item) {
            if (! is_array($item)) {
                continue;
            }

            $reference = $item['reference'] ?? null;
            $title = $item['title'] ?? null;
            $description = $item['description'] ?? null;
            $status = $item['status'] ?? null;
            $source = $item['source'] ?? null;
            $occurredAt = $item['occurredAt'] ?? null;

            if (
                ! is_string($reference)
                || trim($reference) === ''
                || isset($seenReferences[$reference])
                || ! is_string($title)
                || trim($title) === ''
                || ! is_string($description)
                || ! is_string($status)
                || ! in_array($status, $allowedStatuses, true)
                || ! is_string($source)
                || ! in_array($source, $allowedSources, true)
                || ! is_string($occurredAt)
                || trim($occurredAt) === ''
            ) {
                continue;
            }

            // Require a valid ISO 8601-compatible timestamp.
            try {
                $date = new \DateTimeImmutable($occurredAt);
            } catch (\Throwable) {
                continue;
            }

            $seenReferences[$reference] = true;

            $items[] = [
                'reference' => $reference,
                'title' => $title,
                'description' => $description,
                'status' => $status,
                'source' => $source,
                'occurredAt' => $date->format(\DateTimeInterface::ATOM),
            ];
        }

        return [
            'items' => $items,
        ];
    }

    /**
     * Allow only application-relative paths, not external or
     * protocol-relative URLs.
     */
    private function isSafeInternalPath(string $href): bool
    {
        return str_starts_with($href, '/')
            && ! str_starts_with($href, '//')
            && ! str_contains($href, '\\')
            && ! preg_match('/[\x00-\x20\x7f]/', $href)
            && ! preg_match('/^[a-z][a-z0-9+.-]*:/i', $href);
    }

    /**
     * Preserve null for undefined percentage changes.
     */
    private function nullableFloat(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
