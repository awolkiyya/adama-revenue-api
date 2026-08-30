<?php

namespace App\Modules\Revenue\Controllers;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

use App\Http\Controllers\Controller;

use App\Modules\Revenue\Services\BaseFieldService;

use App\Modules\Revenue\Requests\StoreBaseFieldRequest;
use App\Modules\Revenue\Requests\UpdateBaseFieldRequest;

use App\Modules\Revenue\Resources\BaseFieldResource;


class BaseFieldController extends Controller
{

    public function __construct(
        protected BaseFieldService $service
    ) {}



    /**
     * Display list of base fields.
     */
    public function index(Request $request): JsonResponse
    {
        try {


            $fields = $this->service->getAll(
                $request->all()
            );



            return response()->json([

                'success' => true,

                'message' => 'Base fields retrieved successfully.',


                'data' => BaseFieldResource::collection(
                    $fields
                ),


                'meta' => [

                    'current_page' => $fields->currentPage(),

                    'last_page' => $fields->lastPage(),

                    'per_page' => $fields->perPage(),

                    'total' => $fields->total(),

                ],

            ]);



        } catch (Exception $exception) {


            return response()->json([

                'success' => false,

                'message' => 'Failed to retrieve base fields.',

            ], 500);

        }
    }





    /**
     * Store new base field.
     */
    public function store(
        StoreBaseFieldRequest $request
    ): JsonResponse {

        try {


            $field = $this->service->create(

                $request->validated()

            );



            return response()->json([

                'success' => true,

                'message' => 'Base field created successfully.',


                'data' => new BaseFieldResource(
                    $field
                ),


            ], 201);



        } catch (Exception $exception) {


            return response()->json([

                'success' => false,

                'message' => 'Failed to create base field.',

            ], 500);

        }
    }





    /**
     * Display single base field.
     */
    public function show(string $id): JsonResponse
    {

        try {


            $field = $this->service->findById($id);



            return response()->json([

                'success' => true,

                'data' => new BaseFieldResource(
                    $field
                ),

            ]);



        } catch (Exception $exception) {


            return response()->json([

                'success' => false,

                'message' => 'Base field not found.',

            ], 404);

        }

    }





    /**
     * Update base field.
     */
    public function update(
        UpdateBaseFieldRequest $request,
        string $id
    ): JsonResponse {

        try {


            $field = $this->service->update(

                $id,

                $request->validated()

            );



            return response()->json([

                'success' => true,

                'message' => 'Base field updated successfully.',


                'data' => new BaseFieldResource(
                    $field
                ),

            ]);



        } catch (Exception $exception) {


            return response()->json([

                'success' => false,

                'message' => 'Failed to update base field.',

            ], 500);

        }

    }
    /**
     * Remove base field.
     */
    public function destroy(string $id): JsonResponse
    {
        try {


            $this->service->delete($id);



            return response()->json([

                'success' => true,

                'message' => 'Base field deleted successfully.',

            ]);



        } catch (Exception $exception) {



            return response()->json([

                'success' => false,

                'message' => 'Failed to delete base field.',

            ], 500);

        }
    }





    /**
     * Restore deleted base field.
     */
    public function restore(string $id): JsonResponse
    {
        try {


            $field = $this->service->restore($id);



            return response()->json([

                'success' => true,

                'message' => 'Base field restored successfully.',


                'data' => new BaseFieldResource(
                    $field
                ),

            ]);



        } catch (Exception $exception) {



            return response()->json([

                'success' => false,

                'message' => 'Failed to restore base field.',

            ], 500);

        }
    }





    /**
     * Change base field status.
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


            $field = $this->service->changeStatus(

                $id,

                $request->boolean('is_active')

            );



            return response()->json([

                'success' => true,

                'message' => 'Base field status updated successfully.',


                'data' => new BaseFieldResource(
                    $field
                ),

            ]);



        } catch (Exception $exception) {



            return response()->json([

                'success' => false,

                'message' => 'Failed to update base field status.',

            ], 500);

        }

    }


}