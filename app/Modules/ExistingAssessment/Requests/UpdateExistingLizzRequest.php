<?php

namespace App\Modules\ExistingAssessment\Requests;

use Carbon\Carbon;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

class UpdateExistingLizzRequest extends FormRequest
{
    private const MAX_FILE_SIZE_BYTES = 10 * 1024 * 1024;

    private const ALLOWED_FILE_EXTENSIONS = [
        'pdf',
        'doc',
        'docx',
        'jpg',
        'jpeg',
        'png',
    ];

    /**
     * AUTHORIZE
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * VALIDATION RULES
     */
    public function rules(): array
    {
        return [
            'taxpayer_id' => [
                'sometimes',
                'required',
                'uuid',
                'exists:citizens,id',
            ],

            'revenue_service_id' => [
                'sometimes',
                'required',
                'uuid',
                'exists:revenue_services,id',
            ],

            'service_fields' => [
                'sometimes',
                'required',
                'array',
            ],

            'source' => [
                'sometimes',
                'nullable',
                'string',
                'max:1000',
            ],

            'notes' => [
                'sometimes',
                'nullable',
                'string',
                'max:5000',
            ],

            'original_obligation' => [
                'sometimes',
                'required',
                'numeric',
                'min:0',
            ],

            'amount_already_paid' => [
                'sometimes',
                'required',
                'numeric',
                'min:0',

                function (
                    string $attribute,
                    mixed $value,
                    Closure $fail
                ): void {
                    $originalObligation = $this->input(
                        'original_obligation'
                    );

                    /*
                     * If the original obligation is omitted, the service
                     * must validate against the persisted obligation.
                     */
                    if ($originalObligation === null) {
                        return;
                    }

                    if (
                        is_numeric($value) &&
                        is_numeric($originalObligation) &&
                        (float) $value > (float) $originalObligation
                    ) {
                        $fail(
                            'The amount already paid cannot be greater than the original historical obligation.'
                        );
                    }
                },
            ],

            'balance_as_of_date' => [
                'sometimes',
                'required',
                'date',
            ],
        ];
    }

    /**
     * VALIDATE DYNAMIC FILE UPLOADS
     *
     * Multipart field name:
     * file__SERVICE_UUID__FIELD_UUID
     *
     * Accepted formats: PDF, DOC, DOCX, JPG, JPEG, PNG.
     * Maximum size: 10 MB per file.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ($this->allFiles() as $key => $file) {
                if (
                    ! is_string($key) ||
                    ! str_starts_with($key, 'file__')
                ) {
                    continue;
                }

                /*
                 * Validate multipart field naming convention.
                 */
                $parts = explode('__', $key);

                if (
                    count($parts) !== 3 ||
                    $parts[0] !== 'file' ||
                    ! Str::isUuid($parts[1]) ||
                    ! Str::isUuid($parts[2])
                ) {
                    $validator->errors()->add(
                        $key,
                        'The uploaded file field name is invalid.'
                    );

                    continue;
                }

                /*
                 * Validate UploadedFile instance.
                 */
                if (! $file instanceof UploadedFile) {
                    $validator->errors()->add(
                        $key,
                        'The uploaded value must be a file.'
                    );

                    continue;
                }

                /*
                 * Validate PHP upload status.
                 */
                if (! $file->isValid()) {
                    Log::warning(
                        'Existing LIZZ update received an invalid upload.',
                        [
                            'request_id' =>
                                $this->header('X-Request-ID'),
                            'field' => $key,
                            'original_filename' =>
                                $file->getClientOriginalName(),
                            'upload_error' => $file->getError(),
                            'upload_error_message' =>
                                $file->getErrorMessage(),
                        ]
                    );

                    $validator->errors()->add(
                        $key,
                        'The uploaded file is invalid or the upload was interrupted.'
                    );

                    continue;
                }

                /*
                 * Check size explicitly before MIME validation.
                 */
                $size = $file->getSize();

                if (
                    $size !== false &&
                    $size > self::MAX_FILE_SIZE_BYTES
                ) {
                    $validator->errors()->add(
                        $key,
                        'The uploaded file must not exceed 10 MB.'
                    );

                    continue;
                }

                /*
                 * Validate the detected file type using Laravel.
                 * Do not rely exclusively on the filename extension.
                 */
                $fileValidator = validator(
                    ['upload' => $file],
                    [
                        'upload' => [
                            'required',
                            'file',
                            'mimes:pdf,doc,docx,jpg,jpeg,png',
                        ],
                    ]
                );

