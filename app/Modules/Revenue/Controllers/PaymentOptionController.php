<?php

namespace App\Modules\Revenue\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Revenue\Resources\PaymentOptionResource;
use App\Modules\Revenue\Services\PaymentOptionService;

class PaymentOptionController extends Controller
{
    public function __construct(
        private readonly PaymentOptionService $service
    ) {
    }

    public function index(): PaymentOptionResource
    {
        return new PaymentOptionResource(
            $this->service->getAvailableOptions()
        );
    }
}