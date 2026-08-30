<?php

namespace App\Services\Storage;

use App\Models\Report;
use App\Models\ReportExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;

class ReportExportService
{
    public function __construct(
        protected StorageService $storageService
    ) {}

    public function generatePdfExport(
        Report $report,
        ?int $generatedBy = null
    ): ?ReportExport {

        try {

            /**
             * GENERATE PDF
             */
            $pdf = Pdf::loadView(
                'exports.reports.pdf',
                [
                    'report' => $report
                ]
            );

            /**
             * FILE NAME
             */
            $filename =
                'report_' .
                $report->id .
                '_' .
                Str::uuid() .
                '.pdf';

            /**
             * STORE USING STORAGE SERVICE
             */
            $file = $this->storageService->storeRaw(
                content: $pdf->output(),
                folder: 'reports/generated/pdf',
                filename: $filename,
                mimeType: 'application/pdf',
                extension: 'pdf',
                category: 'generated_report',
                visibility: 'private',
                uploadedBy: $generatedBy
            );

            if (!$file) {
                return null;
            }

            /**
             * CREATE EXPORT RECORD
             */
            return ReportExport::create([
                'report_id' => $report->id,
                'file_id' => $file->id,
                'type' => 'PDF',
                'generated_by' => $generatedBy,
                'generated_at' => now(),
            ]);

        } catch (\Exception $e) {

            report($e);

            return null;
        }
    }
}