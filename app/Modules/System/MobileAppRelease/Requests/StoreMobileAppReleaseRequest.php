
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMobileAppReleaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Protect this endpoint with your administrator permissions middleware.
        return $this->user() !== null;
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
            'version_name.required' => 'The release version name is required.',
            'version_code.required' => 'The Android version code is required.',
            'version_code.unique' => 'This version code already exists.',
            'apk.required' => 'Please upload an APK file.',
            'apk.extensions' => 'The uploaded file must have the .apk extension.',
            'apk.max' => 'The APK must not exceed 200 MB.',
        ];
    }
}