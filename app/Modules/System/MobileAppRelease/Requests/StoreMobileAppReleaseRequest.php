<?php

declare(strict_types=1);

namespace App\Modules\System\MobileAppRelease\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class StoreMobileAppReleaseRequest extends FormRequest
{
    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    */

    public function authorize(): bool
    {
        $authorized = $this->user() !== null;

        if (! $authorized) {
            Log::warning(
                'Mobile app release: authorization failed.',
                [
                    'method' => $this->method(),
                    'path' => $this->path(),
                    'ip_address' => $this->ip(),
                    'request_id' => $this->header('X-Request-ID'),
                ]
            );
        }

        return $authorized;
    }

    /*
    |--------------------------------------------------------------------------
    | Prepare Input
    |--------------------------------------------------------------------------
    */

    protected function prepareForValidation(): void
    {
        $merge = [];

        /*
         * Normalize mandatory release flag.
         */
        if ($this->exists('is_mandatory')) {
            $value = filter_var(
                $this->input('is_mandatory'),
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE
            );

            if ($value !== null) {
                $merge['is_mandatory'] = $value;
            }
        }

        /*
         * Normalize application type.
         */
        if (is_string($this->input('app_type'))) {
            $merge['app_type'] = trim(
                $this->input('app_type')
            );
        }

        /*
         * Normalize version name.
         */
        if (is_string($this->input('version_name'))) {
            $merge['version_name'] = trim(
                $this->input('version_name')
            );
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Validation Rules
    |--------------------------------------------------------------------------
    */

    public function rules(): array
    {
        $appType = $this->input('app_type');

        return [
            /*
            |--------------------------------------------------------------------------
            | Application
            |--------------------------------------------------------------------------
            */

            'app_type' => [
                'bail',
                'required',
                'string',
                'max:50',
                Rule::in(
                    array_keys(config('mobile_apps.types', []))
                ),
            ],

            /*
            |--------------------------------------------------------------------------
            | Release Version
            |--------------------------------------------------------------------------
            */

            'version_name' => [
                'bail',
                'required',
                'string',
                'max:50',
            ],

            'version_code' => [
                'bail',
                'required',
                'integer',
                'min:1',

                /*
                 * Version codes are unique per application type,
                 * not globally across all mobile applications.
                 *
                 * Example:
                 * field_collector: version_code = 2
                 * taxpayer:        version_code = 2
                 *
                 * Both are allowed.
                 */
                Rule::unique(
                    'mobile_app_releases',
                    'version_code'
                )->where(
                    fn ($query) => $query->where(
                        'app_type',
                        $appType
                    )
                ),
            ],

            /*
            |--------------------------------------------------------------------------
            | Release Information
            |--------------------------------------------------------------------------
            */

            'release_notes' => [
                'nullable',
                'string',
            ],

            /*
            |--------------------------------------------------------------------------
            | Update Policy
            |--------------------------------------------------------------------------
            */

            'is_mandatory' => [
                'sometimes',
                'boolean',
            ],

            /*
            |--------------------------------------------------------------------------
            | Completed Chunked APK Upload
            |--------------------------------------------------------------------------
            |
            | The APK must already have been uploaded and assembled
            | through the resumable upload endpoints.
            |
            */

            'upload_uuid' => [
                'bail',
                'required',
                'uuid',
                'exists:mobile_app_release_uploads,uuid',
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Messages
    |--------------------------------------------------------------------------
    */

    public function messages(): array
    {
        return [
            /*
            |--------------------------------------------------------------------------
            | Application
            |--------------------------------------------------------------------------
            */

            'app_type.required' =>
                'Please select the mobile application.',

            'app_type.string' =>
                'The application type must be valid text.',

            'app_type.in' =>
                'The selected mobile application is not supported.',

            'app_type.max' =>
                'The application type cannot exceed 50 characters.',

            /*
            |--------------------------------------------------------------------------
            | Release Version
            |--------------------------------------------------------------------------
            */

            'version_name.required' =>
                'The release version name is required.',

            'version_name.string' =>
                'The release version name must be valid text.',

            'version_name.max' =>
                'The release version name cannot exceed 50 characters.',

            'version_code.required' =>
                'The Android version code is required.',

            'version_code.integer' =>
                'The Android version code must be an integer.',

            'version_code.min' =>
                'The Android version code must be at least 1.',

            'version_code.unique' =>
                'This version code already exists for the selected application.',

            /*
            |--------------------------------------------------------------------------
            | Release Information
            |--------------------------------------------------------------------------
            */

            'release_notes.string' =>
                'Release notes must be valid text.',

            /*
            |--------------------------------------------------------------------------
            | Update Policy
            |--------------------------------------------------------------------------
            */

            'is_mandatory.boolean' =>
                'The mandatory release field must be true or false.',

            /*
            |--------------------------------------------------------------------------
            | Chunked APK Upload
            |--------------------------------------------------------------------------
            */

            'upload_uuid.required' =>
                'Please complete the APK upload before creating the release.',

            'upload_uuid.uuid' =>
                'The APK upload reference is invalid.',

            'upload_uuid.exists' =>
                'The specified APK upload session does not exist.',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Validation Failure Logging
    |--------------------------------------------------------------------------
    */

    protected function failedValidation(
        Validator $validator
    ): void {
        Log::warning(
            'Mobile app release: request validation failed.',
            [
                'errors' => $validator->errors()->toArray(),

                'user_id' => $this->user()
                    ? (string) $this->user()->getKey()
                    : null,

                'app_type' => $this->input('app_type'),

                'version_name' => $this->input('version_name'),

                'version_code' => $this->input('version_code'),

                'is_mandatory' => $this->input('is_mandatory'),

                /*
                |--------------------------------------------------------------------------
                | Upload Session Diagnostics
                |--------------------------------------------------------------------------
                */

                'upload_uuid' => $this->input('upload_uuid'),

                /*
                |--------------------------------------------------------------------------
                | Request Information
                |--------------------------------------------------------------------------
                */

                'content_length' => $this->server('CONTENT_LENGTH'),

                'request_id' => $this->header('X-Request-ID'),

                'ip_address' => $this->ip(),
            ]
        );

        parent::failedValidation($validator);
    }
}
