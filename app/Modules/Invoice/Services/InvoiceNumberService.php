<?php

namespace App\Modules\Invoice\Services;

use Andegna\DateTimeFactory;
use App\Models\InvoiceSequence;
use RuntimeException;

class InvoiceNumberService
{
    /*
    |--------------------------------------------------------------------------
    | CONFIGURATION
    |--------------------------------------------------------------------------
    */

    private const PREFIX = 'INV';

    private const SEQUENCE_LENGTH = 6;


    /*
    |--------------------------------------------------------------------------
    | GENERATE NEXT INVOICE NUMBER
    |--------------------------------------------------------------------------
    |
    | Example:
    |
    | INV-2018-000001
    | INV-2018-000002
    | INV-2018-000003
    |
    | IMPORTANT:
    |
    | This method must be called inside the caller's database
    | transaction because the sequence row is locked with
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
        | LOCK YEAR SEQUENCE
        |--------------------------------------------------------------------------
        */

        $sequence = InvoiceSequence::query()
            ->where('year', $ethiopianYear)
            ->lockForUpdate()
            ->first();


        /*
        |--------------------------------------------------------------------------
        | CREATE FIRST SEQUENCE FOR YEAR
        |--------------------------------------------------------------------------
        */

        if (!$sequence) {
            $sequence = InvoiceSequence::query()->create([
                'year' => $ethiopianYear,
                'last_number' => 0,
            ]);
        }


        /*
        |--------------------------------------------------------------------------
        | NEXT SEQUENCE NUMBER
        |--------------------------------------------------------------------------
        */

        $nextNumber = $sequence->last_number + 1;


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
        */

        return sprintf(
            '%s-%d-%0*d',
            self::PREFIX,
            $ethiopianYear,
            self::SEQUENCE_LENGTH,
            $nextNumber
        );
    }


    /*
    |--------------------------------------------------------------------------
    | GET CURRENT ETHIOPIAN YEAR
    |--------------------------------------------------------------------------
    */

    public function getCurrentEthiopianYear(): int
    {
        /*
        |--------------------------------------------------------------------------
        | USE AFRICA/ADDIS_ABABA
        |--------------------------------------------------------------------------
        |
        | This is important for a municipal Ethiopian system.
        |
        */

        $gregorian = new \DateTime(
            'now',
            new \DateTimeZone('Africa/Addis_Ababa')
        );


        /*
        |--------------------------------------------------------------------------
        | CONVERT GREGORIAN → ETHIOPIAN
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
    | Returns the last generated invoice sequence for the
    | current Ethiopian year.
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