<?php

namespace App\Modules\Assessment\Requests;

use App\Models\RevenueService;
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
            | The frontend sends:
            |
            | serviceId
            | serviceCode
            |
            | The backend verifies that the submitted serviceCode
            | actually belongs to the selected serviceId.
            |
            */

            'services.*.serviceCode' => [
                'required',
                'string',
                'max:100',

                function (
                    string $attribute,
                    mixed $value,
                    \Closure $fail
                ): void {

                    /*
                     * Example attribute:
                     *
                     * services.0.serviceCode
                     *
                     * Extract:
                     *
                     * 0
                     */
                    preg_match(
                        '/services\.(\d+)\.serviceCode/',
                        $attribute,
                        $matches
                    );


                    if (! isset($matches[1])) {
                        return;
                    }


                    $index = (int) $matches[1];


                    /*
                     * Get the service ID from the same service
                     * object.
                     */
                    $serviceId = $this->input(
                        "services.{$index}.serviceId"
                    );


                    if (! $serviceId) {
                        return;
                    }


                    /*
                     * Verify that this service ID has the
                     * submitted revenue code.
                     *
                     * revenue_services.revenue_code_id
                     *          ↓
                     * revenue_codes.code
                     */
                    $matchesService = RevenueService::query()
                        ->whereKey($serviceId)
                        ->whereHas(
                            'revenueCode',
                            function ($query) use ($value) {
                                $query->where(
                                    'code',
                                    $value
                                );
                            }
                        )
                        ->exists();


                    if (! $matchesService) {

                        $fail(
                            "The serviceCode does not match serviceId [{$serviceId}]."
                        );
                    }
                },
            ],


            /*
            |--------------------------------------------------------------------------
            | Dynamic Fields
            |--------------------------------------------------------------------------
            */

            'services.*.fields' => [
                'required',
                'array',
            ],


            /*
            |--------------------------------------------------------------------------
            | Dynamic File Inputs
            |--------------------------------------------------------------------------
            |
            | Files are validated separately because their names are:
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