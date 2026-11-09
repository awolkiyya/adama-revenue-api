<?php

namespace App\Modules\System\MobileAppRelease\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMobileAppReleaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /*
    |--------------------------------------------------------------------------
    | Prepare Input
    |--------------------------------------------------------------------------
    */

    protected function prepareForValidation(): void
    {
        if ($this->exists('is_mandatory')) {
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
                Rule::in(array_keys(config('mobile_apps.types', []))),
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
                Rule::unique('mobile_app_releases', 'version_code')
                    ->where('app_type', $this->input('app_type')),
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
                'max:' . config('mobile_apps.apk.max_size', 204800),
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
            'app_type.required' =>
                'Please select the mobile application.',

            'app_type.in' =>
                'The selected mobile application is not supported.',

            'app_type.max' =>
                'The application type cannot exceed 50 characters.',

            'version_name.required' =>
                'The release version name is required.',

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

            'release_notes.string' =>
                'Release notes must be valid text.',

            'is_mandatory.boolean' =>
                'The mandatory release field must be true or false.',

            'apk.required' =>
                'Please upload an APK file.',

            'apk.file' =>
                'The uploaded APK could not be read. Please select the file again.',

            'apk.extensions' =>
                'The uploaded file must have the .apk extension.',

            'apk.max' =>
                'The APK must not exceed 200 MB.',
        ];
    }
}
