<?php

namespace App\Modules\Citizens\Imports;

use App\Models\Citizen;
use App\Rules\UniqueInImportBatch;
use App\Services\CitizenUidService;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Database\QueryException;

use Maatwebsite\Excel\Concerns\Importable;
use Maatwebsite\Excel\Concerns\SkipsErrors;
use Maatwebsite\Excel\Concerns\SkipsFailures;

use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithBatchInserts;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\SkipsOnError;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Concerns\WithEvents;

use Maatwebsite\Excel\Events\BeforeImport;
use Maatwebsite\Excel\Events\AfterImport;
use Maatwebsite\Excel\Events\BeforeSheet;

use Maatwebsite\Excel\Validators\Failure;


class CitizenImport implements
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
    use SkipsFailures;
    use SkipsErrors;


    protected string $batchId;


    protected int $importedCount = 0;


    protected CitizenUidService $uidService;



    public function __construct(
        string $batchId,
        CitizenUidService $uidService
    ) {

        $this->batchId = $batchId;

        $this->uidService = $uidService;


        Log::info('Citizen import initialized', [
            'batch_id' => $this->batchId,
        ]);
    }



    public function registerEvents(): array
    {
        return [

            BeforeImport::class => function () {

                Log::info('Excel reading started', [
                    'batch_id' => $this->batchId,
                ]);

            },


            BeforeSheet::class => function (BeforeSheet $event) {

                $sheet = $event->sheet->getDelegate();


                Log::info('Worksheet information', [

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
                    false
                );


                Log::info('Excel header row', [

                    'batch_id' => $this->batchId,

                    'header' => $header[0] ?? [],

                ]);



                $firstRow = $sheet->rangeToArray(
                    'A2:' . $sheet->getHighestColumn() . '2',
                    null,
                    true,
                    true,
                    false
                );


                Log::info('Excel first data row', [

                    'batch_id' => $this->batchId,

                    'row' => $firstRow[0] ?? [],

                ]);

            },


            AfterImport::class => function () {

                Log::info('Excel reading completed', [

                    'batch_id' => $this->batchId,

                    'imported' => $this->importedCount,

                    'failed' => count($this->failures()),

                ]);

            },

        ];
    }




    public function prepareForValidation($row, $index)
    {

        Log::info('Raw Excel row', [

            'batch_id' => $this->batchId,

            'row_number' => $index,

            'row' => $row,

        ]);



        return [

            ...$row,


            'full_name' => isset($row['full_name'])
                ? trim($row['full_name'])
                : null,


            'national_id' => isset($row['national_id'])
                ? trim($row['national_id'])
                : null,


            'phone' => isset($row['phone'])
                ? trim($row['phone'])
                : null,


            'gender' => isset($row['gender'])
                ? strtoupper(trim($row['gender']))
                : null,


            'address' => isset($row['address'])
                ? trim($row['address'])
                : null,

        ];
    }





    public function model(array $row)
    {

        $this->importedCount++;



        $citizenUid = $this->uidService->generate();



        Log::info('Citizen model()', [

            'batch_id' => $this->batchId,

            'row_number' => $this->importedCount,

            'citizen_uid' => $citizenUid,

            'row' => $row,

        ]);



        return new Citizen([


            'id' => (string) Str::uuid(),


            'citizen_uid' => $citizenUid,


            'full_name' => trim($row['full_name'] ?? ''),


            'national_id' => trim($row['national_id'] ?? ''),


            'phone' => trim($row['phone'] ?? ''),


            'gender' => $this->normalizeGender(
                $row['gender'] ?? null
            ),

            'date_of_birth' => $row['date_of_birth'] ?: null,


            'address' => trim($row['address'] ?? ''),


            'source' => 'IMPORT',


            'is_active' => true,


        ]);
    }




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

                'unique:citizens,national_id',

                new UniqueInImportBatch(
                    $this->batchId,
                    'national_id'
                ),

            ],


            'phone' => [

                'required',

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
        return 1000;
    }



    public function batchSize(): int
    {
        return 1000;
    }

    private function normalizeGender(?string $gender): ?string
{
    if (!$gender) {
        return null;
    }


    $gender = strtoupper(trim($gender));


    return match ($gender) {

        'M',
        'MALE',
        'MAN',
        '1'
            => 'MALE',


        'F',
        'FEMALE',
        'WOMAN',
        '2'
            => 'FEMALE',


        'O',
        'OTHER',
        '3'
            => 'OTHER',


        default
            => null,
    };
}





    public function onFailure(Failure ...$failures)
    {

        foreach ($failures as $failure) {


            Log::warning('Validation failed', [

                'batch_id' => $this->batchId,

                'row' => $failure->row(),

                'field' => $failure->attribute(),

                'errors' => $failure->errors(),

                'values' => $failure->values(),

            ]);

        }
    }





    public function onError(\Throwable $e)
    {

        Log::error('Import error', [

            'batch_id' => $this->batchId,

            'exception' => get_class($e),

            'message' => $e->getMessage(),

        ]);



        if (
            $e instanceof QueryException &&
            in_array($e->getCode(), ['23000', '23505'])
        ) {

            return;

        }


        throw $e;
    }




    public function getImportedCount(): int
    {
        return $this->importedCount;
    }
}