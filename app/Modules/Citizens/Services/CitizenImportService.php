<?php

namespace App\Modules\Citizens\Services;

use App\Modules\Citizens\Imports\CitizenImport;
use App\Services\CitizenUidService;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

use Maatwebsite\Excel\Facades\Excel;


class CitizenImportService
{

    protected CitizenUidService $uidService;



    public function __construct(
        CitizenUidService $uidService
    ) {
        $this->uidService = $uidService;
    }




    /**
     * Download import template.
     */
    public function downloadTemplate()
    {
        $path = storage_path(
            'app/templates/citizen_import_template.xlsx'
        );


        return response()->download(
            $path,
            'citizen_import_template.xlsx'
        );
    }




    /**
     * Import citizens.
     */
    public function import(
        UploadedFile $file
    ): array {


        /**
         * Create import batch id.
         */
        $batchId = (string) Str::uuid();



        Log::info(
            'Citizen import started',
            [

                'request_id' =>
                    request()->attributes->get('request_id'),

                'batch_id' =>
                    $batchId,

                'file' =>
                    $file->getClientOriginalName(),

                'size' =>
                    $file->getSize(),

                'mime' =>
                    $file->getMimeType(),

            ]
        );



        try {


            /**
             * Create importer.
             */
            $import = new CitizenImport(

                $batchId,

                $this->uidService

            );



            /**
             * Execute import.
             */
            Excel::import(
                $import,
                $file
            );



            /**
             * Get failures.
             */
            $failures = $import->failures();



            $result = [

                'batch_id' => $batchId,


                'imported' =>
                    $import->getImportedCount(),


                'failed' =>
                    count($failures),


                'errors' =>
                    collect($failures)
                        ->map(function ($failure) {


                            return [

                                'row' =>
                                    $failure->row(),


                                'field' =>
                                    $failure->attribute(),


                                'errors' =>
                                    $failure->errors(),


                                'values' =>
                                    $failure->values(),

                            ];

                        })
                        ->values(),

            ];



            Log::info(
                'Citizen import finished',
                [

                    'request_id' =>
                        request()->attributes->get('request_id'),


                    'batch_id' =>
                        $batchId,


                    'imported' =>
                        $result['imported'],


                    'failed' =>
                        $result['failed'],

                ]
            );



            return $result;



        } catch (\Throwable $e) {


            Log::error(
                'Citizen import failed',
                [

                    'request_id' =>
                        request()->attributes->get('request_id'),


                    'batch_id' =>
                        $batchId,


                    'exception' =>
                        get_class($e),


                    'message' =>
                        $e->getMessage(),


                    'trace' =>
                        $e->getTraceAsString(),

                ]
            );


            throw $e;

        }

    }

}