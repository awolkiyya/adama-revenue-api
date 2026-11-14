<?php

namespace App\Modules\System\MobileAppRelease\Requests;

use App\Models\MobileAppRelease;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMobileAppReleaseRequest extends FormRequest
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
        $routeRelease = $this->route('mobile_app_release')
            ?? $this->route('mobileAppRelease')
            ?? $this->route('id');

        $release = $routeRelease instanceof MobileAppRelease
            ? $routeRelease
            : MobileAppRelease::findOrFail($routeRelease);

        return [
            /*
            |--------------------------------------------------------------------------
            | Application
            |--------------------------------------------------------------------------
            */

            'app_type' => [
                'prohibited',
            ],

            /*
            |--------------------------------------------------------------------------
            | Release Version
            |--------------------------------------------------------------------------
            */

            'version_name' => [
                'sometimes',
                'required',
                'string',
                'max:50',
            ],

            'version_code' => [
                'sometimes',
                'required',
                'integer',
                'min:1',
                Rule::unique('mobile_app_releases', 'version_code')
                    ->where('app_type', $release->app_type)
                    ->ignore($release->getKey()),
            ],

            /*
            |--------------------------------------------------------------------------
            | Release Information
            |--------------------------------------------------------------------------
            */

            'release_notes' => [
                'sometimes',
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
                'sometimes',
                'nullable',
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
            'app_type.prohibited' =>
                'The application type cannot be changed after release creation.',

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
                'This version code already exists for this application.',

            'release_notes.string' =>
                'Release notes must be valid text.',

            'is_mandatory.boolean' =>
                'The mandatory release field must be true or false.',

            'apk.file' =>
                'The uploaded APK could not be read. Please select the file again.',

            'apk.extensions' =>
                'The uploaded file must have the .apk extension.',

            'apk.max' =>
                'The APK must not exceed 200 MB.',
        ];
    }
}
