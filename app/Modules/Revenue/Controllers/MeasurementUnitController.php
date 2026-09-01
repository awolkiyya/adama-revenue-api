<?php

namespace App\Modules\Revenue\Controllers;

use Exception;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

use App\Http\Controllers\Controller;
use App\Models\MeasurementUnit;

use App\Modules\Revenue\Services\MeasurementUnitService;

use App\Modules\Revenue\Requests\StoreMeasurementUnitRequest;
use App\Modules\Revenue\Requests\UpdateMeasurementUnitRequest;

use App\Modules\Revenue\Resources\MeasurementUnitResource;

class MeasurementUnitController extends Controller
{
    public function __construct(
        protected MeasurementUnitService $service
    ) {}

    /**
     * ============================================================
     * INDEX
     * ============================================================
     *
     * Permission:
     *
     *     measurement_units.view
     *
     * Policy:
     *
     *     MeasurementUnitPolicy::viewAny()
     */
    public function index(
        Request $request
    ): JsonResponse {

        /*
        |--------------------------------------------------------------------------
        | Authorization
        |--------------------------------------------------------------------------
        */

        $this->authorize(
            'viewAny',
            MeasurementUnit::class
        );

        Log::info(
            'MeasurementUnitController@index started',
            [
                'user_id' => auth()->id(),
                'ip' => $request->ip(),
                'url' => $request->fullUrl(),
                'method' => $request->method(),
                'query' => $request->all(),
            ]
        );

        try {

            $units = $this->service->getAll(
                $request->all()
            );

            Log::info(
                'Measurement units retrieved successfully',
                [
                    'user_id' => auth()->id(),
                    'count' => $units->count(),
                    'total' => $units->total(),
                ]
            );

            return response()->json([
                'success' => true,

                'message' =>
                    'Measurement units retrieved successfully.',

                'data' =>
                    MeasurementUnitResource::collection(
                        $units
                    ),

                'meta' => [
                    'current_page' =>
                        $units->currentPage(),

                    'last_page' =>
                        $units->lastPage(),

                    'per_page' =>
                        $units->perPage(),

                    'total' =>
                        $units->total(),
                ],
            ]);

        } catch (Exception $exception) {

            Log::error(
                'Failed to retrieve measurement units',
                [
                    'user_id' => auth()->id(),

                    'message' =>
                        $exception->getMessage(),

                    'file' =>
                        $exception->getFile(),

                    'line' =>
                        $exception->getLine(),

                    'trace' =>
                        $exception->getTraceAsString(),
                ]
            );

            return response()->json([
                'success' => false,

                'message' =>
                    'Failed to retrieve measurement units.',
            ], 500);
        }
    }

    /**
     * ============================================================
     * STORE
     * ============================================================
     *
     * Permission:
     *
     *     measurement_units.create
     *
     * Policy:
     *
     *     MeasurementUnitPolicy::create()
     */
    public function store(
        StoreMeasurementUnitRequest $request
    ): JsonResponse {

        /*
        |--------------------------------------------------------------------------
        | Authorization
        |--------------------------------------------------------------------------
        */

        $this->authorize(
            'create',
            MeasurementUnit::class
        );

        try {

            $unit = $this->service->create(
                $request->validated()
            );

            Log::info(
                'Measurement unit created successfully',
                [
                    'user_id' => auth()->id(),
                    'measurement_unit_id' => $unit->id,
                ]
            );

            return response()->json([
                'success' => true,

                'message' =>
                    'Measurement unit created successfully.',

                'data' =>
                    new MeasurementUnitResource($unit),

            ], 201);

        } catch (Exception $exception) {

            Log::error(
                'Failed to create measurement unit',
                [
                    'user_id' => auth()->id(),

                    'message' =>
                        $exception->getMessage(),

                    'file' =>
                        $exception->getFile(),

                    'line' =>
                        $exception->getLine(),

                    'trace' =>
                        $exception->getTraceAsString(),
                ]
            );

            return response()->json([
                'success' => false,

                'message' =>
                    'Failed to create measurement unit.',
            ], 500);
        }
    }

