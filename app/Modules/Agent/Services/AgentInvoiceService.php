<?php

namespace App\Modules\Agent\Services;

use App\Models\Invoice;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class AgentInvoiceService
{
    /**
     * Get pending invoices that an agent can help pay.
     *
     * An invoice is never assigned to an agent.
     * The authenticated agent is recorded only when
     * the payment is actually created.
     */
    public function pending(
        int $perPage = 15,
        ?string $search = null,
    ): LengthAwarePaginator {
        return Invoice::query()
            ->whereIn('status', [
                'ISSUED',
                'PARTIALLY_PAID',
                'OVERDUE',
            ])
            ->when(
                $search,
                function ($query) use ($search) {
                    $query->where(function ($query) use ($search) {
                        $query
                            ->where(
                                'invoice_number',
                                'like',
                                "%{$search}%"
                            )
                            ->orWhereHas(
                                'citizen',
                                function ($query) use ($search) {
                                    $query
                                        ->where(
                                            'full_name',
                                            'like',
                                            "%{$search}%"
                                        )
                                        ->orWhere(
                                            'phone',
                                            'like',
                                            "%{$search}%"
                                        )
                                        ->orWhere(
                                            'citizen_uid',
                                            'like',
                                            "%{$search}%"
                                        )
                                        ->orWhere(
                                            'national_id',
                                            'like',
                                            "%{$search}%"
                                        );
                                }
                            );
                    });
                }
            )
            ->with([
                'citizen',
                'items.service',
            ])
            ->latest('issued_at')
            ->paginate($perPage);
    }
}