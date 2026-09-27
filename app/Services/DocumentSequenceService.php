<?php

namespace App\Services;

use Andegna\DateTimeFactory;
use App\Models\Assessment;
use App\Models\DocumentSequence;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class DocumentSequenceService
{
    /**
     * Number of digits used for the sequence portion.
     */
    protected const SEQUENCE_DIGITS = 6;

    /**
     * Maximum sequence value.
     */
    protected const MAX_SEQUENCE_VALUE = 999999;

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
     * The sequence is maintained independently for:
     *
     * sequence_type + year
     *
     * Example:
     *
     * assessment / 2019
     * invoice    / 2019
     * payment    / 2019
     *
     * The sequence is automatically synchronized with existing
     * assessment numbers when an existing sequence is detected
     * behind the current database data.
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

        $sequenceType = strtolower(trim($sequenceType));
        $prefix = strtoupper(trim($prefix));

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
        | Validate Prefix Characters
        |--------------------------------------------------------------------------
        |
        | Document numbers use:
        |
        | PREFIX-YEAR-SEQUENCE
        |
        | Restrict the prefix to predictable characters.
        |
        */

        if (! preg_match('/^[A-Z0-9_]+$/', $prefix)) {
            throw new InvalidArgumentException(
                'Sequence prefix may contain only uppercase letters, numbers, and underscores.'
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
        | This transaction is intentionally kept here.
        |
        | If this method is already called from another DB transaction,
        | Laravel will participate in the existing transaction.
        |
        | Therefore:
        |
        | successful document creation
        |        -> sequence increment committed
        |
        | failed document creation
        |        -> sequence increment rolled back
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
                | Initialize Sequence Row
                |--------------------------------------------------------------------------
                |
                | The database must have a unique constraint on:
                |
                | sequence_type + year
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
                | This prevents concurrent requests from receiving
                | the same sequence number.
                |
                | Request A:
                |     locks assessment/2019
                |
                | Request B:
                |     waits
                |
                | Request A:
                |     gets 11
                |
                | Request A:
                |     commits
                |
                | Request B:
                |     gets the lock
                |     gets 12
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
                | Validate Current Sequence Value
                |--------------------------------------------------------------------------
                */

                $currentValue = (int) $sequence->current_value;

                if ($currentValue < 0) {
                    throw new RuntimeException(
                        sprintf(
                            'Invalid current sequence value [%d] for [%s/%d].',
                            $currentValue,
                            $sequenceType,
                            $year
                        )
                    );
                }

                if ($currentValue > self::MAX_SEQUENCE_VALUE) {
                    throw new RuntimeException(
                        sprintf(
                            'Sequence value [%d] exceeds the maximum supported value of %d for [%s/%d].',
                            $currentValue,
                            self::MAX_SEQUENCE_VALUE,
                            $sequenceType,
                            $year
                        )
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Synchronize Existing Data
                |--------------------------------------------------------------------------
                |
                | This is important when DocumentSequenceService is introduced
                | into an existing system.
                |
                | Example:
                |
                | assessments:
                |
                | ASM-2019-000001
                | ASM-2019-000002
                | ...
                | ASM-2019-000010
                |
                | document_sequences:
                |
                | assessment / 2019 / 0
                |
                | Without synchronization the service would generate:
                |
                | ASM-2019-000001
                |
                | which would violate the unique constraint.
                |
                | We therefore synchronize the sequence with existing
                | assessment numbers before generating the next value.
                |
                */

                $existingMaximum =
                    $this->getExistingMaximumValue(
                        $sequenceType,
                        $prefix,
                        $year
                    );

                if (
                    $existingMaximum !== null &&
                    $existingMaximum > $currentValue
                ) {
                    $currentValue =
                        $existingMaximum;

                    $sequence->update([
                        'current_value' => $currentValue,
                        'updated_at' => now(),
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Calculate Next Value
                |--------------------------------------------------------------------------
                */

                $nextValue =
                    $currentValue + 1;

                /*
                |--------------------------------------------------------------------------
                | Prevent Sequence Overflow
                |--------------------------------------------------------------------------
                */

                if (
                    $nextValue >
                    self::MAX_SEQUENCE_VALUE
                ) {
                    throw new RuntimeException(
                        sprintf(
                            'Document sequence for [%s/%d] has reached the maximum value of %d.',
                            $sequenceType,
                            $year,
                            self::MAX_SEQUENCE_VALUE
                        )
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Persist New Sequence Value
                |--------------------------------------------------------------------------
                */

                $sequence->update([
                    'current_value' => $nextValue,
                    'updated_at' => now(),
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
                | ASM-2019-000011
                |
                */

                return sprintf(
                    '%s-%d-%0' . self::SEQUENCE_DIGITS . 'd',
                    $prefix,
                    $year,
                    $nextValue
                );
            }
        );
    }

    /**
     * Get the highest existing document number for the sequence.
     *
     * This protects the application when DocumentSequenceService
     * is introduced into a database that already contains documents.
     *
     * Currently supported existing sequence source:
     *
     * assessment -> assessments.assessment_number
     *
     * Returns null when no existing document is found.
     */
    protected function getExistingMaximumValue(
        string $sequenceType,
        string $prefix,
        int $year
    ): ?int {
        return match ($sequenceType) {
            'assessment' => $this->getExistingAssessmentMaximum(
                $prefix,
                $year
            ),

            default => null,
        };
    }

    /**
     * Get the highest existing assessment sequence.
     *
     * Example:
     *
     * ASM-2019-000001
     * ASM-2019-000002
     * ASM-2019-000010
     *
     * Returns:
     *
     * 10
     */
    protected function getExistingAssessmentMaximum(
        string $prefix,
        int $year
    ): ?int {
        $documentPrefix =
            sprintf(
                '%s-%d-',
                $prefix,
                $year
            );

        /*
        |--------------------------------------------------------------------------
        | Get Latest Existing Assessment
        |--------------------------------------------------------------------------
        |
        | The sequence portion always has a fixed six-digit format.
        |
        | Therefore descending lexical order correctly identifies
        | the highest existing sequence number.
        |
        */

        $assessmentNumber =
            Assessment::query()
                ->where(
                    'assessment_number',
                    'like',
                    $documentPrefix . '%'
                )
                ->orderByDesc(
                    'assessment_number'
                )
                ->value(
                    'assessment_number'
                );

        if (
            $assessmentNumber === null
        ) {
            return null;
        }

        /*
        |--------------------------------------------------------------------------
        | Extract Sequence
        |--------------------------------------------------------------------------
        |
        | Example:
        |
        | ASM-2019-000011
        |
        | Prefix:
        | ASM-2019-
        |
        | Sequence:
        | 000011
        |
        */

        $sequencePart =
            substr(
                $assessmentNumber,
                strlen($documentPrefix)
            );

        if (
            ! preg_match(
                '/^\d{1,6}$/',
                $sequencePart
            )
        ) {
            throw new RuntimeException(
                sprintf(
                    'Invalid assessment number format [%s]. Expected format [%sNNNNNN].',
                    $assessmentNumber,
                    $documentPrefix
                )
            );
        }

        $value =
            (int) $sequencePart;

        if (
            $value < 1 ||
            $value > self::MAX_SEQUENCE_VALUE
        ) {
            throw new RuntimeException(
                sprintf(
                    'Invalid assessment sequence value [%d] in assessment number [%s].',
                    $value,
                    $assessmentNumber
                )
            );
        }

        return $value;
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
            'am' => $this->getEthiopianYear(
                $date
            ),

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
     */
    protected function getEthiopianYear(
        Carbon $date
    ): int {
        /*
        |--------------------------------------------------------------------------
        | Convert Carbon Date To Native DateTime
        |--------------------------------------------------------------------------
        */

        $gregorianDate =
            $date->toDateTime();

        /*
        |--------------------------------------------------------------------------
        | Convert Gregorian Date To Ethiopian Date
        |--------------------------------------------------------------------------
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