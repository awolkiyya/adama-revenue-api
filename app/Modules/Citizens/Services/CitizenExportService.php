<?php

namespace App\Modules\Citizens\Services;

use App\Modules\Citizens\Exports\CitizenExport;
use Maatwebsite\Excel\Facades\Excel;


class CitizenExportService
{


    /**
     * Export citizens.
     */
    public function export(array $filters = [])
    {

        $fileName =
            'citizens_' .
            now()->format('Y_m_d_His') .
            '.xlsx';



        return Excel::download(
            new CitizenExport($filters),
            $fileName
        );

    }


}