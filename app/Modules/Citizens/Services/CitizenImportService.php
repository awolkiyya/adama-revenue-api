<?php

declare(strict_types=1);

namespace App\Modules\Citizens\Services;

use App\Modules\Citizens\Imports\CitizenImport;
use App\Modules\Citizens\Imports\CitizenRowsImport;
use App\Services\DocumentSequenceService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

class CitizenImportService
{
    public function __construct(
        protected DocumentSequenceService $documentSequenceService,
    ) {
    }

    /**
     * Download the citizen import template.
     */
    public function downloadTemplate()
    {
        $path = storage_path(
            'app/templates/citizen_import_template.xlsx',
        );

        if (! is_file($path)) {
            abort(404, 'The citizen import template could not be found.');
        }

        return response()->download(
            $path,
            'citizen_import_template.xlsx',
        );
    }

    /**
     * Import citizens from a CSV or Excel file.
     *
     * CSV files are imported directly through CitizenRowsImport because
     * CSV files do not contain named worksheets.
     *
     * Excel files are imported through CitizenImport, which selects
     * only the worksheet named "Citizens".
     *
     * `failed` represents validation failures.
     * Database errors are reported separately.
     *
     * @return array{
     *     batch_id: string,
     *     processed: int,
     *     imported: int,
     *     failed: int,
     *     database_errors: int,
     *     errors: Collection<int, array{
     *         row: int,
     *         field: string,
     *         errors: array<int, string>,
     *         values: array<string, mixed>
     *     }>
     * }
     */
    public function import(UploadedFile $file): array
    {
        $batchId = (string) Str::uuid();

        $requestId = request()->attributes->get('request_id');

        $extension = strtolower(
            $file->getClientOriginalExtension(),
        );

        Log::info('Citizen import started', [
            'request_id' => $requestId,
            'batch_id' => $batchId,
            'file' => $file->getClientOriginalName(),
            'extension' => $extension,
            'size' => $file->getSize(),
            'mime' => $file->getMimeType(),
        ]);

        try {
            if (! in_array($extension, ['csv', 'txt', 'xlsx', 'xls'], true)) {
                throw new InvalidArgumentException(
                    'Unsupported file format. Upload a CSV or Excel file.',
                );
            }

            $import = new CitizenImport(
                $batchId,
                $this->documentSequenceService,
            );

            if (in_array($extension, ['csv', 'txt'], true)) {
                /*
                 * CSV files have no named worksheets.
                 * Import the rows directly.
                 */
                $rowsImport = $import->getRowsImporter();

                Excel::import($rowsImport, $file);

                $failures = $rowsImport->failures();
                $processed = $rowsImport->getProcessedCount();
                $imported = $rowsImport->getImportedCount();
                $databaseErrors = $rowsImport->getDatabaseErrorCount();
            } else {
                /*
                 * Excel workbooks use the CitizenImport wrapper,
                 * which imports only the "Citizens" worksheet.
                 */
                Excel::import($import, $file);

                $failures = $import->failures();
                $processed = $import->getProcessedCount();
                $imported = $import->getImportedCount();
                $databaseErrors = $import->getDatabaseErrorCount();
            }

            $errors = collect($failures)
                ->map(static function ($failure): array {
                    return [
                        'row' => $failure->row(),
                        'field' => $failure->attribute(),
                        'errors' => $failure->errors(),
                        'values' => $failure->values(),
                    ];
                })
                ->values();

            $result = [
                'batch_id' => $batchId,
                'processed' => $processed,
                'imported' => $imported,
                'failed' => $errors->count(),
                'database_errors' => $databaseErrors,
                'errors' => $errors,
            ];

            Log::info('Citizen import finished', [
                'request_id' => $requestId,
                'batch_id' => $batchId,
                'file' => $file->getClientOriginalName(),
                'extension' => $extension,
                'processed' => $result['processed'],
                'imported' => $result['imported'],
                'validation_failures' => $result['failed'],
                'database_errors' => $result['database_errors'],
            ]);

            return $result;
        } catch (Throwable $e) {
            Log::error('Citizen import failed', [
                'request_id' => $requestId,
                'batch_id' => $batchId,
                'file' => $file->getClientOriginalName(),
                'extension' => $extension,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
