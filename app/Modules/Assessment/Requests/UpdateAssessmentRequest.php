<?php

namespace App\Modules\Assessment\Requests;

use App\Models\RevenueService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAssessmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'taxpayerId' => [
                'sometimes',
                'required',
                'uuid',
                'exists:citizens,id',
            ],

            'notes' => [
                'nullable',
                'string',
                'max:5000',
            ],

            'status' => [
                'sometimes',
                Rule::in([
                    'DRAFT',
                    'PENDING_APPROVAL',
                ]),
            ],

            'services' => [
                'sometimes',
                'required',
                'array',
                'min:1',
            ],

            'services.*.serviceId' => [
                'required',
                'uuid',
                'exists:revenue_services,id',
            ],

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
                            function ($query) use ($serviceCode) {
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

            'services.*.fields' => [
                'required',
                'array',
            ],

            'files.*' => [
                'nullable',
                'file',
                'max:10240',
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $services = $this->input('services');

        if (is_string($services)) {
            $decoded = json_decode($services, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                $this->merge([
                    'services' => $decoded,
                ]);
            }
        }
    }

    public function services(): array
    {
        return $this->validated('services', []);
    }

    public function taxpayerId(): ?string
    {
        return $this->validated('taxpayerId');
    }

    public function status(): ?string
    {
        return $this->validated('status');
    }
}