<?php

declare(strict_types=1);

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
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Throwable;

class CitizenImportController extends Controller
{
    public function __construct(
        private readonly CitizenImportService $service,
        private readonly SystemLogService $systemLogService,
    ) {}

    /**
     * Download the citizen import template.
     */
    public function template(): Response|\Symfony\Component\HttpFoundation\BinaryFileResponse|\Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->authorize('import', Citizen::class);

        return $this->service->downloadTemplate();
    }

    /**
     * Import citizens from an uploaded file.
     */
    public function import(CitizenImportRequest $request): JsonResponse
    {
        $file = $request->file('file');

        try {
            $this->authorize('import', Citizen::class);

            if (!$file instanceof UploadedFile) {
                return ApiResponse::error(
                    'A valid import file is required.',
                    422,
                );
            }

            $result = $this->service->import($file);

            $this->systemLogService->log(
                action: SystemLogAction::IMPORT,
                module: SystemLogModule::CITIZENS,
                description: 'Citizen import completed',
                metadata: [
                    'operation' => 'CITIZEN_IMPORT',
                    'file_name' => $this->safeFileName($file),
                    'file_size' => $file->getSize(),
                    'imported' => (int) ($result['imported'] ?? 0),
                    'failed' => (int) ($result['failed'] ?? 0),
                ],
            );

            return ApiResponse::success(
                $result,
                'Citizens imported successfully.',
            );
        } catch (AuthorizationException $e) {
            return ApiResponse::forbidden(
                'You are not authorized to import citizens.',
            );
        } catch (Throwable $e) {
            Log::error('Citizen import failed.', [
                'exception' => $e::class,
            ]);

            $this->systemLogService->log(
                action: SystemLogAction::IMPORT,
                module: SystemLogModule::CITIZENS,
                description: 'Citizen import failed',
                metadata: [
                    'operation' => 'CITIZEN_IMPORT',
                    'file_name' => $file instanceof UploadedFile
                        ? $this->safeFileName($file)
                        : null,
                    'file_size' => $file instanceof UploadedFile
                        ? $file->getSize()
                        : null,
                    'exception' => $e::class,
                ],
            );

            return ApiResponse::serverError(
                'Citizen import failed.',
            );
        }
    }

    /**
     * Return a safe filename for audit metadata.
     */
    private function safeFileName(UploadedFile $file): string
    {
        return mb_substr(
            basename($file->getClientOriginalName()),
            0,
            255,
        );
    }
}
