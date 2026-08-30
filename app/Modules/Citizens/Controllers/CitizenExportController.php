<?php

namespace App\Modules\Citizens\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Citizens\Services\CitizenExportService;
use Illuminate\Http\Request;


class CitizenExportController extends Controller
{

    public function __construct(
        private CitizenExportService $service
    ) {}



    /**
     * Export citizens.
     *
     * Example:
     * GET /api/citizens/export
     *
     * Filters:
     * ?source=MANUAL
     * ?gender=MALE
     * ?is_active=1
     * ?administrative_unit_id=uuid
     */
    public function export(Request $request)
    {

        return $this->service->export(
            $request->all()
        );

    }

}