<?php

namespace App\Modules\Revenue\Requests;

use App\Models\RevenueCategory;
use App\Models\RevenueCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateRevenueCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }


    protected function category(): RevenueCategory
    {
        return $this->route('category');
    }



    /**
     * Validation Rules
     */
    public function rules(): array
    {
        $category = $this->category();


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

                'string',

                'max:255',

                Rule::unique('revenue_categories')
                    ->where(
                        fn ($query) =>
                            $query->where(
                                'revenue_domain',
                                $this->input(
                                    'revenue_domain',
                                    $category->revenue_domain
                                )
                            )
                    )
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
             *    id: uuid
             * }
             *
             * New code:
             * {
             *    id: null
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
     * Business Validation
     */
    public function withValidator(Validator $validator): void
    {

        $validator->after(function ($validator) {


            $category = $this->category();



            /*
            |--------------------------------------------------------------------------
            | Check existing code belongs to category
            |--------------------------------------------------------------------------
            */

            foreach ($this->input('codes', []) as $index => $code) {


                if (!empty($code['id'])) {


                    $belongs = $category
                        ->codes()
                        ->where(
                            'id',
                            $code['id']
                        )
                        ->exists();



                    if (!$belongs) {


                        $validator->errors()->add(

                            "codes.$index.id",

                            "This revenue code does not belong to this category."

                        );


                    }

                }


            }





            /*
            |--------------------------------------------------------------------------
            | Prevent overlapping category ranges
            |--------------------------------------------------------------------------
            */

            if (
                $this->filled('start_code') &&
                $this->filled('end_code')
            ) {


                $exists = RevenueCategory::query()

                    ->where(
                        'revenue_domain',
                        $this->input(
                            'revenue_domain',
                            $category->revenue_domain
                        )
                    )

                    ->where(
                        'id',
                        '!=',
                        $category->id
                    )

                    ->where(function($query){


                        $query

                            ->whereBetween(
                                'start_code',
                                [
                                    $this->start_code,
                                    $this->end_code
                                ]
                            )

                            ->orWhereBetween(
                                'end_code',
                                [
                                    $this->start_code,
                                    $this->end_code
                                ]
                            )

                            ->orWhere(function($query){

                                $query

                                    ->where(
                                        'start_code',
                                        '<=',
                                        $this->start_code
                                    )

                                    ->where(
                                        'end_code',
                                        '>=',
                                        $this->end_code
                                    );

                            });


                    })

                    ->exists();



                if ($exists) {


                    $validator->errors()->add(

                        'start_code',

                        'The selected revenue range overlaps another category.'

                    );


                }

            }





            /*
            |--------------------------------------------------------------------------
            | Validate codes
            |--------------------------------------------------------------------------
            */

            foreach ($this->input('codes', []) as $index => $code) {


                if (!isset($code['code'])) {
                    continue;
                }




                /*
                |--------------------------------------------------------------------------
                | Prevent duplicate codes
                |--------------------------------------------------------------------------
                */

                $exists = RevenueCode::query()

                    ->where(
                        'code',
                        $code['code']
                    )

                    ->when(
                        !empty($code['id']),

                        function($query) use ($code){

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
                | Code must be inside range
                |--------------------------------------------------------------------------
                */

                if (

                    $this->filled('start_code') &&

                    $this->filled('end_code') &&

                    is_numeric($code['code'])

                ) {


                    $number = (int)$code['code'];



                    if (

                        $number < $this->start_code ||

                        $number > $this->end_code

                    ) {


                        $validator->errors()->add(

                            "codes.$index.code",

                            "Revenue code must be between {$this->start_code} and {$this->end_code}."

                        );


                    }

                }


            }


        });

    }






    /**
     * Normalize input
     */
    protected function prepareForValidation(): void
    {

        $data = [];



        if ($this->has('name')) {

            $data['name'] = trim($this->name);

        }



        if ($this->has('description')) {

            $data['description'] =
                $this->description
                ? trim($this->description)
                : null;

        }



        if ($this->has('is_active')) {


            $data['is_active'] = filter_var(

                $this->is_active,

                FILTER_VALIDATE_BOOLEAN,

                FILTER_NULL_ON_FAILURE

            );


        }




        if ($this->has('codes')) {


            $data['codes'] = collect($this->codes)

                ->map(function($code){


                    return [

                        'id' => $code['id'] ?? null,


                        'code' => isset($code['code'])

                            ? trim((string)$code['code'])

                            : null,


                        'name' => isset($code['name'])

                            ? trim($code['name'])

                            : null,


                        'description' => isset($code['description'])

                            ? trim($code['description'])

                            : null,


                        'is_active' => filter_var(

                            $code['is_active'] ?? true,

                            FILTER_VALIDATE_BOOLEAN

                        ),

                    ];


                })

                ->values()

                ->all();


        }



        $this->merge($data);

    }





    public function messages(): array
    {
        return [

            'name.unique'
                => 'A category with this name already exists.',


            'codes.*.code.distinct'
                => 'Duplicate revenue codes are not allowed.',


            'codes.*.id.uuid'
                => 'Invalid revenue code identifier.',


            'end_code.gte'
                => 'End code must be greater than or equal to start code.',

        ];
    }
}