<?php

namespace App\Modules\System\MobileAppRelease\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMobileAppReleaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('is_mandatory')) {
            $value = $this->input('is_mandatory');

            if ($value === 'true' || $value === '1') {
                $this->merge(['is_mandatory' => true]);
            } elseif ($value === 'false' || $value === '0') {
                $this->merge(['is_mandatory' => false]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'version_name' => [
                'required',
                'string',
                'max:50',
            ],

            'version_code' => [
                'required',
                'integer',
                'min:1',
                Rule::unique('mobile_app_releases', 'version_code'),
            ],

            'release_notes' => [
                'nullable',
                'string',
            ],

            'is_mandatory' => [
                'sometimes',
                'boolean',
            ],

            'apk' => [
                'required',
                'file',
                'extensions:apk',
                'max:204800',
            ],
        ];
    }

    public function messages(): array
    {
        return [
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
                'This version code already exists. Please use a different version code.',

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
