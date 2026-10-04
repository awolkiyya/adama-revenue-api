<?php

namespace App\Modules\Revenue\Services;

use App\Models\PaymentProvider;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PaymentProviderService
{
    public function paginate(
        int $perPage = 15,
        ?string $search = null,
        ?bool $isActive = null
    ): LengthAwarePaginator {
        return PaymentProvider::query()
            ->when($search, function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query
                        ->where('code', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%");
                });
            })
            ->when($isActive !== null, function ($query) use ($isActive) {
                $query->where('is_active', $isActive);
            })
            ->orderBy('name')
            ->paginate($perPage);
    }

    public function find(string $id): PaymentProvider
    {
        return PaymentProvider::query()->findOrFail($id);
    }

    public function create(array $data): PaymentProvider
    {
        return DB::transaction(function () use ($data) {
            return PaymentProvider::query()->create([
                'code' => strtoupper($data['code']),
                'name' => $data['name'],
                'fee_percentage' => $data['fee_percentage'] ?? 0,
                'is_active' => $data['is_active'] ?? true,
            ]);
        });
    }

    public function update(
        PaymentProvider $paymentProvider,
        array $data
    ): PaymentProvider {
        return DB::transaction(function () use (
            $paymentProvider,
            $data
        ) {
            $paymentProvider->update([
                'code' => strtoupper($data['code']),
                'name' => $data['name'],
                'fee_percentage' =>
                    $data['fee_percentage']
                    ?? $paymentProvider->fee_percentage,
                'is_active' =>
                    $data['is_active']
                    ?? $paymentProvider->is_active,
            ]);

            return $paymentProvider->refresh();
        });
    }

    public function activate(
        PaymentProvider $paymentProvider
    ): PaymentProvider {
        $paymentProvider->update([
            'is_active' => true,
        ]);

        return $paymentProvider->refresh();
    }

    public function deactivate(
        PaymentProvider $paymentProvider
    ): PaymentProvider {
        $paymentProvider->update([
            'is_active' => false,
        ]);

        return $paymentProvider->refresh();
    }

    public function delete(
        PaymentProvider $paymentProvider
    ): void {
        DB::transaction(function () use ($paymentProvider) {
            $paymentProvider->delete();
        });
    }

    public function getActiveProviders()
    {
        return PaymentProvider::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }
}