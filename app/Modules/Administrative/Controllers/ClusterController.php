<?php

namespace App\Modules\Administrative\Controllers;

use App\Http\Controllers\Controller;

use App\Modules\Administrative\Services\ClusterService;
use App\Modules\Administrative\Resources\ClusterResource;
use App\Services\ApiResponse;
use Illuminate\Http\Request;

class ClusterController extends Controller
{
    public function __construct(protected ClusterService $service) {}

    public function index(Request $request)
    {
        $clusters = $this->service->getAll($request);

        return ApiResponse::success(
            ClusterResource::collection($clusters),
            'Clusters retrieved successfully'
        );
    }
}