<?php

namespace App\Modules\Revenue\Controllers;

use App\Http\Controllers\Controller;


use App\Modules\Revenue\Requests\PaymentProviderRequest;
use App\Modules\Revenue\Resources\PaymentProviderResource;
use App\Models\PaymentProvider;
use App\Modules\Revenue\Services\PaymentProviderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentProviderController extends Controller
{
    public function __construct(
        private readonly PaymentProviderService $service
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

        $providers = $this->service->paginate(
            perPage: $perPage,
            search: $request->string('search')->toString() ?: null,
            isActive: $isActive,
        );

        return PaymentProviderResource::collection($providers);
    }

    public function store(
        PaymentProviderRequest $request
    ): JsonResponse {
        $provider = $this->service->create(
            $request->validated()
        );

        return (new PaymentProviderResource($provider))
            ->response()
            ->setStatusCode(201);
    }

    public function show(
        PaymentProvider $paymentProvider
    ): PaymentProviderResource {
        return new PaymentProviderResource($paymentProvider);
    }

    public function update(
        PaymentProviderRequest $request,
        PaymentProvider $paymentProvider
    ): PaymentProviderResource {
        $provider = $this->service->update(
            $paymentProvider,
            $request->validated()
        );

        return new PaymentProviderResource($provider);
    }

    public function activate(
        PaymentProvider $paymentProvider
    ): PaymentProviderResource {
        return new PaymentProviderResource(
            $this->service->activate($paymentProvider)
        );
    }

    public function deactivate(
        PaymentProvider $paymentProvider
    ): PaymentProviderResource {
        return new PaymentProviderResource(
            $this->service->deactivate($paymentProvider)
        );
    }

    public function destroy(
        PaymentProvider $paymentProvider
    ): JsonResponse {
        $this->service->delete($paymentProvider);

        return response()->json([
            'message' => 'Payment provider deleted successfully.',
        ]);
    }
}