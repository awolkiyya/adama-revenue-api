<?php

declare(strict_types=1);

namespace App\Modules\System\MobileAppRelease\Requests;

use App\Models\MobileAppRelease;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMobileAppReleaseRequest extends FormRequest
{
    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    */

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
    | Resolve Route Release
    |--------------------------------------------------------------------------
    */

    private function resolveRelease(): MobileAppRelease
    {
        $routeRelease = $this->route('mobile_app_release')
            ?? $this->route('mobileAppRelease')
            ?? $this->route('id');

        if ($routeRelease instanceof MobileAppRelease) {
            return $routeRelease;
        }

        if (! is_string($routeRelease) || $routeRelease === '') {
            abort(404, 'Mobile app release not found.');
        }

        return MobileAppRelease::query()
            ->whereKey($routeRelease)
            ->firstOrFail();
    }

    /*
    |--------------------------------------------------------------------------
    | Validation Rules
    |--------------------------------------------------------------------------
    */

    public function rules(): array
    {
        $release = $this->resolveRelease();

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

                /*
                 * Version codes are unique per application type.
                 *
                 * The current release is excluded from the uniqueness
                 * check so an unchanged version code remains valid.
                 */
                Rule::unique(
                    'mobile_app_releases',
                    'version_code'
                )
                    ->where(
                        fn ($query) => $query->where(
                            'app_type',
                            $release->app_type
                        )
                    )
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
            | Optional Chunked APK Upload
            |--------------------------------------------------------------------------
            |
            | If supplied, the completed upload replaces the existing APK.
            | If omitted, the existing APK remains unchanged.
            |
            */

            'upload_uuid' => [
                'sometimes',
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

            'app_type.prohibited' =>
                'The application type cannot be changed after release creation.',

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
                'This version code already exists for this application.',

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
                'Please provide a completed APK upload reference.',

            'upload_uuid.uuid' =>
                'The APK upload reference is invalid.',

            'upload_uuid.exists' =>
                'The specified APK upload session does not exist.',
        ];
    }
}
