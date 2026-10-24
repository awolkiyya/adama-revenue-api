<?php

declare(strict_types=1);

namespace App\Modules\Citizens\Imports;

use App\Models\Citizen;
use App\Rules\UniqueInImportBatch;
use App\Services\DocumentSequenceService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\Importable;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\SkipsErrors;
use Maatwebsite\Excel\Concerns\SkipsOnError;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithBatchInserts;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;
use Maatwebsite\Excel\Events\AfterImport;
use Maatwebsite\Excel\Events\BeforeImport;
use Maatwebsite\Excel\Events\BeforeSheet;
use Maatwebsite\Excel\Validators\Failure;
use Throwable;

class CitizenRowsImport implements
    ToModel,
    WithHeadingRow,
    WithValidation,
    SkipsOnFailure,
    SkipsOnError,
    SkipsEmptyRows,
    WithChunkReading,
    WithBatchInserts,
    WithEvents
{
    use Importable;
    use SkipsErrors;

    protected string $batchId;

    /**
     * Number of rows that reached model().
     */
    protected int $processedCount = 0;

    /**
     * Number of persisted citizen records verified after import.
     */
    protected int $importedCount = 0;

    /**
     * Number of persistence errors reported by Laravel Excel.
     */
    protected int $databaseErrorCount = 0;

    protected DocumentSequenceService $documentSequenceService;

    /**
     * UUIDs assigned to models returned by model().
     *
     * @var array<int, string>
     */
    protected array $generatedCitizenIds = [];

    /**
     * Validation failures collected explicitly.
     *
     * @var array<int, Failure>
     */
    protected array $validationFailures = [];

    public function __construct(
        string $batchId,
        DocumentSequenceService $documentSequenceService,
    ) {
        $this->batchId = $batchId;
        $this->documentSequenceService = $documentSequenceService;

        Log::info('Citizen row import initialized', [
            'batch_id' => $this->batchId,
        ]);
    }

    /**
     * Register Excel import lifecycle events.
     */
    public function registerEvents(): array
    {
        return [
            BeforeImport::class => function (): void {
                Log::info('Citizen worksheet import started', [
                    'batch_id' => $this->batchId,
                ]);
            },

            BeforeSheet::class => function (BeforeSheet $event): void {
                $sheet = $event->sheet->getDelegate();

                Log::info('Citizen worksheet information', [
                    'batch_id' => $this->batchId,
                    'title' => $sheet->getTitle(),
                    'highest_row' => $sheet->getHighestRow(),
                    'highest_column' => $sheet->getHighestColumn(),
                ]);

                $header = $sheet->rangeToArray(
                    'A1:' . $sheet->getHighestColumn() . '1',
                    null,
                    true,
                    true,
                    false,
                );

                Log::info('Citizen worksheet header row', [
                    'batch_id' => $this->batchId,
                    'header' => $header[0] ?? [],
                ]);
            },

            AfterImport::class => function (): void {
                $this->importedCount = empty($this->generatedCitizenIds)
                    ? 0
                    : (int) Citizen::query()
                        ->whereIn('id', $this->generatedCitizenIds)
                        ->count();

                Log::info('Citizen worksheet import completed', [
                    'batch_id' => $this->batchId,
                    'processed' => $this->processedCount,
                    'imported' => $this->importedCount,
                    'validation_failures' => count(
                        $this->validationFailures
                    ),
                    'database_errors' => $this->databaseErrorCount,
                ]);
            },
        ];
    }

    /**
     * Normalize row values before validation.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function prepareForValidation($row, $index): array
    {
        $normalized = [];

        foreach ($row as $key => $value) {
            $normalized[$key] = is_string($value)
                ? trim($value)
                : $value;
        }

        if (
            !array_key_exists('phone', $normalized)
            && array_key_exists('phone_number', $normalized)
        ) {
            $normalized['phone'] = $normalized['phone_number'];
        }

        if (
            !array_key_exists('full_name', $normalized)
            && array_key_exists('name', $normalized)
        ) {
            $normalized['full_name'] = $normalized['name'];
        }

        $normalized['full_name'] = $this->nullableTrim(
            $normalized['full_name'] ?? null
        );

        $normalized['national_id'] = $this->nullableTrim(
            $normalized['national_id'] ?? null
        );

        $normalized['phone'] = $this->nullableTrim(
            $normalized['phone'] ?? null
        );

        $normalized['gender'] = $this->normalizeGender(
            $normalized['gender'] ?? null
        );

        $normalized['address'] = $this->nullableTrim(
            $normalized['address'] ?? null
        );

        $normalized['date_of_birth'] = $this->nullableTrim(
            $normalized['date_of_birth'] ?? null
        );

        Log::debug('Citizen import row prepared', [
            'batch_id' => $this->batchId,
            'row_number' => $index,
        ]);

        return $normalized;
    }

    /**
     * Build a citizen model after validation.
     *
     * @param array<string, mixed> $row
     */
    public function model(array $row): Citizen
    {
        $this->processedCount++;

        $citizenId = (string) Str::uuid();

        $citizenUid = $this->documentSequenceService->generate(
            'citizen'
        );

        $this->generatedCitizenIds[] = $citizenId;

        Log::debug('Building citizen import model', [
            'batch_id' => $this->batchId,
            'row_number' => $this->processedCount,
            'citizen_id' => $citizenId,
            'citizen_uid' => $citizenUid,
        ]);

        return new Citizen([
            'id' => $citizenId,
            'citizen_uid' => $citizenUid,
            'full_name' => trim((string) $row['full_name']),
            'national_id' => trim((string) $row['national_id']),
            'phone' => trim((string) $row['phone']),
            'gender' => $this->normalizeGender(
                $row['gender'] ?? null
            ),
            'date_of_birth' => $row['date_of_birth'] ?? null,
            'address' => $row['address'] ?? null,
            'source' => 'IMPORT',
            'is_active' => true,
        ]);
    }

    /**
     * Validation rules applied to each citizen row.
     */
    public function rules(): array
    {
        return [
            'full_name' => [
                'required',
                'string',
                'max:255',
            ],

            'national_id' => [
                'required',
                'string',
                'unique:citizens,national_id',
                new UniqueInImportBatch(
                    $this->batchId,
                    'national_id'
                ),
            ],

            'phone' => [
                'required',
                'string',
                'unique:citizens,phone',
                new UniqueInImportBatch(
                    $this->batchId,
                    'phone'
                ),
            ],

            'gender' => [
                'required',
                'in:MALE,FEMALE,OTHER',
            ],

            'date_of_birth' => [
                'nullable',
                'date',
            ],

            'address' => [
                'nullable',
                'string',
            ],
        ];
    }

    public function chunkSize(): int
    {
        return 500;
    }

    public function batchSize(): int
    {
        return 100;
    }

    /**
     * Collect failures and log them.
     *
     * Do not use SkipsFailures here: this class maintains its own
     * failure collection so the service can retrieve it explicitly.
     */
    public function onFailure(Failure ...$failures): void
    {
        foreach ($failures as $failure) {
            $this->validationFailures[] = $failure;

            Log::warning('Citizen import validation failed', [
                'batch_id' => $this->batchId,
                'row' => $failure->row(),
                'field' => $failure->attribute(),
                'errors' => $failure->errors(),
                'values' => $failure->values(),
            ]);
        }
    }

    /**
     * Return the collected validation failures.
     *
     * @return Collection<int, Failure>
     */
    public function failures(): Collection
    {
        return collect($this->validationFailures);
    }

    /**
     * Handle database or other persistence errors skipped by Excel.
     */
    public function onError(Throwable $e): void
    {
        $this->databaseErrorCount++;

        Log::error('Citizen import persistence error', [
            'batch_id' => $this->batchId,
            'exception' => get_class($e),
            'code' => $e->getCode(),
            'message' => $e->getMessage(),
        ]);

        if ($e instanceof QueryException) {
            return;
        }

        throw $e;
    }

    /**
     * Normalize supported gender values.
     */
    private function normalizeGender(mixed $gender): ?string
    {
        if ($gender === null || trim((string) $gender) === '') {
            return null;
        }

        return match (strtoupper(trim((string) $gender))) {
            'M', 'MALE', 'MAN', '1' => 'MALE',
            'F', 'FEMALE', 'WOMAN', '2' => 'FEMALE',
            'O', 'OTHER', '3' => 'OTHER',
            default => strtoupper(trim((string) $gender)),
        };
    }

    /**
     * Trim a value and convert empty strings to null.
     */
    private function nullableTrim(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    public function getImportedCount(): int
    {
        return $this->importedCount;
    }

    public function getProcessedCount(): int
    {
        return $this->processedCount;
    }

    public function getDatabaseErrorCount(): int
    {
        return $this->databaseErrorCount;
    }
}