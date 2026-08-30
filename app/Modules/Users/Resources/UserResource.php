<?php

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
             * Account type
             *
             * employee
             * citizen
             */
            'user_type' => $this->user_type,


            /**
             * AVATAR
             */
            'avatar' => $this->avatar_file_id
                ? "/private-file/{$this->avatar_file_id}"
                : "",

            /**
             * STATUS
             */
            'is_active' => (bool) $this->is_active,

            /**
             * ACCESS
             */
             /**
             * Employee authorization
             *
             * Spatie uses roles relationship,
             * but API returns only first role.
             */
            'role' => $this->when(
                $this->user_type === 'employee'
                && $this->relationLoaded('roles'),
                function () {

                    $role = $this->roles->first();

                    if (!$role) {
                        return null;
                    }

                    return [

                        'id' =>
                            $role->id,

                        'name' =>
                            $role->name,

                        'label' =>
                            $this->label,

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
            'sector' => $this->whenLoaded('sector', fn () => [
                'id' => $this->sector->id,
                'name' => $this->sector->name,
            ]),

            'administrative_unit' => $this->whenLoaded('administrativeUnit', fn () => [
                'id' => $this->administrativeUnit->id,
                'name' => $this->administrativeUnit->name,
                'level' => $this->administrativeUnit->level,
                'parent_id' => $this->administrativeUnit->parent_id,
            ]),

             /**
             * Account status
             */
            'is_phone_verified' =>
                $this->is_phone_verified,

            /**
             * TIMESTAMPS
             */
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'email_verified_at'=> $this->email_verified_at?->toISOString(),
        ];
    }
}