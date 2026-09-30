<?php

namespace App\Modules\Taxpayer\Services;

use App\Models\Payment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class TaxpayerPaymentService
{
    public function paginate(
        string $citizenId,
        int $perPage = 15
    ): LengthAwarePaginator {
        return Payment::query()
            ->where('citizen_id', $citizenId)
            ->with([
                'invoice',
            ])
            ->latest('created_at')
            ->paginate($perPage);
    }

    public function findForTaxpayer(
        string $citizenId,
        string $paymentId
    ): Payment {
        $payment = Payment::query()
            ->where('id', $paymentId)
            ->where('citizen_id', $citizenId)
            ->with([
                'invoice',
            ])
            ->first();

        if (! $payment) {
            throw new ModelNotFoundException();
        }

        return $payment;
    }
}