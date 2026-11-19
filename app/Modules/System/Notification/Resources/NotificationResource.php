<?php

declare(strict_types=1);

namespace App\Modules\System\Notification\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource
{
    /**
     * Transform the notification into an API response.
     */
    public function toArray(Request $request): array
    {
        $data = $this->data ?? [];

        return [
            'id' => (string) $this->id,

            'type' => $data['type'] ?? null,

            'module' => $data['module'] ?? null,

            'title' => $data['title'] ?? null,

            'message' => $data['message'] ?? null,

            'priority' => $data['priority'] ?? 'normal',

            'data' => $data['data'] ?? [],

            'action_url' => $data['action_url'] ?? null,

            'is_read' => $this->read_at !== null,

            'read_at' => $this->read_at?->toISOString(),

            'created_at' => $this->created_at?->toISOString(),

            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
