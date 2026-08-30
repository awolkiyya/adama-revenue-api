<?php

namespace App\Modules\Citizens\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CitizenImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }


    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'mimes:xlsx,xls,csv',
                'max:10240',
            ],
        ];
    }


    public function messages(): array
    {
        return [
            'file.required' => 'Import file is required.',
            'file.mimes' => 'Only Excel and CSV files are allowed.',
            'file.max' => 'File size must not exceed 10MB.',
        ];
    }
}