<?php

namespace App\Http\Requests\PenaltyDiscount;

use App\Models\PenaltyDiscountRequest;
use Illuminate\Foundation\Http\FormRequest;

class SubmitPenaltyDiscountRequest extends FormRequest
{
    public function authorize(): bool
    {
        $request = $this->route('penaltyDiscountRequest');

        return $request instanceof PenaltyDiscountRequest
            && (
                $this->user()?->can('submit', $request)
                ?? false
            );
    }

    public function rules(): array
    {
        return [];
    }
}
