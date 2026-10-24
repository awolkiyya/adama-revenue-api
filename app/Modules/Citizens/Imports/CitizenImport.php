<?php

declare(strict_types=1);

namespace App\Modules\Citizens\Imports;

use App\Services\DocumentSequenceService;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\Importable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Validators\Failure;

class CitizenImport implements WithMultipleSheets
{
    use Importable;

    private const CITIZEN_SHEET = 'Citizens';

    private CitizenRowsImport $citizenRowsImport;

    public function __construct(
        string $batchId,
        DocumentSequenceService $documentSequenceService,
    ) {
        $this->citizenRowsImport = new CitizenRowsImport(
            $batchId,
            $documentSequenceService,
        );
    }

    /**
     * Import only the Citizens worksheet from Excel workbooks.
     *
     * @return array<string, CitizenRowsImport>
     */
    public function sheets(): array
    {
        return [
            self::CITIZEN_SHEET => $this->citizenRowsImport,
        ];
    }

    /**
     * Return validation failures collected by the row importer.
     *
     * @return Collection<int, Failure>
     */
    public function failures(): Collection
    {
        return $this->citizenRowsImport->failures();
    }

    public function getImportedCount(): int
    {
        return $this->citizenRowsImport->getImportedCount();
    }

    public function getProcessedCount(): int
    {
        return $this->citizenRowsImport->getProcessedCount();
    }

    public function getDatabaseErrorCount(): int
    {
        return $this->citizenRowsImport->getDatabaseErrorCount();
    }

    public function getRowsImporter(): CitizenRowsImport
    {
        return $this->citizenRowsImport;
    }
}
