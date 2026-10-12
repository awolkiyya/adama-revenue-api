<?php

namespace App\Modules\Agent\Services;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class AgentPaymentService
{
    /**
     * Retrieve payments processed by the authenticated agent.
     */
    public function paginate(
        ?User $agent,
        int $perPage = 15,
        ?string $search = null,
        ?string $status = null,
    ): LengthAwarePaginator {
        $agent = $this->requireAgent($agent);

        $query = $this->agentPaymentsQuery($agent)
            ->with([
                'invoice',
                'citizen',
            ]);

        if ($status !== null && trim($status) !== '') {
            $query->where('status', strtoupper(trim($status)));
        }

        if ($search !== null && trim($search) !== '') {
            $term = trim($search);

            $query->where(function (Builder $builder) use ($term): void {
                $builder
                    ->where('payment_number', 'like', "%{$term}%")
                    ->orWhere('transaction_reference', 'like', "%{$term}%")
                    ->orWhere('provider_reference', 'like', "%{$term}%")
                    ->orWhere('payer_name', 'like', "%{$term}%")
                    ->orWhere('payer_phone', 'like', "%{$term}%")
                    ->orWhereHas('invoice', function (
                        Builder $invoiceQuery
                    ) use ($term): void {
                        $invoiceQuery->where(
                            'invoice_number',
                            'like',
                            "%{$term}%"
                        );
                    });
            });
        }

        return $query
            ->latest('created_at')
            ->paginate(max(1, min($perPage, 100)))
            ->withQueryString();
    }

    /**
     * Return overall payment statistics for the authenticated agent.
     *
     * Only completed payments contribute to total_collected.
     */
    public function summary(?User $agent): array
    {
        $agent = $this->requireAgent($agent);

        return $this->aggregatePayments(
            query: $this->agentPaymentsQuery($agent),
        );
    }

    /**
     * Return statistics for payments created today.
     *
     * The reporting period is based on created_at and the Laravel
     * application timezone. Collected amounts include completed
     * payments created during this period.
     */
    public function todaySummary(?User $agent): array
    {
        $agent = $this->requireAgent($agent);

        $now = Carbon::now();

        $query = $this->agentPaymentsQuery($agent)
            ->whereBetween('created_at', [
                $now->copy()->startOfDay(),
                $now->copy()->endOfDay(),
            ]);

        return [
            'date' => $now->toDateString(),
            ...$this->aggregatePayments(query: $query),
        ];
    }

    /**
     * Return combined dashboard data for the authenticated agent.
     *
     * Includes:
     * - Overall payment statistics.
     * - Today's payment statistics.
     * - The most recent payments, newest first.
     */
    public function dashboard(
        ?User $agent,
        int $recentLimit = 5,
    ): array {
        $agent = $this->requireAgent($agent);

        $recentLimit = max(1, min($recentLimit, 20));

        $now = Carbon::now();

        $overallQuery = $this->agentPaymentsQuery($agent);

        $todayQuery = $this->agentPaymentsQuery($agent)
            ->whereBetween('created_at', [
                $now->copy()->startOfDay(),
                $now->copy()->endOfDay(),
            ]);

        $recentPayments = $this->agentPaymentsQuery($agent)
            ->with([
                'invoice',
                'citizen',
            ])
            ->latest('created_at')
            ->limit($recentLimit)
            ->get();

        return [
            'date' => $now->toDateString(),

            'overall' => $this->aggregatePayments(
                query: $overallQuery,
            ),

            'today' => [
                'date' => $now->toDateString(),
                ...$this->aggregatePayments(
                    query: $todayQuery,
                ),
            ],

            'recent_payments' => $recentPayments,
        ];
    }

    /**
     * Aggregate payment counts and completed payment amounts.
     *
     * The supplied query must already be scoped to the agent.
     *
     * @param Builder<Payment> $query
     * @return array<string, int|string>
     */
    private function aggregatePayments(Builder $query): array
    {
        $completed = PaymentStatus::COMPLETED->value;
        $pending = PaymentStatus::PENDING->value;
        $processing = PaymentStatus::PROCESSING->value;
        $failed = PaymentStatus::FAILED->value;
        $cancelled = PaymentStatus::CANCELLED->value;
        $reversed = PaymentStatus::REVERSED->value;

        $result = (clone $query)
            ->selectRaw('COUNT(*) as total_payments')
            ->selectRaw(
                'COALESCE(
                    SUM(CASE WHEN status = ? THEN amount ELSE 0 END),
                    0
                ) as total_collected',
                [$completed],
            )
            ->selectRaw(
                'COALESCE(
                    SUM(CASE WHEN status = ? THEN 1 ELSE 0 END),
                    0
                ) as completed_count',
                [$completed],
            )
            ->selectRaw(
                'COALESCE(
                    SUM(CASE WHEN status = ? THEN 1 ELSE 0 END),
                    0
                ) as pending_count',
                [$pending],
            )
            ->selectRaw(
                'COALESCE(
                    SUM(CASE WHEN status = ? THEN 1 ELSE 0 END),
                    0
                ) as processing_count',
                [$processing],
            )
            ->selectRaw(
                'COALESCE(
                    SUM(CASE WHEN status = ? THEN 1 ELSE 0 END),
                    0
                ) as failed_count',
                [$failed],
            )
            ->selectRaw(
                'COALESCE(
                    SUM(CASE WHEN status = ? THEN 1 ELSE 0 END),
                    0
                ) as cancelled_count',
                [$cancelled],
            )
            ->selectRaw(
                'COALESCE(
                    SUM(CASE WHEN status = ? THEN 1 ELSE 0 END),
                    0
                ) as reversed_count',
                [$reversed],
            )
            ->first();

        return [
            'total_payments' => (int) $result->total_payments,

            'total_collected' => number_format(
                (float) $result->total_collected,
                2,
                '.',
                '',
            ),

            'completed_count' => (int) $result->completed_count,
            'pending_count' => (int) $result->pending_count,
            'processing_count' => (int) $result->processing_count,
            'failed_count' => (int) $result->failed_count,
            'cancelled_count' => (int) $result->cancelled_count,
            'reversed_count' => (int) $result->reversed_count,

            'currency' => 'ETB',
        ];
    }

    /**
     * Build the base query scoped to the authenticated agent.
     */
    private function agentPaymentsQuery(User $agent): Builder
    {
        return Payment::query()
            ->where('processed_by', $agent->getKey());
    }

    /**
     * Require an authenticated user.
     */
    private function requireAgent(?User $agent): User
    {
        if ($agent === null) {
            throw ValidationException::withMessages([
                'agent' => 'An authenticated user is required.',
            ]);
        }

        return $agent;
    }
}