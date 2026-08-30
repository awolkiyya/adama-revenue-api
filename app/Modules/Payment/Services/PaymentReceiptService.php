<?php

namespace App\Modules\Payment\Services;

use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PaymentReceiptService
{
    /*
    |--------------------------------------------------------------------------
    | Receipt Prefix
    |--------------------------------------------------------------------------
    */

    private const RECEIPT_PREFIX = 'REC';

    /*
    |--------------------------------------------------------------------------
    | Create Receipt
    |--------------------------------------------------------------------------
    |
    | Creates the official municipal receipt identity for a successful
    | payment.
    |
    | This method does NOT generate a PDF.
    |
    | It is intentionally idempotent:
    |
    | - First call  -> creates receipt number
    | - Later calls -> returns existing receipt
    |
    */

    public function create(Payment $payment): Payment
    {
        return DB::transaction(function () use ($payment): Payment {

            /*
            |--------------------------------------------------------------------------
            | Lock Payment
            |--------------------------------------------------------------------------
            |
            | Important for webhook/concurrent-request safety.
            |
            | Two requests could theoretically try to create the receipt
            | at exactly the same time.
            |
            */

            $lockedPayment = Payment::query()
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->firstOrFail();

            /*
            |--------------------------------------------------------------------------
            | Payment Must Be Successful
            |--------------------------------------------------------------------------
            */

            if (!$lockedPayment->isSuccessful()) {
                throw new RuntimeException(
                    'A receipt can only be created for a successful payment.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Idempotency
            |--------------------------------------------------------------------------
            |
            | If a receipt already exists, do not create another one.
            |
            */

            if (
                filled(
                    $lockedPayment->receipt_number
                )
            ) {
                return $lockedPayment;
            }

            /*
            |--------------------------------------------------------------------------
            | Generate Receipt Number
            |--------------------------------------------------------------------------
            */

            $lockedPayment->forceFill([
                'receipt_number' =>
                    $this->generateReceiptNumber(),

                'receipt_issued_at' =>
                    now(),
            ])->save();

            /*
            |--------------------------------------------------------------------------
            | Return Fresh Payment
            |--------------------------------------------------------------------------
            */

            return $lockedPayment->refresh();
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Generate Receipt Number
    |--------------------------------------------------------------------------
    |
    | Format:
    |
    | REC-2026-00000001
    |
    | REC = Receipt
    | 2026 = Gregorian year
    | 00000001 = sequential receipt number
    |
    */

    protected function generateReceiptNumber(): string
    {
        /*
        |--------------------------------------------------------------------------
        | Generate Next Receipt Sequence
        |--------------------------------------------------------------------------
        |
        | For now we generate a random numeric suffix.
        |
        | This avoids depending on a separate receipt_sequences table.
        |
        */

        do {
            $number = sprintf(
                '%s-%s-%s',
                self::RECEIPT_PREFIX,
                now()->format('Y'),
                str_pad(
                    (string) random_int(
                        1,
                        99999999
                    ),
                    8,
                    '0',
                    STR_PAD_LEFT
                )
            );

        } while (
            Payment::query()
                ->where(
                    'receipt_number',
                    $number
                )
                ->exists()
        );

        return $number;
    }

    /*
    |--------------------------------------------------------------------------
    | Has Receipt
    |--------------------------------------------------------------------------
    */

    public function hasReceipt(
        Payment $payment
    ): bool {
        return filled(
            $payment->receipt_number
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Get Receipt Number
    |--------------------------------------------------------------------------
    */

    public function getReceiptNumber(
        Payment $payment
    ): ?string {
        return $payment->receipt_number;
    }

    /*
    |--------------------------------------------------------------------------
    | Get Receipt Payment
    |--------------------------------------------------------------------------
    |
    | Ensures the payment has a valid receipt before returning it.
    |
    */

    public function getReceipt(
        Payment $payment
    ): Payment {
        if (!$payment->isSuccessful()) {
            throw new RuntimeException(
                'A receipt is only available for a successful payment.'
            );
        }

        if (
            !filled(
                $payment->receipt_number
            )
        ) {
            return $this->create(
                $payment
            );
        }

        return $payment;
    }
}