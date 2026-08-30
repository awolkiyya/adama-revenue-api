<?php

namespace App\Modules\Administrative\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Administrative\Services\SectorService;
use App\Modules\Administrative\Resources\SectorResource;
use App\Services\ApiResponse;
use App\Services\SystemLogService;
use App\Supports\SystemLogModule;
use Illuminate\Http\Request;
use App\Models\Sector;

class SectorController extends Controller
{
    public function __construct(
        protected SectorService $service,
        protected SystemLogService $systemLog
    ) {}

    /**
     * =========================================================
     * GET PAGINATED SECTORS
     * =========================================================
     */
    public function index(Request $request)
    {
        $this->authorize(
            'viewAny',
            Sector::class
        );

        $filters = $request->only([
            'search',
            'cluster_id',
            'is_active',
        ]);

        $sectors = $this->service->getList(
            $filters,
            $request->integer('per_page', 10)
        );

        return ApiResponse::success(
            SectorResource::collection($sectors),
            'Sectors retrieved successfully'
        );
    }


    /**
     * =========================================================
     * CREATE SECTOR
     * =========================================================
     */
    public function store(Request $request)
    {
        $this->authorize(
            'create',
            Sector::class
        );

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'min:2',
                'max:255',
            ],

            'code' => [
                'required',
                'string',
                'min:2',
                'max:100',
            ],

            'description' => [
                'required',
                'string',
            ],

            'cluster_id' => [
                'required',
                'uuid',
                'exists:clusters,id',
            ],
        ]);

        $sector = $this->service->create(
            $validated
        );

        /*
        |--------------------------------------------------------------------------
        | AUDIT LOG
        |--------------------------------------------------------------------------
        |
        | Log only after the sector has been successfully created.
        |
        */

        $this->systemLog->created(
            resource: $sector,
            module: SystemLogModule::SECTORS,
            description: 'Sector created successfully',
            newValues: $sector->getAttributes(),
        );

        return ApiResponse::created(
            new SectorResource($sector),
            'Sector created successfully'
        );
    }


    /**
     * =========================================================
     * UPDATE SECTOR
     * =========================================================
     */
    public function update(
        Request $request,
        Sector $sector
    ) {
        $this->authorize(
            'update',
            $sector
        );

        /*
        |--------------------------------------------------------------------------
        | Capture old values BEFORE update
        |--------------------------------------------------------------------------
        */

        $oldValues = $sector->getAttributes();

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'min:2',
                'max:255',
            ],

            'code' => [
                'required',
                'string',
                'min:2',
                'max:100',
            ],

            'description' => [
                'required',
                'string',
            ],

            'cluster_id' => [
                'required',
                'uuid',
                'exists:clusters,id',
            ],
        ]);

        $sector = $this->service->update(
            $sector,
            $validated
        );

        /*
        |--------------------------------------------------------------------------
        | AUDIT LOG
        |--------------------------------------------------------------------------
        */

        $this->systemLog->updated(
            resource: $sector,
            module: SystemLogModule::SECTORS,
            oldValues: $oldValues,
            newValues: $sector->getAttributes(),
            description: 'Sector updated successfully',
        );

        return ApiResponse::success(
            new SectorResource($sector),
            'Sector updated successfully'
        );
    }


    /**
     * =========================================================
     * DELETE SECTOR
     * =========================================================
     */
    public function destroy(
        Sector $sector
    ) {
        /*
        |--------------------------------------------------------------------------
        | Authorization
        |--------------------------------------------------------------------------
        */

        $this->authorize(
            'delete',
            $sector
        );

        /*
        |--------------------------------------------------------------------------
        | Capture old values BEFORE deletion
        |--------------------------------------------------------------------------
        |
        | This is important because after deletion the model may
        | no longer represent the persisted record.
        |
        */

        $oldValues = $sector->getAttributes();

        /*
        |--------------------------------------------------------------------------
        | Delete
        |--------------------------------------------------------------------------
        */

        $this->service->delete(
            $sector
        );

        /*
        |--------------------------------------------------------------------------
        | AUDIT LOG
        |--------------------------------------------------------------------------
        |
        | Log only after successful deletion.
        |
        */

        $this->systemLog->deleted(
            resource: $sector,
            module: SystemLogModule::SECTORS,
            oldValues: $oldValues,
            description: 'Sector deleted successfully',
        );

        return ApiResponse::success(
            null,
            'Sector deleted successfully'
        );
    }
}
