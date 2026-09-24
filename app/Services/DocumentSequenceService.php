<?php

namespace App\Services;

use Andegna\DateTimeFactory;
use App\Models\DocumentSequence;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class DocumentSequenceService
{
    /**
     * Generate the next document number.
     *
     * Examples:
     *
     * ASM-2019-000001
     * INV-2019-000001
     * PAY-2019-000001
     * RCP-2019-000001
     *
     * Ethiopian calendar is used by default.
     *
     * The sequence is maintained independently for each:
     *
     * sequence_type + year
     *
     * Example:
     *
     * assessment / 2019
     * invoice    / 2019
     * payment    / 2019
     *
     * @param string      $sequenceType
     * @param string      $prefix
     * @param Carbon|null $date
     * @param string      $calendar
     *
     * @return string
     */
    public function generate(
        string $sequenceType,
        string $prefix,
        ?Carbon $date = null,
        string $calendar = 'ethiopian'
    ): string {
        /*
        |--------------------------------------------------------------------------
        | Normalize Input
        |--------------------------------------------------------------------------
        */

        $sequenceType = strtolower(
            trim($sequenceType)
        );

        $prefix = strtoupper(
            trim($prefix)
        );

        /*
        |--------------------------------------------------------------------------
        | Validate Sequence Type
        |--------------------------------------------------------------------------
        */

        if ($sequenceType === '') {
            throw new InvalidArgumentException(
                'Sequence type cannot be empty.'
            );
        }

        if (strlen($sequenceType) > 100) {
            throw new InvalidArgumentException(
                'Sequence type cannot exceed 100 characters.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Validate Prefix
        |--------------------------------------------------------------------------
        */

        if ($prefix === '') {
            throw new InvalidArgumentException(
                'Sequence prefix cannot be empty.'
            );
        }

        if (strlen($prefix) > 20) {
            throw new InvalidArgumentException(
                'Sequence prefix cannot exceed 20 characters.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Resolve Date
        |--------------------------------------------------------------------------
        */

        $date ??= now();

        /*
        |--------------------------------------------------------------------------
        | Resolve Calendar Year
        |--------------------------------------------------------------------------
        */

        $year = $this->getYear(
            $date,
            $calendar
        );

        /*
        |--------------------------------------------------------------------------
        | Generate Number Inside Transaction
        |--------------------------------------------------------------------------
        |
        | The entire sequence operation happens inside one database
        | transaction.
        |
        | This is important because the sequence must never be incremented
        | without returning the corresponding document number.
        |
        */

        return DB::transaction(
            function () use (
                $sequenceType,
                $prefix,
                $year
            ): string {
                /*
                |--------------------------------------------------------------------------
                | Create Sequence Row If It Does Not Exist
                |--------------------------------------------------------------------------
                |
                | insertOrIgnore() is concurrency-safe because the database
                | has a unique constraint on:
                |
                | sequence_type + year
                |
                | If another request creates the same sequence at the same
                | time, PostgreSQL ignores the duplicate insert.
                |
                */

                DocumentSequence::query()
                    ->insertOrIgnore([
                        'sequence_type' => $sequenceType,
                        'year' => $year,
                        'current_value' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                /*
                |--------------------------------------------------------------------------
                | Lock Sequence Row
                |--------------------------------------------------------------------------
                |
                | This is the most important part of the sequence system.
                |
                | Example:
                |
                | Request A -> locks assessment/2019
                | Request B -> waits
                |
                | Request A -> gets 126
                | Request A -> commits
                |
                | Request B -> gets the lock
                | Request B -> gets 127
                |
                | Therefore two requests cannot receive the same number.
                |
                */

                $sequence = DocumentSequence::query()
                    ->where(
                        'sequence_type',
                        $sequenceType
                    )
                    ->where(
                        'year',
                        $year
                    )
                    ->lockForUpdate()
                    ->first();

                if (! $sequence) {
                    throw new RuntimeException(
                        sprintf(
                            'Unable to initialize document sequence [%s] for year [%d].',
                            $sequenceType,
                            $year
                        )
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Calculate Next Value
                |--------------------------------------------------------------------------
                */

                $nextValue =
                    $sequence->current_value + 1;

                /*
                |--------------------------------------------------------------------------
                | Persist New Sequence Value
                |--------------------------------------------------------------------------
                */

                $sequence->update([
                    'current_value' => $nextValue,
                ]);

                /*
                |--------------------------------------------------------------------------
                | Generate Document Number
                |--------------------------------------------------------------------------
                |
                | Format:
                |
                | PREFIX-YEAR-SEQUENCE
                |
                | Example:
                |
                | ASM-2019-000001
                |
                */

                return sprintf(
                    '%s-%d-%06d',
                    $prefix,
                    $year,
                    $nextValue
                );
            }
        );
    }

    /**
     * Resolve the year according to the selected calendar.
     *
     * Supported calendars:
     *
     * Ethiopian:
     * - ethiopian
     * - et
     * - am
     *
     * Gregorian:
     * - gregorian
     * - ge
     * - en
     */
    protected function getYear(
        Carbon $date,
        string $calendar
    ): int {
        return match (
            strtolower(trim($calendar))
        ) {
            'ethiopian',
            'et',
            'am' => $this->getEthiopianYear($date),

            'gregorian',
            'ge',
            'en' => $date->year,

            default => throw new InvalidArgumentException(
                sprintf(
                    'Unsupported calendar [%s]. Supported calendars are Ethiopian and Gregorian.',
                    $calendar
                )
            ),
        };
    }

    /**
     * Get the Ethiopian calendar year.
     *
     * Uses andegna/calender.
     *
     * Example:
     *
     * Gregorian:
     * 2026-09-24
     *
     * Ethiopian:
     * 2019-01-14
     *
     * Returns:
     * 2019
     */
    protected function getEthiopianYear(
        Carbon $date
    ): int {
        /*
        |--------------------------------------------------------------------------
        | Convert Carbon Date To Native DateTime
        |--------------------------------------------------------------------------
        |
        | Andegna's DateTimeFactory works with PHP DateTime objects.
        |
        */

        $gregorianDate = $date->toDateTime();

        /*
        |--------------------------------------------------------------------------
        | Convert Gregorian Date To Ethiopian Date
        |--------------------------------------------------------------------------
        |
        | andegna/calender provides:
        |
        | DateTimeFactory::fromDateTime()
        |
        */

        $ethiopianDate =
            DateTimeFactory::fromDateTime(
                $gregorianDate
            );

        /*
        |--------------------------------------------------------------------------
        | Return Ethiopian Year
        |--------------------------------------------------------------------------
        */

        return $ethiopianDate->getYear();
    }
}
