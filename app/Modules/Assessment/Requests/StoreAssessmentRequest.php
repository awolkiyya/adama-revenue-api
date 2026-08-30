<?php

namespace App\Modules\Assessment\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAssessmentRequest extends FormRequest
{
    /**
     * ----------------------------------------------------------------------
     * AUTHORIZE
     * ----------------------------------------------------------------------
     */
    public function authorize(): bool
    {
        return true;
    }


    /**
     * ----------------------------------------------------------------------
     * VALIDATION RULES
     * ----------------------------------------------------------------------
     */
    public function rules(): array
    {
        return [

            /*
            |--------------------------------------------------------------------------
            | Taxpayer / Citizen
            |--------------------------------------------------------------------------
            |
            | Frontend:
            |
            | taxpayerId
            |
            | Database:
            |
            | assessments.citizen_id
            |
            */

            'taxpayerId' => [
                'required',
                'uuid',
                'exists:citizens,id',
            ],


            /*
            |--------------------------------------------------------------------------
            | Notes
            |--------------------------------------------------------------------------
            */

            'notes' => [
                'nullable',
                'string',
                'max:5000',
            ],


            /*
            |--------------------------------------------------------------------------
            | Status
            |--------------------------------------------------------------------------
            |
            | Assessment creation only allows:
            |
            | DRAFT
            | PENDING_APPROVAL
            |
            | Approval/rejection/cancellation are separate
            | workflow operations.
            |
            */

            'status' => [
                'nullable',
                Rule::in([
                    'DRAFT',
                    'PENDING_APPROVAL',
                ]),
            ],


            /*
            |--------------------------------------------------------------------------
            | Services
            |--------------------------------------------------------------------------
            |
            | At least one revenue service must be selected.
            |
            */

            'services' => [
                'required',
                'array',
                'min:1',
            ],


            /*
            |--------------------------------------------------------------------------
            | Revenue Service ID
            |--------------------------------------------------------------------------
            */

            'services.*.serviceId' => [
                'required',
                'uuid',
                'exists:revenue_services,id',
            ],


            /*
            |--------------------------------------------------------------------------
            | Revenue Service Code
            |--------------------------------------------------------------------------
            |
            | This is a snapshot sent by the frontend.
            |
            | The backend should still verify that it belongs
            | to the selected service.
            |
            */

            'services.*.serviceCode' => [
                'required',
                'string',
                'max:100',
            ],


            /*
            |--------------------------------------------------------------------------
            | Dynamic Fields
            |--------------------------------------------------------------------------
            |
            | Example:
            |
            | {
            |     "LAND_AREA": "105",
            |     "PROPERTY_TYPE": "RESIDENTIAL"
            | }
            |
            */

            'services.*.fields' => [
                'required',
                'array',
            ],


            /*
            |--------------------------------------------------------------------------
            | File Validation
            |--------------------------------------------------------------------------
            |
            | Dynamic file inputs are handled separately because
            | their names are generated from:
            |
            | file__{serviceId}__{fieldCode}
            |
            */

        ];
    }


    /**
     * ----------------------------------------------------------------------
     * PREPARE FOR VALIDATION
     * ----------------------------------------------------------------------
     *
     * The frontend sends `services` as JSON inside multipart FormData.
     *
     * Convert:
     *
     *     "[{...}]"
     *
     * into:
     *
     *     [{...}]
     */
    protected function prepareForValidation(): void
    {
        $services = $this->input('services');


        if (is_string($services)) {

            $decoded = json_decode(
                $services,
                true
            );


            if (
                json_last_error() ===
                JSON_ERROR_NONE
            ) {

                $this->merge([
                    'services' => $decoded,
                ]);
            }
        }
    }


    /**
     * ----------------------------------------------------------------------
     * VALIDATED SERVICES
     * ----------------------------------------------------------------------
     *
     * Useful if the controller/service wants normalized data.
     */
    public function services(): array
    {
        return $this->validated('services', []);
    }


    /**
     * ----------------------------------------------------------------------
     * TAXPAYER ID
     * ----------------------------------------------------------------------
     */
    public function taxpayerId(): string
    {
        return $this->validated('taxpayerId');
    }


    /**
     * ----------------------------------------------------------------------
     * STATUS
     * ----------------------------------------------------------------------
     */
    public function status(): string
    {
        return $this->validated('status', 'DRAFT');
    }
}