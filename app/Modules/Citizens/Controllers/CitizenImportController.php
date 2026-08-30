<?php

namespace App\Modules\Citizens\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Citizen;
use App\Modules\Citizens\Requests\CitizenImportRequest;
use App\Modules\Citizens\Services\CitizenImportService;
use App\Services\ApiResponse;
use App\Services\SystemLogService;
use App\Supports\SystemLogAction;
use App\Supports\SystemLogModule;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;

class CitizenImportController extends Controller
{
    public function __construct(
        private CitizenImportService $service,
        private SystemLogService $systemLogService,
    ) {}

    /**
     * ============================================================
     * DOWNLOAD IMPORT TEMPLATE
     * ============================================================
     */
    public function template()
    {
        return $this->service->downloadTemplate();
    }

    /**
     * ============================================================
     * IMPORT CITIZENS
     * ============================================================
     */
    public function import(
        CitizenImportRequest $request
    ): JsonResponse {

        try {

            /*
            |--------------------------------------------------------------------------
            | AUTHORIZATION
            |--------------------------------------------------------------------------
            */

            $this->authorize(
                'import',
                Citizen::class
            );

            /*
            |--------------------------------------------------------------------------
            | FILE
            |--------------------------------------------------------------------------
            */

            $file = $request->file('file');

            /*
            |--------------------------------------------------------------------------
            | IMPORT
            |--------------------------------------------------------------------------
            */

            $result = $this->service->import(
                $file
            );

            /*
            |--------------------------------------------------------------------------
            | AUDIT LOG
            |--------------------------------------------------------------------------
            |
            | IMPORT is a system action.
            |
            | There is no single Citizen model representing the
            | entire import operation, therefore use log()
            | instead of created().
            |
            */

            $this->systemLogService->log(
                action: SystemLogAction::IMPORT,
                module: SystemLogModule::CITIZENS,
                description: 'Citizens imported successfully',
                metadata: [
                    'operation' => 'CITIZEN_IMPORT',

                    'file_name' =>
                        $file->getClientOriginalName(),

                    'file_size' =>
                        $file->getSize(),

                    'imported' =>
                        $result['imported'] ?? 0,

                    'failed' =>
                        $result['failed'] ?? 0,
                ],
            );

            /*
            |--------------------------------------------------------------------------
            | RESPONSE
            |--------------------------------------------------------------------------
            */

            return ApiResponse::success(
                $result,
                'Citizens imported successfully.'
            );

        } catch (AuthorizationException $e) {

            return ApiResponse::forbidden(
                'You are not authorized to import citizens.'
            );

        } catch (\Throwable $e) {

            /*
            |--------------------------------------------------------------------------
            | AUDIT IMPORT FAILURE
            |--------------------------------------------------------------------------
            */

            $this->systemLogService->log(
                action: SystemLogAction::IMPORT,
                module: SystemLogModule::CITIZENS,
                description: 'Citizen import failed',
                metadata: [
                    'operation' => 'CITIZEN_IMPORT',

                    'file_name' =>
                        $request->hasFile('file')
                            ? $request->file('file')
                                ->getClientOriginalName()
                            : null,

                    'exception' =>
                        get_class($e),

                    'message' =>
                        $e->getMessage(),
                ],
            );

            return ApiResponse::serverError(
                'Citizen import failed.',
                $e
            );
        }
    }
}