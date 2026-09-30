<?php

namespace App\Modules\Taxpayer\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaxpayerNotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'type' => $this->type,

            'title' => $this->data['title'] ?? null,

            'message' => $this->data['message'] ?? null,

            'data' => $this->data,

            'read_at' => $this->read_at?->toISOString(),

            'is_read' => $this->read_at !== null,

            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}