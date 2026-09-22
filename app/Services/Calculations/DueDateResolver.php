<?php

namespace App\Services\Calculations;

use App\Models\AssessmentService;
use App\Models\BaseField;
use App\Models\PenaltyRule;
use App\Models\RevenueSetting;
use Carbon\Carbon;
use DateTimeZone;
use InvalidArgumentException;
use IntlCalendar;

class DueDateResolver
{
    /*
    |--------------------------------------------------------------------------
    | Penalty Rule Start Types
    |--------------------------------------------------------------------------
    |
    | These values must match:
    |
    |     penalty_rules.start_type
    |
    */

    private const RULE_AGREEMENT_DATE = 'AGREEMENT_DATE';

    private const RULE_FIXED_PAYMENT_DATE = 'FIXED_PAYMENT_DATE';

    /*
    |--------------------------------------------------------------------------
    | Dynamic Agreement Date Field
    |--------------------------------------------------------------------------
    */

    private const AGREEMENT_DATE_FIELD = 'AGREEMENT_DATE';

    /*
    |--------------------------------------------------------------------------
    | Resolve Due Date For Assessment
    |--------------------------------------------------------------------------
    |
    | Existing assessment-based flow.
    |
    | The PenaltyRule must already have been resolved by TariffResolver.
    |
    */

