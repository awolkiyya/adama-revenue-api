<?php

namespace App\Modules\Revenue\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentOptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'payment_methods' => $this['payment_methods'],

            'bank_accounts' => BankAccountResource::collection(
                $this['bank_accounts']
            ),

            'payment_providers' => PaymentProviderResource::collection(
                $this['payment_providers']
            ),
        ];
    }
}