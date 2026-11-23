<?php

namespace App\Modules\Revenue\Requests;

use App\Models\RevenueCategory;
use App\Models\RevenueCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateRevenueCategoryRequest extends FormRequest
{
    /**
     * Get the category being updated.
     */
    protected function category(): RevenueCategory
    {
        return $this->route('category');
    }

    public function authorize(): bool
    {
        return true;
    }

    /**
     * --------------------------------------------------------------------------
     * Validation Rules
     * --------------------------------------------------------------------------
     */
    public function rules(): array
    {
        $category = $this->category();

        $revenueDomain = $this->input(
            'revenue_domain',
            $category->revenue_domain
        );

        return [

            /*
            |--------------------------------------------------------------------------
            | Category
            |--------------------------------------------------------------------------
            */

            'revenue_domain' => [
                'sometimes',
                Rule::in([
                    'TAX',
                    'RENT',
                    'INVESTMENT',
                    'SERVICE',
                    'SALE',
                    'CAPITAL',
                ]),
            ],

            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('revenue_categories', 'name')
                    ->where('revenue_domain', $revenueDomain)
                    ->whereNull('deleted_at')
                    ->ignore($category->id),
            ],

            'start_code' => [
                'sometimes',
                'nullable',
                'integer',
                'required_with:end_code',
            ],

            'end_code' => [
                'sometimes',
                'nullable',
                'integer',
                'required_with:start_code',
                'gte:start_code',
            ],

            'description' => [
                'sometimes',
                'nullable',
                'string',
            ],

            'sort_order' => [
                'sometimes',
                'nullable',
                'integer',
                'min:0',
                Rule::unique('revenue_categories', 'sort_order')
                    ->where('revenue_domain', $revenueDomain)
                    ->whereNull('deleted_at')
                    ->ignore($category->id),
            ],

            'is_active' => [
                'sometimes',
                'boolean',
            ],

            /*
            |--------------------------------------------------------------------------
            | Revenue Codes
            |--------------------------------------------------------------------------
            */

            'codes' => [
                'sometimes',
                'array',
            ],

            /*
             * Existing code:
             * {
             *     "id": "uuid",
             *     "code": "1001"
             * }
             *
             * New code:
             * {
             *     "id": null,
             *     "code": "1002"
             * }
             */

            'codes.*.id' => [
                'nullable',
                'uuid',
            ],

            'codes.*.code' => [
                'required',
                'string',
                'max:20',
                'distinct',
            ],

            'codes.*.name' => [
                'required',
                'string',
                'max:255',
            ],

            'codes.*.description' => [
                'sometimes',
                'nullable',
                'string',
            ],

            'codes.*.is_active' => [
                'sometimes',
                'boolean',
            ],
        ];
    }

    /**
     * --------------------------------------------------------------------------
     * Additional Business Validation
     * --------------------------------------------------------------------------
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {

            $category = $this->category();

            /*
            |--------------------------------------------------------------------------
            | Resolve Effective Category Range
            |--------------------------------------------------------------------------
            |
            | For partial updates, use the existing category values
            | when start_code or end_code is not provided.
            |
            */

            $startCode = $this->exists('start_code')
                ? $this->input('start_code')
                : $category->start_code;

            $endCode = $this->exists('end_code')
                ? $this->input('end_code')
                : $category->end_code;

            $revenueDomain = $this->input(
                'revenue_domain',
                $category->revenue_domain
            );

            /*
            |--------------------------------------------------------------------------
            | Check Existing Revenue Code Ownership
            |--------------------------------------------------------------------------
            |
            | Include soft-deleted records because the service supports
            | restoring previously deleted revenue codes.
            |
            */

            foreach ($this->input('codes', []) as $index => $code) {

                if (!is_array($code) || empty($code['id'])) {
                    continue;
                }

                $belongs = $category
                    ->codes()
                    ->withTrashed()
                    ->where('id', $code['id'])
                    ->exists();

                if (!$belongs) {

                    $validator->errors()->add(
                        "codes.$index.id",
                        'This revenue code does not belong to this category.'
                    );

                }
            }

            /*
            |--------------------------------------------------------------------------
            | Prevent Overlapping Category Ranges
            |--------------------------------------------------------------------------
            |
            | Soft-deleted categories are automatically excluded by
            | RevenueCategory's SoftDeletes global scope.
            |
            */

            if (
                $startCode !== null &&
                $endCode !== null &&
                !$validator->errors()->has('start_code') &&
                !$validator->errors()->has('end_code')
            ) {

                $overlap = RevenueCategory::query()

                    ->where('revenue_domain', $revenueDomain)

                    ->where('id', '!=', $category->id)

                    ->where(function ($query) use (
                        $startCode,
                        $endCode
                    ) {

                        $query

                            ->whereBetween('start_code', [
                                $startCode,
                                $endCode,
                            ])

                            ->orWhereBetween('end_code', [
                                $startCode,
                                $endCode,
                            ])

                            ->orWhere(function ($query) use (
                                $startCode,
                                $endCode
                            ) {

                                $query
                                    ->where('start_code', '<=', $startCode)
                                    ->where('end_code', '>=', $endCode);

                            });

                    })

                    ->exists();

                if ($overlap) {

                    $validator->errors()->add(
                        'start_code',
                        'The selected revenue range overlaps another category.'
                    );

                }
            }

            /*
            |--------------------------------------------------------------------------
            | Validate Revenue Codes
            |--------------------------------------------------------------------------
            */

            foreach ($this->input('codes', []) as $index => $code) {

                if (
                    !is_array($code) ||
                    !isset($code['code'])
                ) {
                    continue;
                }

                /*
                |--------------------------------------------------------------------------
                | Prevent Duplicate Active Revenue Codes
                |--------------------------------------------------------------------------
                |
                | RevenueCode uses SoftDeletes, so this query automatically
                | ignores soft-deleted revenue codes.
                |
                | Exclude the current code when updating.
                |
                */

                $exists = RevenueCode::query()

                    ->where('code', $code['code'])

                    ->when(
                        !empty($code['id']),
                        function ($query) use ($code) {

                            $query->where(
                                'id',
                                '!=',
                                $code['id']
                            );

                        }
                    )

                    ->exists();

                if ($exists) {

                    $validator->errors()->add(
                        "codes.$index.code",
                        'This revenue code already exists.'
                    );

                }

                /*
                |--------------------------------------------------------------------------
                | Ensure Revenue Code Is Inside Category Range
                |--------------------------------------------------------------------------
                */

                if (
                    $startCode !== null &&
                    $endCode !== null &&
                    is_numeric($code['code'])
                ) {

                    $number = (int) $code['code'];

                    if (
                        $number < $startCode ||
                        $number > $endCode
                    ) {

                        $validator->errors()->add(
                            "codes.$index.code",
                            "Revenue code must be between {$startCode} and {$endCode}."
                        );

                    }
                }
            }
        });
    }

    /**
     * --------------------------------------------------------------------------
     * Normalize Input
     * --------------------------------------------------------------------------
     */
    protected function prepareForValidation(): void
    {
        $data = [];

        /*
        |--------------------------------------------------------------------------
        | Normalize Category Fields
        |--------------------------------------------------------------------------
        */

        if ($this->has('name')) {

            $data['name'] = trim(
                (string) $this->input('name')
            );

        }

        if ($this->has('description')) {

            $data['description'] = $this->filled('description')
                ? trim((string) $this->input('description'))
                : null;

        }

        if ($this->has('is_active')) {

            $data['is_active'] = filter_var(
                $this->input('is_active'),
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE
            );

        }

        /*
        |--------------------------------------------------------------------------
        | Normalize Revenue Codes
        |--------------------------------------------------------------------------
        */

        if ($this->has('codes')) {

            $data['codes'] = collect($this->input('codes', []))

                ->map(function ($code) {

                    return [

                        'id' => $code['id'] ?? null,

                        'code' => isset($code['code'])
                            ? trim((string) $code['code'])
                            : null,

                        'name' => isset($code['name'])
                            ? trim((string) $code['name'])
                            : null,

                        'description' => isset($code['description'])
                            ? trim((string) $code['description'])
                            : null,

                        'is_active' => filter_var(
                            $code['is_active'] ?? true,
                            FILTER_VALIDATE_BOOLEAN,
                            FILTER_NULL_ON_FAILURE
                        ),

                    ];

                })

                ->values()

                ->all();

        }

        $this->merge($data);
    }

    /**
     * --------------------------------------------------------------------------
     * Custom Validation Messages
     * --------------------------------------------------------------------------
     */
    public function messages(): array
    {
        return [

            'name.required' =>
                'Revenue category name is required.',

            'name.unique' =>
                'A category with this name already exists in the selected revenue domain.',

            'sort_order.unique' =>
                'This display order is already assigned within the selected revenue domain.',

            'codes.*.code.required' =>
                'Revenue code is required.',

            'codes.*.code.distinct' =>
                'Duplicate revenue codes are not allowed.',

            'codes.*.id.uuid' =>
                'Invalid revenue code identifier.',

            'end_code.gte' =>
                'End code must be greater than or equal to start code.',

            'start_code.required_with' =>
                'Start code is required when an end code is provided.',

            'end_code.required_with' =>
                'End code is required when a start code is provided.',

        ];
    }
}