    /**
     * ============================================================
     * SHOW
     * ============================================================
     *
     * Permission:
     *
     *     measurement_units.view
     *
     * Policy:
     *
     *     MeasurementUnitPolicy::view()
     */
    public function show(
        string $id
    ): JsonResponse {

        try {

            /*
            |--------------------------------------------------------------------------
            | Find Resource
            |--------------------------------------------------------------------------
            */

            $unit = $this->service->findById(
                $id
            );

            /*
            |--------------------------------------------------------------------------
            | Authorization
            |--------------------------------------------------------------------------
            */

            $this->authorize(
                'view',
                $unit
            );

            return response()->json([
                'success' => true,

                'data' =>
                    new MeasurementUnitResource($unit),
            ]);

        } catch (AuthorizationException $exception) {

            /*
            |--------------------------------------------------------------------------
            | NEVER CONVERT AUTHORIZATION FAILURE TO 500
            |--------------------------------------------------------------------------
            */

            throw $exception;

        } catch (ModelNotFoundException $exception) {

            return response()->json([
                'success' => false,

                'message' =>
                    'Measurement unit not found.',
            ], 404);

        } catch (Exception $exception) {

            Log::error(
                'Failed to retrieve measurement unit',
                [
                    'user_id' => auth()->id(),
                    'measurement_unit_id' => $id,

                    'message' =>
                        $exception->getMessage(),

                    'file' =>
                        $exception->getFile(),

                    'line' =>
                        $exception->getLine(),

                    'trace' =>
                        $exception->getTraceAsString(),
                ]
            );

            return response()->json([
                'success' => false,

                'message' =>
                    'Failed to retrieve measurement unit.',
            ], 500);
        }
    }

    /**
     * ============================================================
     * UPDATE
     * ============================================================
     *
     * Permission:
     *
     *     measurement_units.update
     *
     * Policy:
     *
     *     MeasurementUnitPolicy::update()
     */
    public function update(
        UpdateMeasurementUnitRequest $request,
        string $id
    ): JsonResponse {

        try {

            /*
            |--------------------------------------------------------------------------
            | Find Resource
            |--------------------------------------------------------------------------
            */

            $unit = $this->service->findById(
                $id
            );

            /*
            |--------------------------------------------------------------------------
            | Authorization
            |--------------------------------------------------------------------------
            */

            $this->authorize(
                'update',
                $unit
            );

            /*
            |--------------------------------------------------------------------------
            | Update
            |--------------------------------------------------------------------------
            */

            $unit = $this->service->update(
                $id,
                $request->validated()
            );

            Log::info(
                'Measurement unit updated successfully',
                [
                    'user_id' => auth()->id(),
                    'measurement_unit_id' => $id,
                ]
            );

            return response()->json([
                'success' => true,

                'message' =>
                    'Measurement unit updated successfully.',

                'data' =>
                    new MeasurementUnitResource($unit),
            ]);

        } catch (AuthorizationException $exception) {

            throw $exception;

        } catch (ModelNotFoundException $exception) {

            return response()->json([
                'success' => false,

                'message' =>
                    'Measurement unit not found.',
            ], 404);

        } catch (Exception $exception) {

            Log::error(
                'Failed to update measurement unit',
                [
                    'user_id' => auth()->id(),
                    'measurement_unit_id' => $id,

                    'message' =>
                        $exception->getMessage(),

                    'file' =>
                        $exception->getFile(),

                    'line' =>
                        $exception->getLine(),

                    'trace' =>
                        $exception->getTraceAsString(),
                ]
            );

            return response()->json([
                'success' => false,

                'message' =>
                    'Failed to update measurement unit.',
            ], 500);
        }
    }

    /**
     * ============================================================
     * DESTROY
     * ============================================================
     *
     * Permission:
     *
     *     measurement_units.delete
     *
     * Policy:
     *
     *     MeasurementUnitPolicy::delete()
     */
    public function destroy(
        string $id
    ): JsonResponse {

        try {

            /*
            |--------------------------------------------------------------------------
            | Find Resource
            |--------------------------------------------------------------------------
            */

            $unit = $this->service->findById(
                $id
            );

            /*
            |--------------------------------------------------------------------------
            | Authorization
            |--------------------------------------------------------------------------
            */

            $this->authorize(
                'delete',
                $unit
            );

            /*
            |--------------------------------------------------------------------------
            | Delete
            |--------------------------------------------------------------------------
            */

            $this->service->delete(
                $id
            );

            Log::info(
                'Measurement unit deleted successfully',
                [
                    'user_id' => auth()->id(),
                    'measurement_unit_id' => $id,
                ]
            );

            return response()->json([
                'success' => true,

                'message' =>
                    'Measurement unit deleted successfully.',
            ]);

        } catch (AuthorizationException $exception) {

            throw $exception;

        } catch (ModelNotFoundException $exception) {

            return response()->json([
                'success' => false,

                'message' =>
                    'Measurement unit not found.',
            ], 404);

        } catch (Exception $exception) {

            Log::error(
                'Failed to delete measurement unit',
                [
                    'user_id' => auth()->id(),
                    'measurement_unit_id' => $id,

                    'message' =>
                        $exception->getMessage(),

                    'file' =>
                        $exception->getFile(),

                    'line' =>
                        $exception->getLine(),

                    'trace' =>
                        $exception->getTraceAsString(),
                ]
            );

            return response()->json([
                'success' => false,

                'message' =>
                    'Failed to delete measurement unit.',
            ], 500);
        }
    }

