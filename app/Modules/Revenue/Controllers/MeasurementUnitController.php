<?php

namespace App\Modules\Revenue\Controllers;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

use App\Http\Controllers\Controller;

use App\Modules\Revenue\Services\MeasurementUnitService;

use App\Modules\Revenue\Requests\StoreMeasurementUnitRequest;
use App\Modules\Revenue\Requests\UpdateMeasurementUnitRequest;

use App\Modules\Revenue\Resources\MeasurementUnitResource;
use Illuminate\Support\Facades\Log;


class MeasurementUnitController extends Controller
{

    public function __construct(
        protected MeasurementUnitService $service
    ) {}



    /**
     * Display list of measurement units.
     */
    public function index(Request $request): JsonResponse
    {
        Log::info('MeasurementUnitController@index started', [
            'user_id' => auth()->id(),
            'ip' => $request->ip(),
            'url' => $request->fullUrl(),
            'method' => $request->method(),
            'query' => $request->all(),
        ]);
    
        try {
    
            $units = $this->service->getAll($request->all());
    
            Log::info('Measurement units retrieved successfully', [
                'count' => $units->count(),
                'total' => $units->total(),
            ]);
    
            return response()->json([
                'success' => true,
                'message' => 'Measurement units retrieved successfully.',
                'data' => MeasurementUnitResource::collection($units),
                'meta' => [
                    'current_page' => $units->currentPage(),
                    'last_page' => $units->lastPage(),
                    'per_page' => $units->perPage(),
                    'total' => $units->total(),
                ],
            ]);
    
        } catch (Exception $exception) {
    
            Log::error('Failed to retrieve measurement units', [
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ]);
    
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve measurement units.',
            ], 500);
        }
    }





    /**
     * Store a new measurement unit.
     */
    public function store(
        StoreMeasurementUnitRequest $request
    ): JsonResponse {


        try {


            $unit = $this->service->create(
                $request->validated()
            );



            return response()->json([

                'success' => true,

                'message' => 'Measurement unit created successfully.',

                'data' => new MeasurementUnitResource($unit),

            ], 201);



        } catch (Exception $exception) {



            return response()->json([

                'success' => false,

                'message' => 'Failed to create measurement unit.',

            ], 500);

        }

    }





    /**
     * Display single measurement unit.
     */
    public function show(string $id): JsonResponse
    {

        try {


            $unit = $this->service->findById($id);



            return response()->json([

                'success' => true,

                'data' => new MeasurementUnitResource($unit),

            ]);



        } catch (Exception $exception) {


            return response()->json([

                'success' => false,

                'message' => 'Measurement unit not found.',

            ], 404);

        }

    }





    /**
     * Update measurement unit.
     */
    public function update(
        UpdateMeasurementUnitRequest $request,
        string $id
    ): JsonResponse {


        try {


            $unit = $this->service->update(

                $id,

                $request->validated()

            );



            return response()->json([

                'success' => true,

                'message' => 'Measurement unit updated successfully.',

                'data' => new MeasurementUnitResource($unit),

            ]);



        } catch (Exception $exception) {



            return response()->json([

                'success' => false,

                'message' => 'Failed to update measurement unit.',

            ], 500);

        }

    }
    /**
     * Remove measurement unit.
     */
    public function destroy(string $id): JsonResponse
    {
        try {

            $this->service->delete($id);


            return response()->json([

                'success' => true,

                'message' => 'Measurement unit deleted successfully.',

            ]);



        } catch (Exception $exception) {


            return response()->json([

                'success' => false,

                'message' => 'Failed to delete measurement unit.',

            ], 500);

        }
    }





    /**
     * Restore deleted measurement unit.
     */
    public function restore(string $id): JsonResponse
    {
        try {


            $unit = $this->service->restore($id);



            return response()->json([

                'success' => true,

                'message' => 'Measurement unit restored successfully.',

                'data' => new MeasurementUnitResource($unit),

            ]);



        } catch (Exception $exception) {


            return response()->json([

                'success' => false,

                'message' => 'Failed to restore measurement unit.',

            ], 500);

        }
    }





    /**
     * Change measurement unit status.
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


            $unit = $this->service->changeStatus(

                $id,

                $request->boolean('is_active')

            );



            return response()->json([

                'success' => true,

                'message' => 'Measurement unit status updated successfully.',

                'data' => new MeasurementUnitResource($unit),

            ]);



        } catch (Exception $exception) {


            return response()->json([

                'success' => false,

                'message' => 'Failed to update measurement unit status.',

            ], 500);

        }

    }
}