    public function resolve(
        AssessmentService $assessmentService,
        PenaltyRule $penaltyRule,
    ): Carbon {
        /*
        |--------------------------------------------------------------------------
        | Validate Persisted Rule
        |--------------------------------------------------------------------------
        */

        if (! $penaltyRule->exists) {
            throw new InvalidArgumentException(
                sprintf(
                    'Penalty rule is not persisted for assessment service [%s].',
                    $assessmentService->id,
                )
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Resolve According To Start Type
        |--------------------------------------------------------------------------
        */

        return match ($penaltyRule->start_type) {
            self::RULE_AGREEMENT_DATE =>
                $this->resolveFromAgreementDate(
                    $assessmentService,
                ),

            self::RULE_FIXED_PAYMENT_DATE =>
                $this->resolveFromFixedPaymentDate(
                    $assessmentService,
                ),

            default =>
                throw new InvalidArgumentException(
                    sprintf(
                        'Unsupported penalty rule start type [%s] for assessment service [%s].',
                        $penaltyRule->start_type,
                        $assessmentService->id,
                    )
                ),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Resolve Due Date For Direct Collection
    |--------------------------------------------------------------------------
    |
    | Direct Collection does not have an AssessmentService.
    |
    | Therefore the resolver receives:
    |
    |     PenaltyRule
    |     collection date
    |     submitted dynamic field values
    |
    | Example:
    |
    |     RevenueService
    |           ↓
    |     submitted fields
    |           ↓
    |     Tariff calculation
    |           ↓
    |     PenaltyRule
    |           ↓
    |     DueDateResolver
    |
    | The collection date is used to determine the Ethiopian year for
    | FIXED_PAYMENT_DATE.
    |
    | For AGREEMENT_DATE, the agreement date is read from the supplied
    | dynamic field values.
    |
    */

    public function resolveForRevenueService(
        PenaltyRule $penaltyRule,
        Carbon $collectionDate,
        array $values = [],
    ): Carbon {
        /*
        |--------------------------------------------------------------------------
        | Validate Persisted Rule
        |--------------------------------------------------------------------------
        */

        if (! $penaltyRule->exists) {
            throw new InvalidArgumentException(
                'Penalty rule is not persisted for direct collection.',
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Normalize Collection Date
        |--------------------------------------------------------------------------
        */

        $collectionDate = $collectionDate
            ->copy()
            ->startOfDay();

        /*
        |--------------------------------------------------------------------------
        | Resolve According To Start Type
        |--------------------------------------------------------------------------
        */

        return match ($penaltyRule->start_type) {
            self::RULE_AGREEMENT_DATE =>
                $this->resolveDirectCollectionFromAgreementDate(
                    values: $values,
                ),

            self::RULE_FIXED_PAYMENT_DATE =>
                $this->resolveDirectCollectionFromFixedPaymentDate(
                    collectionDate: $collectionDate,
                ),

            default =>
                throw new InvalidArgumentException(
                    sprintf(
                        'Unsupported penalty rule start type [%s] for direct collection.',
                        $penaltyRule->start_type,
                    )
                ),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | AGREEMENT_DATE - ASSESSMENT
    |--------------------------------------------------------------------------
    |
    | Used by services such as LIZZ where the obligation's due date
    | is based on the agreement date.
    |
    */

    private function resolveFromAgreementDate(
        AssessmentService $assessmentService,
    ): Carbon {
        return $this->getAgreementDate(
            $assessmentService,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Get Agreement Date - Assessment
    |--------------------------------------------------------------------------
    |
    | Reads AGREEMENT_DATE from assessment_service_values.
    |
    | Canonical relationship:
    |
    | AssessmentService
    |       ↓
    | values()
    |       ↓
    | AssessmentServiceValue
    |       ↓
    | revenueServiceField
    |       ↓
    | baseField
    |       ↓
    | code = AGREEMENT_DATE
    |
    */

    private function getAgreementDate(
        AssessmentService $assessmentService,
    ): Carbon {
        /*
        |--------------------------------------------------------------------------
        | Find Dynamic Field Value
        |--------------------------------------------------------------------------
        */

        $value = $assessmentService
            ->values()
            ->whereHas(
                'revenueServiceField.baseField',
                function ($query): void {
                    $query->where(
                        'code',
                        self::AGREEMENT_DATE_FIELD,
                    );
                },
            )
            ->value('value');

        /*
        |--------------------------------------------------------------------------
        | Validate Presence
        |--------------------------------------------------------------------------
        */

        if (
            $value === null ||
            $value === ''
        ) {
            throw new InvalidArgumentException(
                sprintf(
                    'AGREEMENT_DATE is required for assessment service [%s].',
                    $assessmentService->id,
                )
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Handle JSON Value
        |--------------------------------------------------------------------------
        */

        $value = $this->extractDateValue(
            $value,
        );

        /*
        |--------------------------------------------------------------------------
        | Validate Extracted Value
        |--------------------------------------------------------------------------
        */

        if (
            $value === null ||
            $value === ''
        ) {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid AGREEMENT_DATE value for assessment service [%s].',
                    $assessmentService->id,
                )
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Parse Date
        |--------------------------------------------------------------------------
        */

        try {
            return Carbon::parse(
                (string) $value,
            )->startOfDay();

        } catch (\Throwable $e) {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid AGREEMENT_DATE [%s] for assessment service [%s].',
                    (string) $value,
                    $assessmentService->id,
                ),
                previous: $e,
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | AGREEMENT_DATE - DIRECT COLLECTION
    |--------------------------------------------------------------------------
    |
    | Direct Collection does not have assessment_service_values.
    |
    | The values are supplied by the DirectCollectionService.
    |
    | Supported value map examples:
    |
    |     [
    |         'uuid-of-base-field' => '2026-09-20',
    |     ]
    |
    | or:
    |
    |     [
    |         'AGREEMENT_DATE' => '2026-09-20',
    |     ]
    |
    */

    private function resolveDirectCollectionFromAgreementDate(
        array $values,
    ): Carbon {
        $value = $this->findAgreementDateInValues(
            $values,
        );

        if (
            $value === null ||
            $value === ''
        ) {
            throw new InvalidArgumentException(
                'AGREEMENT_DATE is required for direct collection.',
            );
        }

        $value = $this->extractDateValue(
            $value,
        );

        if (
            $value === null ||
            $value === ''
        ) {
            throw new InvalidArgumentException(
                'Invalid AGREEMENT_DATE value for direct collection.',
            );
        }

        try {
            return Carbon::parse(
                (string) $value,
            )->startOfDay();

        } catch (\Throwable $e) {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid AGREEMENT_DATE [%s] for direct collection.',
                    (string) $value,
                ),
                previous: $e,
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Find Agreement Date In Direct Collection Values
    |--------------------------------------------------------------------------
    |
    | First tries:
    |
    |     AGREEMENT_DATE
    |
    | Then resolves the BaseField UUID for:
    |
    |     AGREEMENT_DATE
    |
    | and checks the values map using that UUID.
    |
    */

    private function findAgreementDateInValues(
        array $values,
    ): mixed {
        /*
        |--------------------------------------------------------------------------
        | Try Field Code Directly
        |--------------------------------------------------------------------------
        */

        foreach ($values as $key => $value) {
            if (
                is_string($key) &&
                strtoupper(trim($key)) === self::AGREEMENT_DATE_FIELD
            ) {
                return $value;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Resolve BaseField By Code
        |--------------------------------------------------------------------------
        */

        $baseField = BaseField::query()
            ->where(
                'code',
                self::AGREEMENT_DATE_FIELD,
            )
            ->first();

        if (! $baseField) {
            return null;
        }

        /*
        |--------------------------------------------------------------------------
        | Try BaseField ID
        |--------------------------------------------------------------------------
        */

        $baseFieldId = (string) $baseField->id;

        if (array_key_exists($baseFieldId, $values)) {
            return $values[$baseFieldId];
        }

        /*
        |--------------------------------------------------------------------------
        | Try Original ID Type
        |--------------------------------------------------------------------------
        */

        if (array_key_exists($baseField->id, $values)) {
            return $values[$baseField->id];
        }

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | Extract Date Value
    |--------------------------------------------------------------------------
    |
    | Handles:
    |
    |     "2026-09-20"
    |
    |     ["2026-09-20"]
    |
    |     ["value" => "2026-09-20"]
    |
    |     ["date" => "2026-09-20"]
    |
    */

    private function extractDateValue(
        mixed $value,
    ): mixed {
        if (! is_array($value)) {
            return $value;
        }

        return $value['value']
            ?? $value['date']
            ?? $value[0]
            ?? null;
    }

    /*
    |--------------------------------------------------------------------------
    | FIXED_PAYMENT_DATE - ASSESSMENT
    |--------------------------------------------------------------------------
    |
    | Uses the global recurring annual payment deadline:
    |
    |     revenue_settings.annual_payment_due_date
    |
    | Example:
    |
    |     06-13
    |
    | This is an Ethiopian-calendar month/day.
    |
    */

    private function resolveFromFixedPaymentDate(
        AssessmentService $assessmentService,
    ): Carbon {
        /*
        |--------------------------------------------------------------------------
        | Resolve Active Revenue Settings
        |--------------------------------------------------------------------------
        */

        $settings = RevenueSetting::query()
            ->where(
                'is_active',
                true,
            )
            ->first();

        if (! $settings) {
            throw new InvalidArgumentException(
                sprintf(
                    'Active revenue settings are not configured for assessment service [%s].',
                    $assessmentService->id,
                )
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Resolve Configured Annual Payment Date
        |--------------------------------------------------------------------------
        */

        $annualPaymentDate = $settings->annual_payment_due_date;

        if (
            $annualPaymentDate === null ||
            trim((string) $annualPaymentDate) === ''
        ) {
            throw new InvalidArgumentException(
                sprintf(
                    'Annual payment due date is not configured in revenue settings for assessment service [%s].',
                    $assessmentService->id,
                )
            );
        }

        $annualPaymentDate = trim(
            (string) $annualPaymentDate,
        );

        /*
        |--------------------------------------------------------------------------
        | Parse Ethiopian MM-DD
        |--------------------------------------------------------------------------
        */

        [$month, $day] = $this->parseAnnualPaymentDate(
            $annualPaymentDate,
        );

        /*
        |--------------------------------------------------------------------------
        | Resolve Assessment
        |--------------------------------------------------------------------------
        */

        $assessment = $assessmentService->assessment;

        if (! $assessment) {
            throw new InvalidArgumentException(
                sprintf(
                    'Assessment is missing for assessment service [%s].',
                    $assessmentService->id,
                )
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Resolve Assessment Date
        |--------------------------------------------------------------------------
        */

        $assessmentDate = $assessment->assessment_date
            ?? $assessment->created_at;

        if (! $assessmentDate) {
            throw new InvalidArgumentException(
                sprintf(
                    'Assessment date is missing for assessment service [%s].',
                    $assessmentService->id,
                )
            );
        }

        $assessmentDate = Carbon::parse(
            $assessmentDate,
        )->startOfDay();

        /*
        |--------------------------------------------------------------------------
        | Resolve Ethiopian Annual Payment Date
        |--------------------------------------------------------------------------
        */

        return $this->resolveEthiopianAnnualPaymentDate(
            assessmentDate: $assessmentDate,
            month: $month,
            day: $day,
            context: sprintf(
                'assessment service [%s]',
                $assessmentService->id,
            ),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | FIXED_PAYMENT_DATE - DIRECT COLLECTION
    |--------------------------------------------------------------------------
    |
    | Direct Collection has no Assessment.
    |
    | Therefore:
    |
    |     collection date
    |            ↓
    |     Ethiopian collection year
    |            ↓
    |     configured MM-DD
    |            ↓
    |     Gregorian due date
    |
    */

    private function resolveDirectCollectionFromFixedPaymentDate(
        Carbon $collectionDate,
    ): Carbon {
        /*
        |--------------------------------------------------------------------------
        | Resolve Active Revenue Settings
        |--------------------------------------------------------------------------
        */

        $settings = RevenueSetting::query()
            ->where(
                'is_active',
                true,
            )
            ->first();

        if (! $settings) {
            throw new InvalidArgumentException(
                'Active revenue settings are not configured for direct collection.',
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Resolve Configured Annual Payment Date
        |--------------------------------------------------------------------------
        */

        $annualPaymentDate = $settings->annual_payment_due_date;

        if (
            $annualPaymentDate === null ||
            trim((string) $annualPaymentDate) === ''
        ) {
            throw new InvalidArgumentException(
                'Annual payment due date is not configured in revenue settings for direct collection.',
            );
        }

        $annualPaymentDate = trim(
            (string) $annualPaymentDate,
        );

        /*
        |--------------------------------------------------------------------------
        | Parse Ethiopian MM-DD
        |--------------------------------------------------------------------------
        */

        [$month, $day] = $this->parseAnnualPaymentDate(
            $annualPaymentDate,
        );

        /*
        |--------------------------------------------------------------------------
        | Resolve Ethiopian Annual Payment Date
        |--------------------------------------------------------------------------
        */

        return $this->resolveEthiopianAnnualPaymentDate(
            assessmentDate: $collectionDate,
            month: $month,
            day: $day,
            context: 'direct collection',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Parse Annual Payment Date
    |--------------------------------------------------------------------------
    |
    | Expected:
    |
    |     MM-DD
    |
    | Ethiopian calendar:
    |
    |     Months 1-12 → maximum 30 days
    |     Month 13    → maximum 6 days
    |
    */

    private function parseAnnualPaymentDate(
        string $annualPaymentDate,
    ): array {
        /*
        |--------------------------------------------------------------------------
        | Validate Format
        |--------------------------------------------------------------------------
        */

        if (
            ! preg_match(
                '/^(0[1-9]|1[0-3])-(0[1-9]|[12][0-9]|30)$/',
                $annualPaymentDate,
            )
        ) {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid annual payment due date [%s]. Expected Ethiopian MM-DD format.',
                    $annualPaymentDate,
                )
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Extract Month And Day
        |--------------------------------------------------------------------------
        */

        [$month, $day] = array_map(
            'intval',
            explode(
                '-',
                $annualPaymentDate,
            ),
        );

        /*
        |--------------------------------------------------------------------------
        | Explicit Month Validation
        |--------------------------------------------------------------------------
        */

        if (
            $month < 1 ||
            $month > 13
        ) {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid Ethiopian month [%d].',
                    $month,
                )
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Explicit Day Validation
        |--------------------------------------------------------------------------
        */

        if ($day < 1) {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid Ethiopian day [%d].',
                    $day,
                )
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Month 13 Can Only Have Up To 6 Days
        |--------------------------------------------------------------------------
        |
        | The leap-year-specific validation is performed after the
        | Ethiopian year has been determined by ICU.
        |
        */

        if (
            $month === 13 &&
            $day > 6
        ) {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid Ethiopian Pagume date [%02d-%02d]. Month 13 can contain at most 6 days.',
                    $month,
                    $day,
                )
            );
        }

        return [
            $month,
            $day,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Resolve Ethiopian Annual Payment Date
    |--------------------------------------------------------------------------
    |
    | Converts:
    |
    |     Gregorian reference date
    |              ↓
    |     Ethiopian reference year
    |              ↓
    |     Ethiopian year + configured month/day
    |              ↓
    |     Gregorian due date
    |
    | The same method is used by:
    |
    |     Assessment
    |     Direct Collection
    |
    */

    private function resolveEthiopianAnnualPaymentDate(
        Carbon $assessmentDate,
        int $month,
        int $day,
        string $context,
    ): Carbon {
        /*
        |--------------------------------------------------------------------------
        | Verify PHP Intl Extension
        |--------------------------------------------------------------------------
        */

        if (! class_exists(IntlCalendar::class)) {
            throw new InvalidArgumentException(
                sprintf(
                    'PHP Intl extension is required to resolve Ethiopian annual payment date [%02d-%02d] for %s. Enable ext-intl.',
                    $month,
                    $day,
                    $context,
                )
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Resolve Application Timezone
        |--------------------------------------------------------------------------
        */

        try {
            $assessmentTimezone = new DateTimeZone(
                config(
                    'app.timezone',
                    'Africa/Addis_Ababa',
                ),
            );
        } catch (\Throwable $e) {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid application timezone while resolving Ethiopian annual payment date for %s.',
                    $context,
                ),
                previous: $e,
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Normalize Reference Date
        |--------------------------------------------------------------------------
        */

        $assessmentDate = $assessmentDate
            ->copy()
            ->setTimezone($assessmentTimezone)
            ->startOfDay();

        /*
        |--------------------------------------------------------------------------
        | Create Ethiopian Calendar
        |--------------------------------------------------------------------------
        */

        $ethiopianCalendar = IntlCalendar::createInstance(
            $assessmentTimezone,
            'en_US@calendar=ethiopic',
        );

        if (! $ethiopianCalendar) {
            throw new InvalidArgumentException(
                sprintf(
                    'Unable to initialize Ethiopian calendar for %s.',
                    $context,
                )
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Set Reference Date
        |--------------------------------------------------------------------------
        */

        $ethiopianCalendar->setTime(
            $assessmentDate->getTimestamp() * 1000,
        );

        /*
        |--------------------------------------------------------------------------
        | Read Ethiopian Reference Year
        |--------------------------------------------------------------------------
        */

        $ethiopianYear = $ethiopianCalendar->get(
            IntlCalendar::FIELD_YEAR,
        );

        if ($ethiopianYear <= 0) {
            throw new InvalidArgumentException(
                sprintf(
                    'Unable to determine Ethiopian year for %s.',
                    $context,
                )
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Validate Ethiopian Month
        |--------------------------------------------------------------------------
        */

        if (
            $month < 1 ||
            $month > 13
        ) {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid Ethiopian month [%d] for %s.',
                    $month,
                    $context,
                )
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Validate Ethiopian Day
        |--------------------------------------------------------------------------
        */

        if ($day < 1) {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid Ethiopian day [%d] for %s.',
                    $day,
                    $context,
                )
            );
        }

        /*
        |--------------------------------------------------------------------------
        | ICU MONTH IS ZERO-BASED
        |--------------------------------------------------------------------------
        |
        | Ethiopian:
        |
        |     Month 1  → ICU month 0
        |     Month 2  → ICU month 1
        |     ...
        |     Month 13 → ICU month 12
        |
        */

        $ethiopianMonth = $month - 1;

        /*
        |--------------------------------------------------------------------------
        | Create Target Ethiopian Calendar
        |--------------------------------------------------------------------------
        */

        $targetEthiopianCalendar = IntlCalendar::createInstance(
            $assessmentTimezone,
            'en_US@calendar=ethiopic',
        );

        if (! $targetEthiopianCalendar) {
            throw new InvalidArgumentException(
                sprintf(
                    'Unable to initialize Ethiopian target calendar for %s.',
                    $context,
                )
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Clear Calendar Before Setting Exact Date
        |--------------------------------------------------------------------------
        */

        $targetEthiopianCalendar->clear();

        /*
        |--------------------------------------------------------------------------
        | Set Ethiopian Year / Month / Day
        |--------------------------------------------------------------------------
        */

        $targetEthiopianCalendar->set(
            IntlCalendar::FIELD_YEAR,
            $ethiopianYear,
        );

        $targetEthiopianCalendar->set(
            IntlCalendar::FIELD_MONTH,
            $ethiopianMonth,
        );

        $targetEthiopianCalendar->set(
            IntlCalendar::FIELD_DAY_OF_MONTH,
            $day,
        );

        $targetEthiopianCalendar->set(
            IntlCalendar::FIELD_HOUR_OF_DAY,
            0,
        );

        $targetEthiopianCalendar->set(
            IntlCalendar::FIELD_MINUTE,
            0,
        );

        $targetEthiopianCalendar->set(
            IntlCalendar::FIELD_SECOND,
            0,
        );

        $targetEthiopianCalendar->set(
            IntlCalendar::FIELD_MILLISECOND,
            0,
        );

        /*
        |--------------------------------------------------------------------------
        | Validate Calendar Date
        |--------------------------------------------------------------------------
        |
        | ICU may normalize invalid dates automatically.
        |
        | We compare the requested date with the actual calendar date
        | to detect invalid dates such as Pagume day 6 in a non-leap year.
        |
        */

        $actualYear = $targetEthiopianCalendar->get(
            IntlCalendar::FIELD_YEAR,
        );

        $actualMonth = $targetEthiopianCalendar->get(
            IntlCalendar::FIELD_MONTH,
        );

        $actualDay = $targetEthiopianCalendar->get(
            IntlCalendar::FIELD_DAY_OF_MONTH,
        );

        if (
            $actualYear !== $ethiopianYear ||
            $actualMonth !== $ethiopianMonth ||
            $actualDay !== $day
        ) {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid Ethiopian annual payment date [%02d-%02d] for Ethiopian year [%d] and %s.',
                    $month,
                    $day,
                    $ethiopianYear,
                    $context,
                )
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Convert Ethiopian Calendar → Gregorian Timestamp
        |--------------------------------------------------------------------------
        */

        $timestampMilliseconds = $targetEthiopianCalendar->getTime();

        if ($timestampMilliseconds === false) {
            throw new InvalidArgumentException(
                sprintf(
                    'Unable to convert Ethiopian annual payment date [%02d-%02d/%d] to Gregorian date for %s.',
                    $month,
                    $day,
                    $ethiopianYear,
                    $context,
                )
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Convert Timestamp → Carbon
        |--------------------------------------------------------------------------
        */

        $timestampSeconds = $timestampMilliseconds / 1000;

        $dueDate = Carbon::createFromTimestamp(
            $timestampSeconds,
            $assessmentTimezone,
        )->startOfDay();

        /*
        |--------------------------------------------------------------------------
        | Return Gregorian Due Date
        |--------------------------------------------------------------------------
        */

        return $dueDate;
    }
}