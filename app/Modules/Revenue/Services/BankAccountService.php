<?php

namespace App\Modules\Revenue\Services;

use App\Models\BankAccount;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class BankAccountService
{
    public function paginate(
        int $perPage = 15,
        ?string $search = null,
        ?bool $isActive = null
    ): LengthAwarePaginator {
        return BankAccount::query()
            ->when($search, function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query
                        ->where('bank_name', 'like', "%{$search}%")
                        ->orWhere('account_name', 'like', "%{$search}%")
                        ->orWhere('account_number', 'like', "%{$search}%");
                });
            })
            ->when($isActive !== null, function ($query) use ($isActive) {
                $query->where('is_active', $isActive);
            })
            ->latest()
            ->paginate($perPage);
    }

    public function find(string $id): BankAccount
    {
        return BankAccount::query()->findOrFail($id);
    }

    public function create(array $data): BankAccount
    {
        return DB::transaction(function () use ($data) {
            return BankAccount::query()->create([
                'bank_name' => $data['bank_name'],
                'account_name' => $data['account_name'],
                'account_number' => $data['account_number'],
                'currency' => $data['currency'] ?? 'ETB',
                'is_active' => $data['is_active'] ?? true,
            ]);
        });
    }

    public function update(
        BankAccount $bankAccount,
        array $data
    ): BankAccount {
        return DB::transaction(function () use ($bankAccount, $data) {
            $bankAccount->update([
                'bank_name' => $data['bank_name'],
                'account_name' => $data['account_name'],
                'account_number' => $data['account_number'],
                'currency' => $data['currency'] ?? $bankAccount->currency,
                'is_active' => $data['is_active'] ?? $bankAccount->is_active,
            ]);

            return $bankAccount->refresh();
        });
    }

    public function activate(BankAccount $bankAccount): BankAccount
    {
        $bankAccount->update([
            'is_active' => true,
        ]);

        return $bankAccount->refresh();
    }

    public function deactivate(BankAccount $bankAccount): BankAccount
    {
        $bankAccount->update([
            'is_active' => false,
        ]);

        return $bankAccount->refresh();
    }

    public function delete(BankAccount $bankAccount): void
    {
        DB::transaction(function () use ($bankAccount) {
            $bankAccount->delete();
        });
    }
}