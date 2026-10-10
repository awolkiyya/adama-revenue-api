
<?php

namespace App\Http\Requests;

use App\Models\MobileAppRelease;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMobileAppReleaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $routeRelease = $this->route('mobile_app_release')
            ?? $this->route('mobileAppRelease')
            ?? $this->route('id');

        $releaseId = $routeRelease instanceof MobileAppRelease
            ? $routeRelease->getKey()
            : $routeRelease;

        return [
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
                    ->ignore($releaseId),
            ],

            'release_notes' => [
                'sometimes',
                'nullable',
                'string',
            ],

            'is_mandatory' => [
                'sometimes',
                'boolean',
            ],

            'apk' => [
                'sometimes',
                'nullable',
                'file',
                'extensions:apk',
                'max:204800',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'version_code.unique' => 'This version code already exists.',
            'apk.extensions' => 'The uploaded file must have the .apk extension.',
            'apk.max' => 'The APK must not exceed 200 MB.',
        ];
    }
}