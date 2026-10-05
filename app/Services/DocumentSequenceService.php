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
     * Maximum supported sequence value.
     */
    protected const MAX_SEQUENCE_VALUE = 999999;

    /**
     * Official document prefixes.
     */
    protected const DOCUMENT_PREFIXES = [
        'assessment' => 'ASM',
        'invoice' => 'INV',
        'payment' => 'PAY',
        'receipt' => 'RCP',
    ];

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
     */
    public function generate(
        string $sequenceType,
        ?Carbon $date = null,
        string $calendar = 'ethiopian'
    ): string {
        $sequenceType = strtolower(trim($sequenceType));

        if ($sequenceType === '') {
            throw new InvalidArgumentException(
                'Sequence type cannot be empty.'
            );
        }

        if (! isset(self::DOCUMENT_PREFIXES[$sequenceType])) {
            throw new InvalidArgumentException(
                sprintf(
                    'Unsupported sequence type [%s]. Supported types are: %s.',
                    $sequenceType,
                    implode(', ', array_keys(self::DOCUMENT_PREFIXES))
                )
            );
        }

        $prefix = self::DOCUMENT_PREFIXES[$sequenceType];

        $date ??= now();

        $year = $this->getYear(
            $date,
            $calendar
        );

        return DB::transaction(
            function () use (
                $sequenceType,
                $prefix,
                $year
            ): string {
                /*
                 |--------------------------------------------------------------------------
                 | 1. Ensure sequence row exists
                 |--------------------------------------------------------------------------
                 */

                DocumentSequence::query()->insertOrIgnore([
                    'sequence_type' => $sequenceType,
                    'year' => $year,
                    'current_value' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                /*
                 |--------------------------------------------------------------------------
                 | 2. Lock sequence row
                 |--------------------------------------------------------------------------
                 */

                $sequence = DocumentSequence::query()
                    ->where('sequence_type', $sequenceType)
                    ->where('year', $year)
                    ->lockForUpdate()
                    ->first();

                if (! $sequence) {
                    throw new RuntimeException(
                        sprintf(
                            'Unable to initialize document sequence [%s/%d].',
                            $sequenceType,
                            $year
                        )
                    );
                }

                /*
                 |--------------------------------------------------------------------------
                 | 3. Validate current value
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

                if ($currentValue >= self::MAX_SEQUENCE_VALUE) {
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
                 | 4. Synchronize with existing documents
                 |--------------------------------------------------------------------------
                 */

                $existingMaximum = $this->getExistingMaximumValue(
                    $sequenceType,
                    $prefix,
                    $year
                );

                if (
                    $existingMaximum !== null &&
                    $existingMaximum > $currentValue
                ) {
                    $currentValue = $existingMaximum;

                    $sequence->forceFill([
                        'current_value' => $currentValue,
                    ])->save();
                }

                /*
                 |--------------------------------------------------------------------------
                 | 5. Generate next value
                 |--------------------------------------------------------------------------
                 */

                $nextValue = $currentValue + 1;

                if ($nextValue > self::MAX_SEQUENCE_VALUE) {
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
                 | 6. Persist sequence
                 |--------------------------------------------------------------------------
                 */

                $sequence->forceFill([
                    'current_value' => $nextValue,
                ])->save();

                /*
                 |--------------------------------------------------------------------------
                 | 7. Generate document number
                 |--------------------------------------------------------------------------
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
     * Get the highest existing document number.
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

            'invoice' => $this->getExistingInvoiceMaximum(
                $prefix,
                $year
            ),

            'payment' => $this->getExistingPaymentMaximum(
                $prefix,
                $year
            ),

            /*
             * Add this once the Receipt model/table exists.
             */
            'receipt' => $this->getExistingReceiptMaximum(
                $prefix,
                $year
            ),

            default => null,
        };
    }

    protected function getExistingAssessmentMaximum(
        string $prefix,
        int $year
    ): ?int {
        $documentPrefix = sprintf(
            '%s-%d-',
            $prefix,
            $year
        );

        $number = Assessment::query()
            ->where(
                'assessment_number',
                'like',
                $documentPrefix . '%'
            )
            ->orderByDesc('assessment_number')
            ->value('assessment_number');

        return $number !== null
            ? $this->extractSequenceValue(
                $number,
                $documentPrefix,
                'assessment'
            )
            : null;
    }

    protected function getExistingInvoiceMaximum(
        string $prefix,
        int $year
    ): ?int {
        $documentPrefix = sprintf(
            '%s-%d-',
            $prefix,
            $year
        );

        $number = Invoice::query()
            ->where(
                'invoice_number',
                'like',
                $documentPrefix . '%'
            )
            ->orderByDesc('invoice_number')
            ->value('invoice_number');

        return $number !== null
            ? $this->extractSequenceValue(
                $number,
                $documentPrefix,
                'invoice'
            )
            : null;
    }

    protected function getExistingPaymentMaximum(
        string $prefix,
        int $year
    ): ?int {
        $documentPrefix = sprintf(
            '%s-%d-',
            $prefix,
            $year
        );

        $number = Payment::query()
            ->where(
                'payment_number',
                'like',
                $documentPrefix . '%'
            )
            ->orderByDesc('payment_number')
            ->value('payment_number');

        return $number !== null
            ? $this->extractSequenceValue(
                $number,
                $documentPrefix,
                'payment'
            )
            : null;
    }

    /**
     * Get the highest existing receipt number.
     *
     * Enable after the Receipt model is implemented.
     */
    protected function getExistingReceiptMaximum(
        string $prefix,
        int $year
    ): ?int {
        /*
         * Example when Receipt model exists:
         *
         * $documentPrefix = sprintf('%s-%d-', $prefix, $year);
         *
         * $number = Receipt::query()
         *     ->where('receipt_number', 'like', $documentPrefix . '%')
         *     ->orderByDesc('receipt_number')
         *     ->value('receipt_number');
         *
         * return $number !== null
         *     ? $this->extractSequenceValue(
         *         $number,
         *         $documentPrefix,
         *         'receipt'
         *     )
         *     : null;
         */

        return null;
    }

    /**
     * Extract numeric sequence from a document number.
     */
    protected function extractSequenceValue(
        string $documentNumber,
        string $documentPrefix,
        string $documentType
    ): int {
        $sequencePart = substr(
            $documentNumber,
            strlen($documentPrefix)
        );

        if (! preg_match('/^\d{1,6}$/', $sequencePart)) {
            throw new RuntimeException(
                sprintf(
                    'Invalid %s number format [%s]. Expected format [%sNNNNNN].',
                    $documentType,
                    $documentNumber,
                    $documentPrefix
                )
            );
        }

        $value = (int) $sequencePart;

        if (
            $value < 1 ||
            $value > self::MAX_SEQUENCE_VALUE
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
     * Resolve calendar year.
     */
    protected function getYear(
        Carbon $date,
        string $calendar
    ): int {
        return match (strtolower(trim($calendar))) {
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
     * Get Ethiopian calendar year.
     */
    protected function getEthiopianYear(
        Carbon $date
    ): int {
        $ethiopianDate = DateTimeFactory::fromDateTime(
            $date->toDateTime()
        );

        return $ethiopianDate->getYear();
    }
}