<?php

namespace App\Modules\Revenue\Controllers;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Modules\Revenue\Requests\StoreBankAccountRequest;
use App\Modules\Revenue\Requests\UpdateBankAccountRequest;
use App\Modules\Revenue\Resources\BankAccountResource;
use App\Modules\Revenue\Services\BankAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BankAccountController extends Controller
{
    public function __construct(
        private readonly BankAccountService $service
    ) {
    }

    public function index(Request $request)
    {
        $perPage = min(
            max((int) $request->integer('per_page', 15), 1),
            100
        );

        $isActive = $request->has('is_active')
            ? $request->boolean('is_active')
            : null;

        $accounts = $this->service->paginate(
            perPage: $perPage,
            search: $request->string('search')->toString() ?: null,
            isActive: $isActive,
        );

        return BankAccountResource::collection($accounts);
    }

    public function store(
        StoreBankAccountRequest $request
    ): JsonResponse {
        $bankAccount = $this->service->create(
            $request->validated()
        );

        return (new BankAccountResource($bankAccount))
            ->response()
            ->setStatusCode(201);
    }

    public function show(
        BankAccount $bankAccount
    ): BankAccountResource {
        return new BankAccountResource($bankAccount);
    }

    public function update(
        UpdateBankAccountRequest $request,
        BankAccount $bankAccount
    ): BankAccountResource {
        $bankAccount = $this->service->update(
            $bankAccount,
            $request->validated()
        );

        return new BankAccountResource($bankAccount);
    }

    public function activate(
        BankAccount $bankAccount
    ): BankAccountResource {
        return new BankAccountResource(
            $this->service->activate($bankAccount)
        );
    }

    public function deactivate(
        BankAccount $bankAccount
    ): BankAccountResource {
        return new BankAccountResource(
            $this->service->deactivate($bankAccount)
        );
    }
}