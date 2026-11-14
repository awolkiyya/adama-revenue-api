<?php

namespace App\Modules\System\MobileAppRelease\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class StoreMobileAppReleaseRequest extends FormRequest
{
    /**
     * Log Form Request initialization.
     */
    public function __construct()
    {
        parent::__construct();

        Log::info(
            'Mobile app release: Form Request instantiated.',
            [
                'method' => $this->method(),
                'content_type' => $this->header('Content-Type'),
                'content_length' => $this->server('CONTENT_LENGTH'),
                'request_id' => $this->header('X-Request-ID'),
            ]
        );
    }

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
        if ($this->has('is_mandatory')) {
            $value = filter_var(
                $this->input('is_mandatory'),
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE
            );

            if ($value !== null) {
                $this->merge([
                    'is_mandatory' => $value,
                ]);
            }
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

        $maxSize = (int) config(
            'mobile_apps.apk.max_size',
            307200
        );

        return [
            /*
            |--------------------------------------------------------------------------
            | Application
            |--------------------------------------------------------------------------
            */

            'app_type' => [
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
                'required',
                'string',
                'max:50',
            ],

            'version_code' => [
                'required',
                'integer',
                'min:1',

                // Version codes are unique within each application type.
                // Different applications may share the same version code.
                Rule::unique('mobile_app_releases', 'version_code')
                    ->where('app_type', $appType),
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
            | APK Upload
            |--------------------------------------------------------------------------
            */

            'apk' => [
                'required',
                'file',
                'extensions:apk',
                'mimetypes:application/vnd.android.package-archive,application/zip',
                'max:' . $maxSize,
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
        $maxSize = (int) config(
            'mobile_apps.apk.max_size',
            307200
        );

        $maxSizeMb = (int) ceil($maxSize / 1024);

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
            | APK Upload
            |--------------------------------------------------------------------------
            */

            'apk.required' =>
                'Please upload an APK file.',

            'apk.file' =>
                'The uploaded APK could not be read. Please select the file again.',

            'apk.extensions' =>
                'The uploaded file must have the .apk extension.',

            'apk.mimetypes' =>
                'The uploaded file must have a valid Android APK file type.',

            'apk.max' =>
                "The APK must not exceed {$maxSizeMb} MB.",
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
        $apk = $this->file('apk');

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
                | APK Diagnostics
                |--------------------------------------------------------------------------
                */

                'has_apk' => $this->hasFile('apk'),

                'apk_original_name' =>
                    $apk?->getClientOriginalName(),

                'apk_size_bytes' =>
                    $apk?->getSize(),

                'apk_mime_type' =>
                    $apk?->getMimeType(),

                'apk_client_mime_type' =>
                    $apk?->getClientMimeType(),

                'apk_valid' =>
                    $apk?->isValid(),

                'apk_error' =>
                    $apk?->getError(),

                /*
                |--------------------------------------------------------------------------
                | PHP Upload Configuration
                |--------------------------------------------------------------------------
                */

                'content_length' =>
                    $this->server('CONTENT_LENGTH'),

                'post_max_size' =>
                    ini_get('post_max_size'),

                'upload_max_filesize' =>
                    ini_get('upload_max_filesize'),

                /*
                |--------------------------------------------------------------------------
                | Request Information
                |--------------------------------------------------------------------------
                */

                'request_id' =>
                    $this->header('X-Request-ID'),

                'ip_address' =>
                    $this->ip(),
            ]
        );

        parent::failedValidation($validator);
    }
}
