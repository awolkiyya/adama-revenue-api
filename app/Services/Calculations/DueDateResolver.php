<?php

namespace App\Services\Calculations;

use App\Models\AssessmentService;
use App\Models\PenaltyRule;
use App\Models\RevenueSetting;
use Carbon\Carbon;
use DateTimeZone;
use InvalidArgumentException;
use IntlCalendar;
use IntlGregorianCalendar;

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
    | Annual Payment Date Format
    |--------------------------------------------------------------------------
    |
    | revenue_settings.annual_payment_due_date is stored as:
    |
    |     MM-DD
    |
    | Example:
    |
    |     06-13
    |
    | The value represents an Ethiopian-calendar month/day.
    |
    */

    private const ANNUAL_PAYMENT_DATE_FORMAT = 'm-d';

    /*
    |--------------------------------------------------------------------------
    | Resolve Due Date
    |--------------------------------------------------------------------------
    |
    | This resolver does NOT select the penalty rule.
    |
    | The applicable PenaltyRule must already be resolved by the
    | financial rule-resolution layer and passed into this class.
    |
    | Flow:
    |
    |     Selected PenaltyRule
    |            ↓
    |     DueDateResolver
    |            ↓
    |     Actual Due Date
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

        if (!$penaltyRule->exists) {
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
    | AGREEMENT_DATE
    |--------------------------------------------------------------------------
    |
    | Used by services such as LIZZ where the obligation's due date
    | is based on the agreement date.
    |
    | The agreement date is stored in:
    |
    |     assessment_service_values
    |
    | Example:
    |
    |     field_code = AGREEMENT_DATE
    |     value      = "2024-08-01"
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
    | Get Agreement Date
    |--------------------------------------------------------------------------
    |
    | Reads AGREEMENT_DATE from assessment_service_values.
    |
    */

    private function getAgreementDate(
        AssessmentService $assessmentService,
    ): Carbon {
        /*
        |--------------------------------------------------------------------------
        | Find Dynamic Field
        |--------------------------------------------------------------------------
        */

        $value = $assessmentService
            ->serviceValues()
            ->where(
                'field_code',
                self::AGREEMENT_DATE_FIELD,
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
        |
        | Depending on the model cast, the value may arrive as:
        |
        |     "2024-08-01"
        |
        | or:
        |
        |     ["2024-08-01"]
        |
        | or:
        |
        |     ["value" => "2024-08-01"]
        |
        */

        if (is_array($value)) {
            $value =
                $value['value']
                ?? $value['date']
                ?? $value[0]
                ?? null;
        }

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
                $value,
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
    | FIXED_PAYMENT_DATE
    |--------------------------------------------------------------------------
    |
    | Uses the global recurring annual payment deadline:
    |
    |     revenue_settings.annual_payment_due_date
    |
    | Example:
    |
    |     "13-06"
    |
    | This value represents an Ethiopian-calendar month/day.
    |
    | IMPORTANT:
    |
    |     13-06 is NOT a Gregorian date.
    |
    | It is a recurring Ethiopian-calendar date:
    |
    |     2019 EC → 13/06/2019
    |     2020 EC → 13/06/2020
    |     2021 EC → 13/06/2021
    |
    | The Ethiopian year is determined from the assessment date.
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
            ->where('is_active', true)
            ->first();

        if (!$settings) {
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
        | Validate MM-DD Format
        |--------------------------------------------------------------------------
        |
        | Ethiopian calendar:
        |
        |     Months 1-12 → maximum 30 days
        |     Month 13    → maximum 6 days
        |
        */

        if (
            !preg_match(
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
        | Extract Ethiopian Month And Day
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
        | Validate Pagume
        |--------------------------------------------------------------------------
        |
        | Ethiopian month 13 (Pagume) has:
        |
        |     5 days in a normal year
        |     6 days in a leap year
        |
        | We cannot fully validate day 6 until the Ethiopian year is known,
        | so this is checked after resolving the assessment's Ethiopian year.
        |
        */

        /*
        |--------------------------------------------------------------------------
        | Resolve Assessment
        |--------------------------------------------------------------------------
        */

        $assessment = $assessmentService->assessment;

        if (!$assessment) {
            throw new InvalidArgumentException(
                sprintf(
                    'Assessment is missing for assessment service [%s].',
                    $assessmentService->id,
                )
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Assessment Date
        |--------------------------------------------------------------------------
        */

        $assessmentDate = $assessment->assessment_date
            ?? $assessment->created_at;

        if (!$assessmentDate) {
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
            assessmentService: $assessmentService,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Resolve Ethiopian Annual Payment Date
    |--------------------------------------------------------------------------
    |
    | Converts:
    |
    |     Gregorian assessment date
    |              ↓
    |     Ethiopian assessment year
    |              ↓
    |     Ethiopian year + configured month/day
    |              ↓
    |     Gregorian due date
    |
    | Example:
    |
    |     Assessment:
    |         2026-09-12 Gregorian
    |
    |     Ethiopian:
    |         2019 EC
    |
    |     Configuration:
    |         06-13
    |
    |     Target:
    |         13/06/2019 EC
    |
    |     Result:
    |         Gregorian equivalent of 13/06/2019 EC
    |
    */

    private function resolveEthiopianAnnualPaymentDate(
        Carbon $assessmentDate,
        int $month,
        int $day,
        AssessmentService $assessmentService,
    ): Carbon {
        /*
        |--------------------------------------------------------------------------
        | Verify PHP Intl Extension
        |--------------------------------------------------------------------------
        */

        if (!class_exists(IntlCalendar::class)) {
            throw new InvalidArgumentException(
                sprintf(
                    'PHP Intl extension is required to resolve Ethiopian annual payment date [%02d-%02d] for assessment service [%s]. Enable ext-intl.',
                    $month,
                    $day,
                    $assessmentService->id,
                )
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Convert Assessment Gregorian Date → Ethiopian Date
        |--------------------------------------------------------------------------
        */

        $assessmentTimezone = new DateTimeZone(
            config('app.timezone', 'Africa/Addis_Ababa'),
        );

        $ethiopianCalendar = IntlCalendar::createInstance(
            $assessmentTimezone,
            'en_US@calendar=ethiopic',
        );

        if (!$ethiopianCalendar) {
            throw new InvalidArgumentException(
                sprintf(
                    'Unable to initialize Ethiopian calendar for assessment service [%s].',
                    $assessmentService->id,
                )
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Set Assessment Date
        |--------------------------------------------------------------------------
        */

        $ethiopianCalendar->setTime(
            $assessmentDate
                ->copy()
                ->setTimezone($assessmentTimezone)
                ->getTimestamp() * 1000,
        );

        /*
        |--------------------------------------------------------------------------
        | Read Ethiopian Assessment Year
        |--------------------------------------------------------------------------
        */

        $ethiopianYear = $ethiopianCalendar->get(
            IntlCalendar::FIELD_YEAR,
        );

        if ($ethiopianYear <= 0) {
            throw new InvalidArgumentException(
                sprintf(
                    'Unable to determine Ethiopian year for assessment service [%s].',
                    $assessmentService->id,
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
                    'Invalid Ethiopian month [%d] for assessment service [%s].',
                    $month,
                    $assessmentService->id,
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
                    'Invalid Ethiopian day [%d] for assessment service [%s].',
                    $day,
                    $assessmentService->id,
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
        | Create Target Ethiopian Date
        |--------------------------------------------------------------------------
        */

        $targetEthiopianCalendar = IntlCalendar::createInstance(
            $assessmentTimezone,
            'en_US@calendar=ethiopic',
        );

        if (!$targetEthiopianCalendar) {
            throw new InvalidArgumentException(
                sprintf(
                    'Unable to initialize Ethiopian target calendar for assessment service [%s].',
                    $assessmentService->id,
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

        /*
        |--------------------------------------------------------------------------
        | Ensure ICU Did Not Normalize An Invalid Date
        |--------------------------------------------------------------------------
        |
        | This is particularly important for:
        |
        |     Pagume day 6
        |
        | in a non-leap Ethiopian year.
        |
        */

        if (
            $actualYear !== $ethiopianYear ||
            $actualMonth !== $ethiopianMonth ||
            $actualDay !== $day
        ) {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid Ethiopian annual payment date [%02d-%02d] for Ethiopian year [%d] and assessment service [%s].',
                    $month,
                    $day,
                    $ethiopianYear,
                    $assessmentService->id,
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
                    'Unable to convert Ethiopian annual payment date [%02d-%02d/%d] to Gregorian date for assessment service [%s].',
                    $month,
                    $day,
                    $ethiopianYear,
                    $assessmentService->id,
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