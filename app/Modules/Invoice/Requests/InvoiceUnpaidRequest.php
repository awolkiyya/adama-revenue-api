<?php

/*
|--------------------------------------------------------------------------
| 1. REQUEST  -  app/Modules/Invoice/Requests/InvoiceUnpaidRequest.php
|--------------------------------------------------------------------------
|
| Copy the authorize() logic from InvoiceIndexRequest if it has any.
|
*/

namespace App\Modules\Invoice\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InvoiceUnpaidRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ADAPT: same rule as InvoiceIndexRequest
    }

    public function rules(): array
    {
        return [
            // The backend enforces the minimum so no client can pull everything.
            'search'   => ['required', 'string', 'min:2', 'max:100'],
            'page'     => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}