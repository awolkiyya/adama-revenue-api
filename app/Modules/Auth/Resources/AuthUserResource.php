<?php

namespace App\Modules\Auth\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuthUserResource extends JsonResource
{
    /**
     * Sanctum personal access token.
     *
     * null for:
     * - Web
     * - WebView
     *
     * populated for:
     * - Native mobile applications
     */
    private ?string $token;

    /**
     * Create resource.
     *
     * @param mixed $resource
     * @param string|null $token
     */
    public function __construct(
        $resource,
        ?string $token = null
    ) {
        parent::__construct($resource);

        $this->token = $token;
    }

    /**
     * Transform resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [

            /*
            |--------------------------------------------------------------------------
            | USER
            |--------------------------------------------------------------------------
            */

            'user' => [

                /*
                |--------------------------------------------------------------------------
                | Basic User Information
                |--------------------------------------------------------------------------
                */

                'id' => $this->id,

                'name' => $this->name,

                'label' => $this->label,

                'email' => $this->email,

                'phone' => $this->phone,

                /*
                |--------------------------------------------------------------------------
                | Account Type
                |--------------------------------------------------------------------------
                */

                'user_type' => $this->user_type,

                /*
                |--------------------------------------------------------------------------
                | Administrative Unit
                |--------------------------------------------------------------------------
                */

                'administrative_unit' => $this->whenLoaded(
                    'administrativeUnit',
                    function () {

                        $unit = $this->administrativeUnit;

                        if (!$unit) {
                            return null;
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | Determine hierarchy
                        |--------------------------------------------------------------------------
                        */

                        $wereda = (
                            $unit->level === 'WEREDA'
                        )
                            ? $unit
                            : null;

                        $subcity = (
                            $unit->level === 'SUBCITY'
                        )
                            ? $unit
                            : (
                                $unit->parent?->level === 'SUBCITY'
                                    ? $unit->parent
                                    : null
                            );

                        $city = (
                            $unit->level === 'CITY'
                        )
                            ? $unit
                            : (
                                $unit->parent?->level === 'CITY'
                                    ? $unit->parent
                                    : $unit->parent?->parent
                            );

                        return [

                            'id' => $unit->id,

                            'name' => $unit->name,

                            'code' => $unit->code,

                            'level' => $unit->level,

                            /*
                            |--------------------------------------------------------------------------
                            | Administrative Context
                            |--------------------------------------------------------------------------
                            */

                            'context' => [

                                'city' => $city
                                    ? [
                                        'id' => $city->id,
                                        'name' => $city->name,
                                    ]
                                    : null,

                                'subcity' => $subcity
                                    ? [
                                        'id' => $subcity->id,
                                        'name' => $subcity->name,
                                    ]
                                    : null,

                                'wereda' => $wereda
                                    ? [
                                        'id' => $wereda->id,
                                        'name' => $wereda->name,
                                    ]
                                    : null,
                            ],
                        ];
                    }
                ),

                /*
                |--------------------------------------------------------------------------
                | Account Status
                |--------------------------------------------------------------------------
                */

                'is_phone_verified' => $this->is_phone_verified,

                'is_active' => $this->is_active,

                /*
                |--------------------------------------------------------------------------
                | Avatar
                |--------------------------------------------------------------------------
                */

                'avatar' => $this->resolveAvatar(),

                /*
                |--------------------------------------------------------------------------
                | Citizen Profile
                |--------------------------------------------------------------------------
                */

                'citizen' => $this->when(
                    $this->user_type === 'citizen',
                    function () {

                        $citizen = $this
                            ->citizenAccount
                            ?->citizen;

                        if (!$citizen) {
                            return null;
                        }

                        return [

                            'id' => $citizen->id,

                            'citizen_uid' => $citizen->citizen_uid,

                            'full_name' => $citizen->full_name,

                            'national_id' => $citizen->national_id,

                            'address' => $citizen->address,

                            'gender' => $citizen->gender,

                            'date_of_birth' => $citizen->date_of_birth,

                            'registered_sector_id' =>
                                $citizen->registered_sector_id,
                        ];
                    }
                ),

                /*
                |--------------------------------------------------------------------------
                | Employee Role
                |--------------------------------------------------------------------------
                |
                | Employees use Spatie roles.
                |
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

                            'id' => $role->id,

                            'name' => $role->name,

                            'label' => $role->label ?? $this->label,
                        ];
                    }
                ),

                /*
                |--------------------------------------------------------------------------
                | Employee Permissions
                |--------------------------------------------------------------------------
                |
                | IMPORTANT:
                |
                | Frontend expects:
                |
                | [
                |     {
                |         resource: "users",
                |         actions: ["view", "create"]
                |     }
                | ]
                |
                | NOT:
                |
                | {
                |     users: ["view", "create"]
                | }
                |
                */

                'permissions' => $this->when(
                    $this->user_type === 'employee',
                    function () {
                        return $this->formatPermissions();
                    }
                ),

                /*
                |--------------------------------------------------------------------------
                | Audit
                |--------------------------------------------------------------------------
                */

                'created_at' => $this->created_at,

                'updated_at' => $this->updated_at,
            ],

            /*
            |--------------------------------------------------------------------------
            | AUTH TOKEN
            |--------------------------------------------------------------------------
            */

            'access_token' => $this->when(
                $this->token !== null,
                $this->token
            ),

            'token_type' => $this->when(
                $this->token !== null,
                'Bearer'
            ),
        ];
    }

    /**
     * Format employee permissions for frontend RBAC.
     *
     * Converts:
     *
     * dashboard.view
     * users.view
     * users.create
     * users.update
     *
     * Into:
     *
     * [
     *     [
     *         'resource' => 'dashboard',
     *         'actions' => ['view'],
     *     ],
     *     [
     *         'resource' => 'users',
     *         'actions' => [
     *             'view',
     *             'create',
     *             'update',
     *         ],
     *     ],
     * ]
     */
    private function formatPermissions(): array
    {
        $grouped = [];

        foreach ($this->getAllPermissions() as $permission) {

            /*
            |--------------------------------------------------------------------------
            | Permission name
            |--------------------------------------------------------------------------
            |
            | Example:
            |
            | users.view
            | users.create
            | revenue.view
            |
            */

            $parts = explode(
                '.',
                $permission->name
            );

            $resource = $parts[0] ?? 'general';

            $action = $parts[1]
                ?? $permission->name;

            /*
            |--------------------------------------------------------------------------
            | Initialize resource
            |--------------------------------------------------------------------------
            */

            if (!isset($grouped[$resource])) {
                $grouped[$resource] = [
                    'resource' => $resource,
                    'actions' => [],
                ];
            }

            /*
            |--------------------------------------------------------------------------
            | Prevent duplicate actions
            |--------------------------------------------------------------------------
            */

            if (
                !in_array(
                    $action,
                    $grouped[$resource]['actions'],
                    true
                )
            ) {
                $grouped[$resource]['actions'][] = $action;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Convert associative object to indexed array
        |--------------------------------------------------------------------------
        |
        | Frontend requires:
        |
        | permissions.some(...)
        |
        */

        return array_values($grouped);
    }

    /**
     * Resolve user avatar URL.
     */
    private function resolveAvatar(): ?string
    {
        /*
        |--------------------------------------------------------------------------
        | Employee
        |--------------------------------------------------------------------------
        */

        if ($this->user_type === 'employee') {

            return $this->avatar
                ? asset($this->avatar->path)
                : null;
        }

        /*
        |--------------------------------------------------------------------------
        | Citizen
        |--------------------------------------------------------------------------
        */

        if ($this->user_type === 'citizen') {

            $citizen = $this
                ->citizenAccount
                ?->citizen;

            return (
                $citizen &&
                $citizen->avatar
            )
                ? asset($citizen->avatar->path)
                : null;
        }

        return null;
    }
}