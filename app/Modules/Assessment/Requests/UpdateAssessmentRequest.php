<?php

namespace App\Modules\Assessment\Requests;

use App\Models\RevenueService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAssessmentRequest extends FormRequest
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
            | Optional during update.
            |
            */

            'taxpayerId' => [
                'sometimes',
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
                'sometimes',
                'nullable',
                'string',
                'max:5000',
            ],

            /*
            |--------------------------------------------------------------------------
            | Status
            |--------------------------------------------------------------------------
            |
            | Assessment update only allows:
            |
            | DRAFT
            | PENDING_APPROVAL
            |
            */

            'status' => [
                'sometimes',
                'required',
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
            | Services are optional during an update.
            |
            */

            'services' => [
                'sometimes',
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
            | The backend verifies that serviceCode actually belongs
            | to the selected serviceId.
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

                    $serviceCode = trim((string) $value);

                    $matchesService = RevenueService::query()
                        ->whereKey($serviceId)
                        ->whereHas(
                            'revenueCode',
                            function ($query) use ($serviceCode): void {
                                $query->where(
                                    'code',
                                    $serviceCode
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

            /*
            |--------------------------------------------------------------------------
            | Files
            |--------------------------------------------------------------------------
            |
            | Files are sent separately through multipart FormData.
            |
            */

            'files.*' => [
                'nullable',
                'file',
                'max:10240',
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
     * AFTER VALIDATION
     * ----------------------------------------------------------------------
     *
     * Verify that every submitted RevenueField.id belongs to the
     * corresponding RevenueService.
     *
     * This prevents the client from submitting arbitrary field IDs.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {

            /*
             * If services were not included in this update,
             * there is nothing to validate here.
             */
            if (! $this->has('services')) {
                return;
            }

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

                if (
                    ! $serviceId ||
                    ! is_array($fields)
                ) {
                    continue;
                }

                /*
                 * Load the service and its RevenueFields.
                 */
                $revenueService = RevenueService::query()
                    ->with('fields')
                    ->find($serviceId);

                if (! $revenueService) {
                    continue;
                }

                /*
                 * Build a lookup of valid RevenueField IDs.
                 */
                $validFieldIds = $revenueService->fields
                    ->pluck('id')
                    ->map(fn ($id) => (string) $id)
                    ->flip();

                /*
                 * The keys of `fields` are RevenueField.id values.
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
    public function taxpayerId(): ?string
    {
        return $this->validated('taxpayerId');
    }

    /**
     * ----------------------------------------------------------------------
     * STATUS
     * ----------------------------------------------------------------------
     */
    public function status(): ?string
    {
        return $this->validated('status');
    }
}