    /**
     * ============================================================
     * RESTORE
     * ============================================================
     *
     * Permission:
     *
     *     measurement_units.restore
     *
     * Policy:
     *
     *     MeasurementUnitPolicy::restore()
     */
    public function restore(
        string $id
    ): JsonResponse {

        try {

            /*
            |--------------------------------------------------------------------------
            | Find Resource
            |--------------------------------------------------------------------------
            */

            $unit = $this->service->findById(
                $id
            );

            /*
            |--------------------------------------------------------------------------
            | Authorization
            |--------------------------------------------------------------------------
            */

            $this->authorize(
                'restore',
                $unit
            );

            /*
            |--------------------------------------------------------------------------
            | Restore
            |--------------------------------------------------------------------------
            */

            $unit = $this->service->restore(
                $id
            );

            Log::info(
                'Measurement unit restored successfully',
                [
                    'user_id' => auth()->id(),
                    'measurement_unit_id' => $id,
                ]
            );

            return response()->json([
                'success' => true,

                'message' =>
                    'Measurement unit restored successfully.',

                'data' =>
                    new MeasurementUnitResource($unit),
            ]);

        } catch (AuthorizationException $exception) {

            throw $exception;

        } catch (ModelNotFoundException $exception) {

            return response()->json([
                'success' => false,

                'message' =>
                    'Measurement unit not found.',
            ], 404);

        } catch (Exception $exception) {

            Log::error(
                'Failed to restore measurement unit',
                [
                    'user_id' => auth()->id(),
                    'measurement_unit_id' => $id,

                    'message' =>
                        $exception->getMessage(),

                    'file' =>
                        $exception->getFile(),

                    'line' =>
                        $exception->getLine(),

                    'trace' =>
                        $exception->getTraceAsString(),
                ]
            );

            return response()->json([
                'success' => false,

                'message' =>
                    'Failed to restore measurement unit.',
            ], 500);
        }
    }

    /**
     * ============================================================
     * CHANGE STATUS
     * ============================================================
     *
     * is_active = true
     *     ↓
     * measurement_units.activate
     *
     * is_active = false
     *     ↓
     * measurement_units.deactivate
     */
    public function changeStatus(
        Request $request,
        string $id
    ): JsonResponse {

        $request->validate([
            'is_active' => [
                'required',
                'boolean',
            ],
        ]);

        try {

            /*
            |--------------------------------------------------------------------------
            | Find Resource
            |--------------------------------------------------------------------------
            */

            $unit = $this->service->findById(
                $id
            );

            /*
            |--------------------------------------------------------------------------
            | Determine Authorization Ability
            |--------------------------------------------------------------------------
            */

            $ability = $request->boolean(
                'is_active'
            )
                ? 'activate'
                : 'deactivate';

            /*
            |--------------------------------------------------------------------------
            | Authorization
            |--------------------------------------------------------------------------
            */

            $this->authorize(
                $ability,
                $unit
            );

            /*
            |--------------------------------------------------------------------------
            | Change Status
            |--------------------------------------------------------------------------
            */

            $unit = $this->service->changeStatus(
                $id,
                $request->boolean('is_active')
            );

            Log::info(
                'Measurement unit status changed successfully',
                [
                    'user_id' => auth()->id(),
                    'measurement_unit_id' => $id,
                    'is_active' =>
                        $request->boolean('is_active'),
                ]
            );

            return response()->json([
                'success' => true,

                'message' =>
                    'Measurement unit status updated successfully.',

                'data' =>
                    new MeasurementUnitResource($unit),
            ]);

        } catch (AuthorizationException $exception) {

            throw $exception;

        } catch (ModelNotFoundException $exception) {

            return response()->json([
                'success' => false,

                'message' =>
                    'Measurement unit not found.',
            ], 404);

        } catch (Exception $exception) {

            Log::error(
                'Failed to update measurement unit status',
                [
                    'user_id' => auth()->id(),
                    'measurement_unit_id' => $id,

                    'message' =>
                        $exception->getMessage(),

                    'file' =>
                        $exception->getFile(),

                    'line' =>
                        $exception->getLine(),

                    'trace' =>
                        $exception->getTraceAsString(),
                ]
            );

            return response()->json([
                'success' => false,

                'message' =>
                    'Failed to update measurement unit status.',
            ], 500);
        }
    }
}
