<?php

namespace App\Modules\Revenue\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Revenue\Requests\StoreTariffVersionRequest;
use App\Modules\Revenue\Requests\UpdateTariffVersionRequest;
use App\Modules\Revenue\Resources\TariffVersionResource;
use App\Modules\Revenue\Services\TariffVersionService;
use App\Services\ApiResponse;
use Illuminate\Http\Request;

class TariffVersionController extends Controller
{
    public function __construct(
        protected TariffVersionService $service
    ) {
    }


    /**
     * Display paginated tariff versions.
     */
    public function index(Request $request)
    {
        $tariffVersions = $this->service->paginate(
            $request->all()
        );


        return ApiResponse::success(
            TariffVersionResource::collection(
                $tariffVersions
            ),
            'Tariff versions retrieved successfully.',
            summary:$this->service->summary(),
        );
    }



    /**
     * Dashboard summary.
     */
    public function summary()
    {
        return ApiResponse::success(
            null,
            'Tariff version summary retrieved successfully.',
            [],
            200,
            $this->service->summary()
        );
    }



    /**
     * Display a single tariff version.
     */
    public function show(string $id)
    {
        $tariffVersion = $this->service->find($id);


        return ApiResponse::success(
            new TariffVersionResource(
                $tariffVersion
            ),
            'Tariff version retrieved successfully.'
        );
    }



    /**
     * Store new tariff version.
     */
    public function store(
        StoreTariffVersionRequest $request
    ) {

        $tariffVersion = $this->service->create(
            $request->validated()
        );


        return ApiResponse::created(
            new TariffVersionResource(
                $tariffVersion
            ),
            'Tariff version created successfully.'
        );
    }



    /**
     * Update tariff version.
     */
    public function update(
        UpdateTariffVersionRequest $request,
        string $id
    ) {

        $tariffVersion = $this->service->update(
            $id,
            $request->validated()
        );


        return ApiResponse::updated(
            new TariffVersionResource(
                $tariffVersion
            ),
            'Tariff version updated successfully.'
        );
    }



    /**
     * Activate tariff version.
     */
    public function activate(string $id)
    {

        $tariffVersion = $this->service->activate(
            $id
        );


        return ApiResponse::updated(
            new TariffVersionResource(
                $tariffVersion
            ),
            'Tariff version activated successfully.'
        );
    }



    /**
     * Soft delete tariff version.
     */
    public function destroy(string $id)
    {
        $this->service->delete($id);


        return ApiResponse::deleted(
            'Tariff version deleted successfully.'
        );
    }



    /**
     * Restore deleted tariff version.
     */
    public function restore(string $id)
    {
        $this->service->restore($id);


        return ApiResponse::success(
            null,
            'Tariff version restored successfully.'
        );
    }
}