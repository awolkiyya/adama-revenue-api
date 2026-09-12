<?php

namespace App\Services\Financial;

use App\Models\AssessmentService;
use App\Models\PenaltyRule;
use App\Models\RevenueSetting;
use Carbon\Carbon;
use InvalidArgumentException;

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
    |     03-30
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
    | No penalty-rule offset is applied here because penalty_rules
    | do not contain due-date offset fields.
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
        |
        | AGREEMENT_DATE is expected to be stored as an actual date value
        | such as:
        |
        |     2024-08-01
        |
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
    |     "03-30"
    |
    | This value represents an Ethiopian-calendar month/day.
    |
    | The actual Gregorian date must be resolved for the Ethiopian
    | year associated with the assessment.
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

        $annualPaymentDate =
            $settings->annual_payment_due_date;

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

        /*
        |--------------------------------------------------------------------------
        | Validate MM-DD Format
        |--------------------------------------------------------------------------
        */

        if (
            !preg_match(
                '/^(0[1-9]|1[0-3])-(0[1-9]|[12][0-9]|30)$/',
                (string) $annualPaymentDate,
            )
        ) {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid annual payment due date [%s]. Expected Ethiopian MM-DD format.',
                    (string) $annualPaymentDate,
                )
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Validate Ethiopian Month/Day Combination
        |--------------------------------------------------------------------------
        */

        [$month, $day] = array_map(
            'intval',
            explode(
                '-',
                $annualPaymentDate,
            ),
        );

        if (
            $month === 13 &&
            $day > 6
        ) {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid Ethiopian annual payment due date [%s]. Pagume supports days 1-6.',
                    (string) $annualPaymentDate,
                )
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Resolve Relevant Ethiopian Year
        |--------------------------------------------------------------------------
        |
        | The assessment date determines which Ethiopian year the recurring
        | payment date belongs to.
        |
        | The actual conversion from Ethiopian calendar to Gregorian calendar
        | should be performed by the application's Ethiopian calendar service.
        |
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

        /*
        |--------------------------------------------------------------------------
        | IMPORTANT
        |--------------------------------------------------------------------------
        |
        | At this point the application must convert:
        |
        |     Ethiopian year + month + day
        |
        | into:
        |
        |     Gregorian Carbon date
        |
        | Do NOT use:
        |
        |     Carbon::createFromDate()
        |
        | because Carbon uses the Gregorian calendar.
        |
        | Replace the method below with the existing Ethiopian calendar
        | service used by the application.
        |
        */

        return $this->resolveEthiopianAnnualPaymentDate(
            assessmentDate: Carbon::parse($assessmentDate)->startOfDay(),
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
    | This method intentionally isolates calendar conversion from
    | financial-rule resolution.
    |
    | The implementation should delegate to the application's
    | Ethiopian calendar service.
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
        | TODO: Replace With Existing Ethiopian Calendar Service
        |--------------------------------------------------------------------------
        |
        | The application should have one authoritative Ethiopian calendar
        | conversion service.
        |
        | Do not implement a second calendar algorithm here.
        |
        */

        throw new InvalidArgumentException(
            sprintf(
                'Ethiopian calendar conversion is required to resolve annual payment date [%02d-%02d] for assessment service [%s]. Configure the application Ethiopian calendar service.',
                $month,
                $day,
                $assessmentService->id,
            )
        );
    }
}
