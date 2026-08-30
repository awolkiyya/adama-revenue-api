<?php

namespace App\Modules\Audit\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SystemLogResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            /*
            |--------------------------------------------------------------------------
            | Actor
            |--------------------------------------------------------------------------
            */

            'user' => $this->whenLoaded('user', function () {
                return [
                    'id' => $this->user?->id,
                    'name' => $this->user?->name,
                    'email' => $this->user?->email,
                ];
            }),

            'user_id' => $this->user_id,

            /*
            |--------------------------------------------------------------------------
            | Audit Action
            |--------------------------------------------------------------------------
            */

            'action' => $this->action,

            'module' => $this->module,

            /*
            |--------------------------------------------------------------------------
            | Resource
            |--------------------------------------------------------------------------
            */

            'resource' => [
                'type' => $this->resource_type,
                'id' => $this->resource_id,
            ],

            /*
            |--------------------------------------------------------------------------
            | Description
            |--------------------------------------------------------------------------
            */

            'description' => $this->description,

            /*
            |--------------------------------------------------------------------------
            | Changes
            |--------------------------------------------------------------------------
            */

            'old_values' => $this->old_values,
            'new_values' => $this->new_values,

            /*
            |--------------------------------------------------------------------------
            | Request Information
            |--------------------------------------------------------------------------
            */

            'request_id' => $this->request_id,

            'ip_address' => $this->ip_address,

            'user_agent' => $this->user_agent,

            /*
            |--------------------------------------------------------------------------
            | Additional Context
            |--------------------------------------------------------------------------
            */

            'metadata' => $this->metadata,

            /*
            |--------------------------------------------------------------------------
            | Timestamp
            |--------------------------------------------------------------------------
            */

            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}