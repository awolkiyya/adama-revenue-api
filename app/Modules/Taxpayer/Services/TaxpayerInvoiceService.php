<?php

namespace App\Modules\Taxpayer\Services;

use App\Models\Invoice;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class TaxpayerInvoiceService
{
    /**
     * Get invoices belonging only to the authenticated taxpayer.
     */
    public function paginate(
        string $citizenId,
        int $perPage = 15,
        ?string $status = null
    ): LengthAwarePaginator {
        return Invoice::query()
            ->where('citizen_id', $citizenId)

            ->when(
                $status,
                fn ($query) => $query->where('status', $status)
            )

            ->with([
                'items.service',
            ])

            ->latest('created_at')

            ->paginate($perPage);
    }

    /**
     * Get one invoice belonging to the taxpayer.
     *
     * Never query the invoice by ID alone.
     */
    public function findForTaxpayer(
        string $citizenId,
        string $invoiceId
    ): Invoice {
        $invoice = Invoice::query()
            ->where('id', $invoiceId)
            ->where('citizen_id', $citizenId)
            ->with([
                'items.service',
                'assessment',
            ])
            ->first();

        if (! $invoice) {
            throw new ModelNotFoundException();
        }

        return $invoice;
    }
}