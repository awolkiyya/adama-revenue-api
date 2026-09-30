<?php

namespace App\Services;

use Andegna\DateTimeFactory;
use App\Models\Assessment;
use App\Models\DocumentSequence;
use App\Models\Invoice;
use App\Models\Payment;
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
     * Sequence is maintained independently by:
     *
     * sequence_type + year
     *
     * Existing documents are used to synchronize the sequence
     * when the persisted sequence is behind the existing data.
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
        | Generate Inside Transaction
        |--------------------------------------------------------------------------
        |
        | The sequence row is locked before calculating the next value.
        |
        | This prevents concurrent requests from receiving the same
        | document number.
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
                | 1. Initialize Sequence Row
                |--------------------------------------------------------------------------
                |
                | A unique database constraint must exist on:
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
                | 2. Lock Sequence Row
                |--------------------------------------------------------------------------
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
                | 3. Validate Current Sequence Value
                |--------------------------------------------------------------------------
                */

                $currentValue =
                    (int) $sequence->current_value;

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

                if (
                    $currentValue >
                    self::MAX_SEQUENCE_VALUE
                ) {
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
                | 4. Synchronize Existing Documents
                |--------------------------------------------------------------------------
                |
                | This is important when:
                |
                | - the sequence service is introduced into an existing system
                | - old documents already exist
                | - the sequence table was reset
                | - data was imported
                |
                | Example:
                |
                | payments:
                |
                | PAY-2019-000001
                | PAY-2019-000002
                | PAY-2019-000010
                |
                | sequence:
                |
                | payment / 2019 / 0
                |
                | Existing maximum = 10
                |
                | Current sequence becomes 10
                |
                | Next number = 11
                |
                */

                $existingMaximum =
                    $this->getExistingMaximumValue(
                        $sequenceType,
                        $prefix,
                        $year
                    );

                if (
                    $existingMaximum !== null
                    &&
                    $existingMaximum > $currentValue
                ) {
                    $currentValue =
                        $existingMaximum;

                    $sequence->update([
                        'current_value' =>
                            $currentValue,

                        'updated_at' =>
                            now(),
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | 5. Calculate Next Value
                |--------------------------------------------------------------------------
                */

                $nextValue =
                    $currentValue + 1;

                /*
                |--------------------------------------------------------------------------
                | 6. Prevent Overflow
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
                            $year
                        )
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | 7. Persist New Sequence Value
                |--------------------------------------------------------------------------
                */

                $sequence->update([
                    'current_value' =>
                        $nextValue,

                    'updated_at' =>
                        now(),
                ]);

                /*
                |--------------------------------------------------------------------------
                | 8. Generate Document Number
                |--------------------------------------------------------------------------
                */

                return sprintf(
                    '%s-%d-%0' .
                        self::SEQUENCE_DIGITS .
                        'd',
                    $prefix,
                    $year,
                    $nextValue
                );
            }
        );
    }

    /**
     * Get the highest existing document number for the sequence.
     */
    protected function getExistingMaximumValue(
        string $sequenceType,
        string $prefix,
        int $year
    ): ?int {
        return match ($sequenceType) {
            'assessment' =>
                $this->getExistingAssessmentMaximum(
                    $prefix,
                    $year
                ),

            'invoice' =>
                $this->getExistingInvoiceMaximum(
                    $prefix,
                    $year
                ),

            'payment' =>
                $this->getExistingPaymentMaximum(
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
     * ASM-2019-000011
     *
     * Returns:
     *
     * 11
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

        if ($assessmentNumber === null) {
            return null;
        }

        return $this->extractSequenceValue(
            $assessmentNumber,
            $documentPrefix,
            'assessment'
        );
    }

    /**
     * Get the highest existing invoice sequence.
     *
     * Example:
     *
     * INV-2019-000001
     * INV-2019-000002
     * INV-2019-000015
     *
     * Returns:
     *
     * 15
     */
    protected function getExistingInvoiceMaximum(
        string $prefix,
        int $year
    ): ?int {
        $documentPrefix =
            sprintf(
                '%s-%d-',
                $prefix,
                $year
            );

        $invoiceNumber =
            Invoice::query()
                ->where(
                    'invoice_number',
                    'like',
                    $documentPrefix . '%'
                )
                ->orderByDesc(
                    'invoice_number'
                )
                ->value(
                    'invoice_number'
                );

        if ($invoiceNumber === null) {
            return null;
        }

        return $this->extractSequenceValue(
            $invoiceNumber,
            $documentPrefix,
            'invoice'
        );
    }

    /**
     * Get the highest existing payment sequence.
     *
     * Example:
     *
     * PAY-2019-000001
     * PAY-2019-000002
     * PAY-2019-000015
     *
     * Returns:
     *
     * 15
     */
    protected function getExistingPaymentMaximum(
        string $prefix,
        int $year
    ): ?int {
        $documentPrefix =
            sprintf(
                '%s-%d-',
                $prefix,
                $year
            );

        $paymentNumber =
            Payment::query()
                ->where(
                    'payment_number',
                    'like',
                    $documentPrefix . '%'
                )
                ->orderByDesc(
                    'payment_number'
                )
                ->value(
                    'payment_number'
                );

        if ($paymentNumber === null) {
            return null;
        }

        return $this->extractSequenceValue(
            $paymentNumber,
            $documentPrefix,
            'payment'
        );
    }

    /**
     * Extract the numeric sequence portion from a document number.
     *
     * Example:
     *
     * INV-2019-000015
     *
     * documentPrefix:
     *
     * INV-2019-
     *
     * result:
     *
     * 15
     */
    protected function extractSequenceValue(
        string $documentNumber,
        string $documentPrefix,
        string $documentType
    ): int {
        $sequencePart =
            substr(
                $documentNumber,
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
                    'Invalid %s number format [%s]. Expected format [%sNNNNNN].',
                    $documentType,
                    $documentNumber,
                    $documentPrefix
                )
            );
        }

        $value =
            (int) $sequencePart;

        if (
            $value < 1
            ||
            $value >
            self::MAX_SEQUENCE_VALUE
        ) {
            throw new RuntimeException(
                sprintf(
                    'Invalid %s sequence value [%d] in document number [%s].',
                    $documentType,
                    $value,
                    $documentNumber
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
            'am' =>
                $this->getEthiopianYear($date),

            'gregorian',
            'ge',
            'en' =>
                $date->year,

            default =>
                throw new InvalidArgumentException(
                    sprintf(
                        'Unsupported calendar [%s]. Supported calendars are Ethiopian and Gregorian.',
                        $calendar
                    )
                ),
        };
    }

    /**
     * Get the Ethiopian calendar year.
     */
    protected function getEthiopianYear(
        Carbon $date
    ): int {
        $gregorianDate =
            $date->toDateTime();

        $ethiopianDate =
            DateTimeFactory::fromDateTime(
                $gregorianDate
            );

        return $ethiopianDate->getYear();
    }
}