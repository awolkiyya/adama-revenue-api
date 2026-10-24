<?php

declare(strict_types=1);

namespace App\Modules\Users\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            /**
             * BASIC INFORMATION
             */
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'label' => $this->label,

            /**
             * ACCOUNT TYPE
             *
             * employee
             * citizen
             */
            'user_type' => $this->user_type,

           /**
             * AVATAR
             *
             * The private-file route uses UUID-based route-model binding.
            */
            'avatar' => $this->whenLoaded(
                'avatar',
                fn () => $this->avatar
                    ? "/private-file/{$this->avatar->uuid}/url"
                    : null,
            ),

            /**
             * ACCOUNT STATUS
             */
            'is_active' => (bool) $this->is_active,

            /**
             * EMPLOYEE AUTHORIZATION
             *
             * Spatie manages the roles relationship.
             * The API returns only the first role.
             */
            'role' => $this->when(
                $this->user_type === 'employee'
                    && $this->relationLoaded('roles'),
                function (): ?array {
                    $role = $this->roles->first();

                    if (! $role) {
                        return null;
                    }

                    return [
                        'id' => $role->id,
                        'name' => $role->name,
                        'label' => $this->label,
                    ];
                }
            ),

            /**
             * LAST LOGIN
             */
            'lastLoginAt' => $this->last_login_at?->toISOString(),

            /**
             * ORGANIZATIONAL SCOPE
             */
            'sector' => $this->whenLoaded(
                'sector',
                fn () => $this->sector
                    ? [
                        'id' => $this->sector->id,
                        'name' => $this->sector->name,
                    ]
                    : null,
            ),

            'administrative_unit' => $this->whenLoaded(
                'administrativeUnit',
                fn () => $this->administrativeUnit
                    ? [
                        'id' => $this->administrativeUnit->id,
                        'name' => $this->administrativeUnit->name,
                        'level' => $this->administrativeUnit->level,
                        'parent_id' => $this->administrativeUnit->parent_id,
                    ]
                    : null,
            ),

            /**
             * PHONE VERIFICATION
             */
            'is_phone_verified' => $this->is_phone_verified,

            /**
             * TIMESTAMPS
             */
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'email_verified_at' => $this->email_verified_at?->toISOString(),
        ];
    }
}
