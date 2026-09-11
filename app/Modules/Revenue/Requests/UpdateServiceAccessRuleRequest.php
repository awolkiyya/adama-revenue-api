<?php

namespace App\Modules\Revenue\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateServiceAccessRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sectors' => [
                'required',
                'array',
                'min:1',
            ],

            'sectors.*' => [
                'required',
                'array',
            ],

            'sectors.*.sectorId' => [
                'required',
                'uuid',
                'exists:sectors,id',
            ],

            'sectors.*.sectorName' => [
                'nullable',
                'string',
                'max:255',
            ],

            'sectors.*.isActive' => [
                'required',
                'boolean',
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {

            $sectors = $this->input('sectors', []);

            if (!is_array($sectors) || empty($sectors)) {
                return;
            }

            $sectorIds = collect($sectors)
                ->pluck('sectorId')
                ->filter()
                ->values();

            if ($sectorIds->duplicates()->isNotEmpty()) {
                $validator->errors()->add(
                    'sectors',
                    'Each sector can only appear once.'
                );
            }
        });
    }
}