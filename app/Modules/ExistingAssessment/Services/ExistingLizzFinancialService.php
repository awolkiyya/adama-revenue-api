<?php

namespace App\Modules\ExistingAssessment\Services;

use Illuminate\Validation\ValidationException;

class ExistingLizzFinancialService
{
    /**
     * Validate and calculate the historical financial position.
     *
     * @return array{
     *     original_obligation: float,
     *     amount_already_paid: float,
     *     outstanding_balance: float
     * }
     */
    public function calculate(
        mixed $originalObligation,
        mixed $amountAlreadyPaid
    ): array {
        if (
            ! is_numeric($originalObligation)
            || ! is_finite((float) $originalObligation)
            || (float) $originalObligation < 0
        ) {
            throw ValidationException::withMessages([
                'original_obligation' => [
                    'Original obligation must be a valid non-negative amount.',
                ],
            ]);
        }

        if (
            ! is_numeric($amountAlreadyPaid)
            || ! is_finite((float) $amountAlreadyPaid)
            || (float) $amountAlreadyPaid < 0
        ) {
            throw ValidationException::withMessages([
                'amount_already_paid' => [
                    'Amount already paid must be a valid non-negative amount.',
                ],
            ]);
        }

        $original = round((float) $originalObligation, 2);
        $paid = round((float) $amountAlreadyPaid, 2);

        if ($paid > $original) {
            throw ValidationException::withMessages([
                'amount_already_paid' => [
                    'Amount already paid cannot be greater than the original obligation.',
                ],
            ]);
        }

        return [
            'original_obligation' => $original,
            'amount_already_paid' => $paid,
            'outstanding_balance' => round($original - $paid, 2),
        ];
    }
}