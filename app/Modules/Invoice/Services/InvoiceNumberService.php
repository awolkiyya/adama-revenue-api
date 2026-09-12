<?php

namespace App\Modules\Invoice\Services;

use Andegna\DateTimeFactory;
use App\Models\InvoiceSequence;
use App\Models\RevenueSetting;
use Illuminate\Database\QueryException;
use RuntimeException;

class InvoiceNumberService
{
    /*
    |--------------------------------------------------------------------------
    | CONFIGURATION
    |--------------------------------------------------------------------------
    */

    private const DEFAULT_PREFIX = 'INV';

    private const SEQUENCE_LENGTH = 6;


    /*
    |--------------------------------------------------------------------------
    | GENERATE NEXT INVOICE NUMBER
    |--------------------------------------------------------------------------
    |
    | Examples:
    |
    | INV-2018-000001
    | INV-2018-000002
    | INV-2018-000003
    |
    | IMPORTANT:
    |
    | This method must be called inside the caller's database
    | transaction because the sequence row is protected with
    | lockForUpdate().
    |
    |--------------------------------------------------------------------------
    */

    public function generate(): string
    {
        /*
        |--------------------------------------------------------------------------
        | GET CURRENT ETHIOPIAN YEAR
        |--------------------------------------------------------------------------
        */

        $ethiopianYear = $this->getCurrentEthiopianYear();


        /*
        |--------------------------------------------------------------------------
        | GET CONFIGURED INVOICE PREFIX
        |--------------------------------------------------------------------------
        */

        $prefix = $this->getInvoicePrefix();


        /*
        |--------------------------------------------------------------------------
        | LOCK EXISTING YEAR SEQUENCE
        |--------------------------------------------------------------------------
        |
        | If the sequence already exists, lock it so concurrent invoice
        | creation cannot allocate the same number.
        |
        */

        $sequence = InvoiceSequence::query()
            ->where('year', $ethiopianYear)
            ->lockForUpdate()
            ->first();


        /*
        |--------------------------------------------------------------------------
        | CREATE FIRST SEQUENCE FOR YEAR
        |--------------------------------------------------------------------------
        |
        | The unique constraint on invoice_sequences.year protects
        | against duplicate sequence rows.
        |
        */

        if (!$sequence) {
            try {
                $sequence = InvoiceSequence::query()->create([
                    'year' => $ethiopianYear,
                    'last_number' => 0,
                ]);
            } catch (QueryException $exception) {

                /*
                |--------------------------------------------------------------------------
                | CONCURRENT FIRST CREATION
                |--------------------------------------------------------------------------
                |
                | Another transaction may have created the sequence
                | for this Ethiopian year at the same time.
                |
                | Re-read the sequence and lock it.
                |
                */

                $sequence = InvoiceSequence::query()
                    ->where('year', $ethiopianYear)
                    ->lockForUpdate()
                    ->first();

                if (!$sequence) {
                    throw $exception;
                }
            }
        }


        /*
        |--------------------------------------------------------------------------
        | CALCULATE NEXT NUMBER
        |--------------------------------------------------------------------------
        */

        $nextNumber = (int) $sequence->last_number + 1;


        /*
        |--------------------------------------------------------------------------
        | SAFETY CHECK
        |--------------------------------------------------------------------------
        */

        if ($nextNumber <= 0) {
            throw new RuntimeException(
                'Invoice sequence overflow.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | SAVE SEQUENCE
        |--------------------------------------------------------------------------
        */

        $sequence->update([
            'last_number' => $nextNumber,
        ]);


        /*
        |--------------------------------------------------------------------------
        | BUILD INVOICE NUMBER
        |--------------------------------------------------------------------------
        |
        | Example:
        |
        | INV-2018-000001
        |
        |--------------------------------------------------------------------------
        */

        return sprintf(
            '%s-%d-%0*d',
            $prefix,
            $ethiopianYear,
            self::SEQUENCE_LENGTH,
            $nextNumber
        );
    }


    /*
    |--------------------------------------------------------------------------
    | GET INVOICE PREFIX
    |--------------------------------------------------------------------------
    |
    | The prefix is controlled by the active revenue settings.
    |
    | Example:
    |
    | INV
    | REV
    |
    |--------------------------------------------------------------------------
    */

    private function getInvoicePrefix(): string
    {
        $prefix = RevenueSetting::query()
            ->where('is_active', true)
            ->value('invoice_prefix');


        /*
        |--------------------------------------------------------------------------
        | FALLBACK TO DEFAULT
        |--------------------------------------------------------------------------
        */

        if ($prefix === null) {
            return self::DEFAULT_PREFIX;
        }


        /*
        |--------------------------------------------------------------------------
        | NORMALIZE
        |--------------------------------------------------------------------------
        */

        $prefix = trim((string) $prefix);


        /*
        |--------------------------------------------------------------------------
        | EMPTY PREFIX SAFETY
        |--------------------------------------------------------------------------
        */

        if ($prefix === '') {
            return self::DEFAULT_PREFIX;
        }


        return $prefix;
    }


    /*
    |--------------------------------------------------------------------------
    | GET CURRENT ETHIOPIAN YEAR
    |--------------------------------------------------------------------------
    |
    | Uses the Addis Ababa timezone and Andegna for the
    | Gregorian → Ethiopian calendar conversion.
    |
    |--------------------------------------------------------------------------
    */

    public function getCurrentEthiopianYear(): int
    {
        /*
        |--------------------------------------------------------------------------
        | CURRENT LOCAL DATE/TIME
        |--------------------------------------------------------------------------
        */

        $gregorian = new \DateTime(
            'now',
            new \DateTimeZone('Africa/Addis_Ababa')
        );


        /*
        |--------------------------------------------------------------------------
        | GREGORIAN → ETHIOPIAN
        |--------------------------------------------------------------------------
        */

        $ethiopian = DateTimeFactory::fromDateTime(
            $gregorian
        );


        return (int) $ethiopian->getYear();
    }


    /*
    |--------------------------------------------------------------------------
    | GET CURRENT SEQUENCE
    |--------------------------------------------------------------------------
    |
    | Returns the last allocated invoice sequence for the
    | current Ethiopian year.
    |
    | Returns 0 when no invoice has been generated yet.
    |
    |--------------------------------------------------------------------------
    */

    public function current(): int
    {
        $ethiopianYear = $this->getCurrentEthiopianYear();

        return (int) (
            InvoiceSequence::query()
                ->where('year', $ethiopianYear)
                ->value('last_number')
            ?? 0
        );
    }
}