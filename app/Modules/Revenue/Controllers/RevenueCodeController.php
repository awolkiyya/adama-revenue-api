<?php

namespace App\Modules\Revenue\Controllers;

use App\Http\Controllers\Controller;
use App\Models\RevenueCode;
use App\Modules\Revenue\Resources\RevenueCodeResource;
use App\Services\ApiResponse;
use Illuminate\Http\Request;
use Throwable;


class RevenueCodeController extends Controller
{


    /**
     * Display paginated revenue codes.
     */
    public function index(Request $request)
    {



            $search = $request->input('search');


            $codes = RevenueCode::query()

                ->whereHas('category', function ($query) {

                    $query->where(
                        'is_active',
                        true
                    );

                })


                ->when(
                    $search,
                    function ($query) use ($search) {

                        $query->where(function ($q) use ($search) {


                            $q->where(
                                'code',
                                'ILIKE',
                                "%{$search}%"
                            )


                            ->orWhere(
                                'name',
                                'ILIKE',
                                "%{$search}%"
                            );


                        });


                    }
                )


                ->where(
                    'is_active',
                    true
                )


                ->orderBy('code')


                ->paginate(
                    $request->integer(
                        'per_page',
                        20
                    )
                );




            return ApiResponse::success(

                RevenueCodeResource::collection($codes),

                'Revenue codes retrieved successfully.'

            );

    }


}