                if ($fileValidator->fails()) {
                    Log::warning(
                        'Existing LIZZ update file validation failed.',
                        [
                            'request_id' =>
                                $this->header('X-Request-ID'),
                            'field' => $key,
                            'original_filename' =>
                                $file->getClientOriginalName(),
                            'original_extension' =>
                                $file->getClientOriginalExtension(),
                            'client_mime' =>
                                $file->getClientMimeType(),
                            'detected_mime' =>
                                $file->getMimeType(),
                            'size_bytes' => $size,
                            'upload_error' => $file->getError(),
                            'validation_errors' =>
                                $fileValidator->errors()->all(),
                        ]
                    );

                    $validator->errors()->add(
                        $key,
                        'The uploaded file must be a valid PDF, DOC, DOCX, JPG, JPEG, or PNG file.'
                    );
                }
            }
        });
    }

    /**
     * AFTER VALIDATION
     *
     * Validate:
     * agreement_date <= balance_as_of_date
     *
     * When either value is omitted, the application service must validate
     * the merged submitted and persisted values.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (
                    $validator->errors()->has('service_fields') ||
                    $validator->errors()->has('balance_as_of_date')
                ) {
                    return;
                }

                $agreementDate = $this->agreementDate();

                $balanceAsOfDate = $this->input(
                    'balance_as_of_date'
                );

                if (
                    $agreementDate === null ||
                    empty($balanceAsOfDate)
                ) {
                    return;
                }

                try {
                    $balanceDate = Carbon::parse(
                        $balanceAsOfDate
                    );
                } catch (\Throwable) {
                    return;
                }

                if ($balanceDate->lt($agreementDate)) {
                    $validator->errors()->add(
                        'balance_as_of_date',
                        sprintf(
                            'The balance as of date must be on or after the LIZZ agreement date. Agreement date: %s. Balance as of date: %s.',
                            $agreementDate->toDateString(),
                            $balanceDate->toDateString()
                        )
                    );
                }
            },
        ];
    }

    /**
     * PREPARE FOR VALIDATION
     *
     * Decode service_fields when submitted as JSON in multipart/form-data.
     */
    protected function prepareForValidation(): void
    {
        $serviceFields = $this->input('service_fields');

        if (! is_string($serviceFields)) {
            return;
        }

        $decoded = json_decode($serviceFields, true);

        if (
            json_last_error() === JSON_ERROR_NONE &&
            is_array($decoded)
        ) {
            $this->merge([
                'service_fields' => $decoded,
            ]);
        }
    }

    /**
     * TAXPAYER ID
     */
    public function taxpayerId(): ?string
    {
        if (! $this->exists('taxpayer_id')) {
            return null;
        }

        return $this->validated('taxpayer_id');
    }

    /**
     * REVENUE SERVICE ID
     */
    public function revenueServiceId(): ?string
    {
        if (! $this->exists('revenue_service_id')) {
            return null;
        }

        return $this->validated('revenue_service_id');
    }

    /**
     * DYNAMIC SERVICE FIELDS
     */
    public function serviceFields(): ?array
    {
        if (! $this->exists('service_fields')) {
            return null;
        }

        return $this->validated('service_fields', []);
    }

    /**
     * SERVICE FILES
     *
     * Returns uploads indexed by service UUID and field UUID:
     *
     * [
     *     'SERVICE_UUID' => [
     *         'FIELD_UUID' => UploadedFile,
     *     ],
     * ]
     */
    public function serviceFiles(): array
    {
        $this->validated();

        $files = [];

        foreach ($this->allFiles() as $key => $file) {
            if (
                ! is_string($key) ||
                ! str_starts_with($key, 'file__')
            ) {
                continue;
            }

            $parts = explode('__', $key);

            if (
                count($parts) !== 3 ||
                $parts[0] !== 'file' ||
                ! Str::isUuid($parts[1]) ||
                ! Str::isUuid($parts[2]) ||
                ! $file instanceof UploadedFile
            ) {
                continue;
            }

            [, $serviceId, $fieldId] = $parts;

            $files[$serviceId][$fieldId] = $file;
        }

        return $files;
    }

    /**
     * SOURCE
     */
    public function source(): ?string
    {
        if (! $this->exists('source')) {
            return null;
        }

        return $this->validated('source');
    }

    /**
     * NOTES
     */
    public function notes(): ?string
    {
        if (! $this->exists('notes')) {
            return null;
        }

        return $this->validated('notes');
    }

    /**
     * ORIGINAL HISTORICAL OBLIGATION
     */
    public function originalObligation(): ?float
    {
        if (! $this->exists('original_obligation')) {
            return null;
        }

        return (float) $this->validated(
            'original_obligation'
        );
    }

    /**
     * HISTORICAL AMOUNT ALREADY PAID
     */
    public function amountAlreadyPaid(): ?float
    {
        if (! $this->exists('amount_already_paid')) {
            return null;
        }

        return (float) $this->validated(
            'amount_already_paid'
        );
    }

    /**
     * BALANCE AS OF DATE
     */
    public function balanceAsOfDate(): ?string
    {
        if (! $this->exists('balance_as_of_date')) {
            return null;
        }

        return $this->validated(
            'balance_as_of_date'
        );
    }

    /**
     * AGREEMENT DATE
     *
     * Supports the semantic AGREEMENT_DATE key.
     *
     * If service_fields uses UUID keys, resolve the agreement-date field
     * through the configured revenue-service field definition.
     */
    public function agreementDate(): ?Carbon
    {
        $serviceFields = $this->input('service_fields', []);

        if (! is_array($serviceFields)) {
            return null;
        }

        $value = data_get(
            $serviceFields,
            'AGREEMENT_DATE'
        );

        if (
            ! is_string($value) ||
            trim($value) === ''
        ) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * WHETHER ANY FINANCIAL FIELD WAS SUBMITTED
     */
    public function hasFinancialUpdate(): bool
    {
        return $this->exists([
            'original_obligation',
            'amount_already_paid',
            'balance_as_of_date',
        ]);
    }
}
