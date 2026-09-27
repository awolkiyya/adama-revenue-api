<?php

namespace App\Modules\Assessment\Requests;

use App\Models\RevenueService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;
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
            | The backend verifies that serviceCode belongs
            | to the submitted serviceId.
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

                    preg_match(
                        '/services\.(\d+)\.serviceCode/',
                        $attribute,
                        $matches
                    );

                    if (! isset($matches[1])) {
                        return;
                    }

                    $index = (int) $matches[1];

                    $serviceId = $this->input(
                        "services.{$index}.serviceId"
                    );

                    if (! $serviceId) {
                        return;
                    }

                    $matchesService = RevenueService::query()
                        ->whereKey($serviceId)
                        ->whereHas(
                            'revenueCode',
                            function ($query) use ($value): void {
                                $query->where('code', $value);
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
            |
            | Fields are keyed by RevenueField.id.
            |
            | Example:
            |
            | fields: {
            |     "01JFIELD-ID-1": 10000,
            |     "01JFIELD-ID-2": "COMMERCIAL"
            | }
            |
            */

            'services.*.fields' => [
                'required',
                'array',
            ],
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

        if (! is_string($services)) {
            return;
        }

        $decoded = json_decode(
            $services,
            true
        );

        if (
            json_last_error() === JSON_ERROR_NONE &&
            is_array($decoded)
        ) {
            $this->merge([
                'services' => $decoded,
            ]);
        }
    }

    /**
     * ----------------------------------------------------------------------
     * CONFIGURE VALIDATOR
     * ----------------------------------------------------------------------
     *
     * Validate that every submitted field ID actually belongs
     * to the selected RevenueService.
     *
     * This is important because the frontend must never be trusted
     * to decide which fields belong to a service.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {

            $services = $this->input('services', []);

            if (! is_array($services)) {
                return;
            }

            foreach ($services as $index => $service) {

                if (! is_array($service)) {
                    continue;
                }

                $serviceId = $service['serviceId'] ?? null;
                $fields = $service['fields'] ?? [];

                if (! $serviceId || ! is_array($fields)) {
                    continue;
                }

                /*
                 * Load the service together with its fields.
                 */
                $revenueService = RevenueService::query()
                    ->with('fields')
                    ->find($serviceId);

                if (! $revenueService) {
                    continue;
                }

                /*
                 * Build a fast lookup of valid RevenueField IDs.
                 */
                $validFieldIds = $revenueService->fields
                    ->pluck('id')
                    ->map(fn ($id) => (string) $id)
                    ->flip();

                /*
                 * The submitted `fields` array is keyed by
                 * RevenueField.id.
                 */
                foreach (array_keys($fields) as $fieldId) {

                    $fieldId = (string) $fieldId;

                    if (! isset($validFieldIds[$fieldId])) {
                        $validator->errors()->add(
                            "services.{$index}.fields.{$fieldId}",
                            "The field [{$fieldId}] does not belong to service [{$serviceId}]."
                        );
                    }
                }
            }
        });
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