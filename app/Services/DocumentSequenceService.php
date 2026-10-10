<?php

namespace App\Services;

use Andegna\DateTimeFactory;
use App\Models\Assessment;
use App\Models\DocumentSequence;
use App\Models\Invoice;
use App\Models\LeaseAmendment;
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class DocumentSequenceService
{
    protected const SEQUENCE_DIGITS = 6;

    protected const MAX_SEQUENCE_VALUE = 999999;

    protected const DOCUMENT_PREFIXES = [
        'assessment' => 'ASM',
        'invoice' => 'INV',
        'payment' => 'PAY',
        'receipt' => 'RCP',
        'lease_amendment' => 'LAM',
    ];

    /**
     * Generate the next document number.
     *
     * The sequence is independent for each sequence type and year.
     *
     * Examples:
     * ASM-2019-000001
     * INV-2019-000001
     * PAY-2019-000001
     * LAM-2019-000001
     *
     * Ethiopian calendar is used by default.
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

        if (!isset(self::DOCUMENT_PREFIXES[$sequenceType])) {
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

        $year = $this->getYear($date, $calendar);

        return DB::transaction(function () use (
            $sequenceType,
            $prefix,
            $year
        ): string {
            /*
             * 1. Initialize the sequence row if it does not exist.
             *
             * Requires a unique constraint on (sequence_type, year).
             */
            DocumentSequence::query()->insertOrIgnore([
                'sequence_type' => $sequenceType,
                'year' => $year,
                'current_value' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            /*
             * 2. Lock the sequence row to prevent concurrent
             * requests from allocating the same number.
             */
            $sequence = DocumentSequence::query()
                ->where('sequence_type', $sequenceType)
                ->where('year', $year)
                ->lockForUpdate()
                ->first();

            if (!$sequence) {
                throw new RuntimeException(
                    sprintf(
                        'Unable to initialize document sequence [%s/%d].',
                        $sequenceType,
                        $year
                    )
                );
            }

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

            /*
             * 3. Synchronize with existing document numbers.
             *
             * This supports installations that already have
             * assessments, invoices, payments, or amendments.
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
             * 4. Generate the next number.
             */
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

            $nextValue = $currentValue + 1;

            $sequence->forceFill([
                'current_value' => $nextValue,
            ])->save();

            return sprintf(
                '%s-%d-%0' . self::SEQUENCE_DIGITS . 'd',
                $prefix,
                $year,
                $nextValue
            );
        });
    }

    /**
     * Get the highest existing number for the selected document type.
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

            'lease_amendment' => $this->getExistingLeaseAmendmentMaximum(
                $prefix,
                $year
            ),

            // Implement when a Receipt model/table is available.
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
        $documentPrefix = sprintf('%s-%d-', $prefix, $year);

        $number = Assessment::query()
            ->where('assessment_number', 'like', $documentPrefix . '%')
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
        $documentPrefix = sprintf('%s-%d-', $prefix, $year);

        $number = Invoice::query()
            ->where('invoice_number', 'like', $documentPrefix . '%')
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
        $documentPrefix = sprintf('%s-%d-', $prefix, $year);

        $number = Payment::query()
            ->where('payment_number', 'like', $documentPrefix . '%')
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
     * Get the highest existing lease amendment number.
     */
    protected function getExistingLeaseAmendmentMaximum(
        string $prefix,
        int $year
    ): ?int {
        $documentPrefix = sprintf('%s-%d-', $prefix, $year);

        $number = LeaseAmendment::query()
            ->where('amendment_number', 'like', $documentPrefix . '%')
            ->orderByDesc('amendment_number')
            ->value('amendment_number');

        return $number !== null
            ? $this->extractSequenceValue(
                $number,
                $documentPrefix,
                'lease amendment'
            )
            : null;
    }

    /**
     * Receipt sequence synchronization can be implemented
     * when the Receipt model and table are available.
     */
    protected function getExistingReceiptMaximum(
        string $prefix,
        int $year
    ): ?int {
        return null;
    }

    /**
     * Extract and validate the numeric sequence.
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

        if (!preg_match('/^\d{1,6}$/', $sequencePart)) {
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
     * Resolve the calendar year.
     */
    protected function getYear(
        Carbon $date,
        string $calendar
    ): int {
        return match (strtolower(trim($calendar))) {
            'ethiopian', 'et', 'am' => $this->getEthiopianYear($date),
            'gregorian', 'ge', 'en' => $date->year,

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