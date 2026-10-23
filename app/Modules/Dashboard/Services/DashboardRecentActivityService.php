<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services;

use App\Models\Invoice;
use App\Models\User;
use App\Services\Authorization\AdministrativeScopeService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class DashboardRecentActivityService
{
    private const ACTIVITY_LIMIT = 8;

    public function __construct(
        private readonly AdministrativeScopeService $administrativeScope,
    ) {}

    /**
     * Retrieve the latest payment activities for the dashboard,
     * restricted to the user's accessible administrative units.
     *
     * Uses verified_at when available, falling back to created_at.
     * The activity list is independent of the revenue reporting period.
     *
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
    public function summarize(User $user): array
    {
        $payments = DB::table('payments')
            ->join(
                'invoices',
                'invoices.id',
                '=',
                'payments.invoice_id',
            )
            ->whereNull('invoices.deleted_at')
            ->whereIn(
                'invoices.id',
                $this->scopedInvoiceIds($user),
            )
            ->select([
                'payments.id',
                'payments.payment_number',
                'payments.payment_method',
                'payments.amount',
                'payments.currency',
                'payments.status',
                'payments.verified_at',
                'payments.created_at',
                'invoices.invoice_number',
                'invoices.source_type',
            ])
            ->orderByRaw(
                'COALESCE(
                    payments.verified_at,
                    payments.created_at
                ) DESC NULLS LAST'
            )
            ->orderByDesc('payments.id')
            ->limit(self::ACTIVITY_LIMIT)
            ->get();

        return [
            'items' => $payments
                ->map(
                    fn (object $payment): array => $this->transform($payment),
                )
                ->values()
                ->all(),
        ];
    }

    /**
     * Build an Eloquent invoice-ID subquery scoped to the user's
     * accessible administrative units.
     *
     * @return Builder<Invoice>
     */
    private function scopedInvoiceIds(User $user): Builder
    {
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
     * Normalize a payment into the dashboard activity response.
     *
     * @return array{
     *     reference: string,
     *     title: string,
     *     description: string,
     *     status: string,
     *     source: string,
     *     occurredAt: string
     * }
     */
    private function transform(object $payment): array
    {
        $source = match ($payment->source_type) {
            'ASSESSMENT' => 'ASSESSMENT',
            'DIRECT_COLLECTION' => 'DIRECT',
            default => 'DIRECT',
        };

        $sourceLabel = match ($source) {
            'ASSESSMENT' => 'Assessment',
            default => 'Direct collection',
        };

        $status = (string) $payment->status;

        $title = match ($status) {
            'COMPLETED' => "{$sourceLabel} payment completed",
            'PENDING' => "{$sourceLabel} payment pending",
            'PROCESSING' => "{$sourceLabel} payment processing",
            'INITIATED' => "{$sourceLabel} payment initiated",
            'FAILED' => "{$sourceLabel} payment failed",
            'REVERSED' => "{$sourceLabel} payment reversed",
            'CANCELLED' => "{$sourceLabel} payment cancelled",
            default => "{$sourceLabel} payment recorded",
        };

        $amount = number_format(
            (float) $payment->amount,
            2,
            '.',
            ',',
        );

        $currency = (string) ($payment->currency ?: 'ETB');

        $description = sprintf(
            '%s %s via %s · Invoice %s',
            $currency,
            $amount,
            (string) $payment->payment_method,
            (string) $payment->invoice_number,
        );

        $occurredAt = $payment->verified_at
            ?? $payment->created_at;

        return [
            'reference' => (string) $payment->payment_number,
            'title' => $title,
            'description' => $description,
            'status' => $status,
            'source' => $source,
            'occurredAt' => CarbonImmutable::parse(
                $occurredAt,
            )->toIso8601String(),
        ];
    }
}
