<?php

namespace App\Modules\Revenue\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Revenue\Requests\StoreServiceAccessRuleRequest;
use App\Modules\Revenue\Requests\UpdateServiceAccessRuleRequest;
use App\Models\ServiceAccessRule;
use App\Modules\Revenue\Services\ServiceAccessRuleService;
use App\Modules\Revenue\Resources\ServiceAccessRuleResource;
use App\Services\ApiResponse;
use Illuminate\Http\Request;
use Throwable;


class ServiceAccessRuleController extends Controller
{

    public function __construct(
        private ServiceAccessRuleService $service
    ) {}



   /**
     * List service access rules
     */
    public function index(Request $request)
    {

        try {


            return ApiResponse::success(

                ServiceAccessRuleResource::collection(

                    $this->service->all(
                        $request->all()
                    )

                ),

                'Service access rules retrieved successfully',


                summary: $this->service->summary(
                    $request->all()
                )

            );


        } catch(Throwable $e) {


            report($e);


            return ApiResponse::serverError(
                exception: $e
            );


        }

    }





    /**
     * Create access rule
     */
    public function store(
        StoreServiceAccessRuleRequest $request
    )
    {

        try {


            $rule = $this->service->create(
                $request->validated()
            );


            return ApiResponse::created(

                new ServiceAccessRuleResource($rule),

                'Service access rule created successfully'

            );


        } catch(Throwable $e) {

            report($e);

            return ApiResponse::serverError(
                exception: $e
            );

        }

    }





    /**
     * Show access rule
     */
    public function show(
        ServiceAccessRule $service_access_rule
    )
    {

        try {


            return ApiResponse::success(

                new ServiceAccessRuleResource(
                    $this->service->find(
                        $service_access_rule
                    )
                ),

                'Service access rule retrieved successfully'

            );


        } catch(Throwable $e) {

            report($e);

            return ApiResponse::serverError(
                exception: $e
            );

        }

    }





    /**
     * Update access rule
     */
    public function update(
        UpdateServiceAccessRuleRequest $request,
        ServiceAccessRule $service_access_rule
    )
    {

        try {


            $rule = $this->service->update(

                $service_access_rule,

                $request->validated()

            );


            return ApiResponse::updated(

                new ServiceAccessRuleResource($rule),

                'Service access rule updated successfully'

            );


        } catch(Throwable $e) {

            report($e);

            return ApiResponse::serverError(
                exception: $e
            );

        }

    }





    /**
     * Delete access rule
     */
    public function destroy(
        ServiceAccessRule $service_access_rule
    )
    {

        try {


            $this->service->delete(
                $service_access_rule
            );


            return ApiResponse::deleted(
                'Service access rule deleted successfully'
            );


        } catch(Throwable $e) {

            report($e);

            return ApiResponse::serverError(
                exception: $e
            );

        }

    }

}