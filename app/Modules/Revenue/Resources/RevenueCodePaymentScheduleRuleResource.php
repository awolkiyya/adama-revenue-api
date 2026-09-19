<?php

namespace App\Modules\Revenue\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RevenueCodePaymentScheduleRuleResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'revenue_code_id' => $this->revenue_code_id,

            'is_enabled' => (bool) $this->is_enabled,

            'first_installment_percentage' =>
                $this->first_installment_percentage !== null
                    ? (float) $this->first_installment_percentage
                    : null,

            'status' => $this->is_enabled
                ? 'ACTIVE'
                : 'INACTIVE',

            'revenue_code' => $this->whenLoaded(
                'revenueCode',
                function () {
                    return [
                        'id' => $this->revenueCode->id,
                        'code' => $this->revenueCode->code,
                        'name' => $this->revenueCode->name,
                    ];
                }
            ),

            'created_at' => $this->created_at?->toISOString(),

            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}