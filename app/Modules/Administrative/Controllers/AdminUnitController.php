<?php

namespace App\Modules\Administrative\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AdministrativeUnit;
use App\Modules\Administrative\Resources\AdminUnitResource;
use App\Services\ApiResponse;
use Illuminate\Http\Request;

class AdminUnitController extends Controller
{
    /**
     * =========================================================
     * LIST ADMINISTRATIVE UNITS
     * =========================================================
     */
    public function index(Request $request)
    {
        $this->authorize(
            'viewAny',
            AdministrativeUnit::class
        );

        $perPage = $request->integer(
            'per_page',
            10
        );

        $units = AdministrativeUnit::query()

            ->when(
                $request->query('search'),
                function ($query, $search) {
                    $searchTerm = mb_strtolower(
                        trim($search)
                    );

                    $query->where(function ($q) use ($searchTerm) {
                        $q->whereRaw(
                            'LOWER(name) LIKE ?',
                            ["%{$searchTerm}%"]
                        )
                        ->orWhereRaw(
                            'LOWER(code) LIKE ?',
                            ["%{$searchTerm}%"]
                        );
                    });
                }
            )

            ->when(
                $request->query('level'),
                function ($query, $level) {
                    $query->where(
                        'level',
                        $level
                    );
                }
            )

            ->latest()
            ->paginate($perPage);

        return ApiResponse::success(
            AdminUnitResource::collection($units),
            'Administrative Units retrieved successfully'
        );
    }

    /**
     * =========================================================
     * SHOW ADMINISTRATIVE UNIT
     * =========================================================
     */
    public function show(string $id)
    {
        $unit = AdministrativeUnit::with(
            'children'
        )->findOrFail($id);

        $this->authorize(
            'view',
            $unit
        );

        return ApiResponse::success(
            new AdminUnitResource($unit),
            'Administrative Unit retrieved successfully'
        );
    }

    /**
     * =========================================================
     * GET CHILDREN
     * =========================================================
     */
    public function getChildren(string $id)
    {
        $parent = AdministrativeUnit::findOrFail($id);

        $this->authorize(
            'view',
            $parent
        );

        $children = AdministrativeUnit::query()
            ->where(
                'parent_id',
                $parent->id
            )
            ->get();

        return ApiResponse::success(
            AdminUnitResource::collection($children),
            'Administrative Unit children retrieved successfully'
        );
    }

    /**
     * =========================================================
     * CREATE ADMINISTRATIVE UNIT
     * =========================================================
     */
    public function store(Request $request)
    {
        $this->authorize(
            'create',
            AdministrativeUnit::class
        );

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'code' => [
                'required',
                'string',
                'max:100',
                'unique:administrative_units,code',
            ],

            'level' => [
                'required',
                'in:CITY,SUBCITY,WEREDA',
            ],

            'parent_id' => [
                'nullable',
                'uuid',
                'exists:administrative_units,id',
            ],
        ]);

        /*
        |--------------------------------------------------------------------------
        | IMPORTANT
        |--------------------------------------------------------------------------
        |
        | Policy::create() checks permission.
        |
        | The requested parent/level scope must additionally be
        | validated before creating the unit.
        |
        */

        $unit = AdministrativeUnit::create(
            $validated
        );

        return ApiResponse::created(
            new AdminUnitResource($unit),
            'Administrative Unit created successfully'
        );
    }
}
