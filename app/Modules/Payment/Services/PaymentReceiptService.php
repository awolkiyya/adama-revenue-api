<?php

namespace App\Modules\Payment\Services;

use App\Models\Payment;
use App\Models\Receipt;
use App\Models\User;
use App\Services\DocumentSequenceService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PaymentReceiptService
{
    public function __construct(
        protected DocumentSequenceService $documentSequenceService,
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | Create Receipt
    |--------------------------------------------------------------------------
    |
    | Creates the official municipal receipt for a completed payment.
    |
    | This method:
    |
    | - only works for successful/completed payments
    | - is idempotent
    | - prevents duplicate receipts
    | - uses the central document sequence
    | - records which user issued the receipt
    |
    | It does NOT generate a PDF.
    |
    */

    public function create(
        Payment $payment,
        User $user,
    ): Receipt {
        return DB::transaction(function () use ($payment, $user): Receipt {

            /*
            |--------------------------------------------------------------------------
            | Lock Payment
            |--------------------------------------------------------------------------
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
            | Check Existing Receipt
            |--------------------------------------------------------------------------
            |
            | One completed payment = one official receipt.
            |
            */

            $existingReceipt = Receipt::query()
                ->where('payment_id', $lockedPayment->id)
                ->first();

            if ($existingReceipt) {
                return $existingReceipt;
            }

            /*
            |--------------------------------------------------------------------------
            | Generate Official Receipt Number
            |--------------------------------------------------------------------------
            |
            | Uses the centralized document numbering system.
            |
            */

            $receiptNumber = $this->documentSequenceService->generate(
                sequenceType: 'receipt',
            );

            /*
            |--------------------------------------------------------------------------
            | Create Receipt
            |--------------------------------------------------------------------------
            */

            return Receipt::query()->create([
                'payment_id' => $lockedPayment->id,
                'receipt_number' => $receiptNumber,
                'issued_by' => $user->id,
                'issued_at' => now(),
                'status' => 'ISSUED',
            ]);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Has Receipt
    |--------------------------------------------------------------------------
    */

    public function hasReceipt(
        Payment $payment,
    ): bool {
        return Receipt::query()
            ->where('payment_id', $payment->id)
            ->exists();
    }

    /*
    |--------------------------------------------------------------------------
    | Get Receipt
    |--------------------------------------------------------------------------
    |
    | Returns the official receipt for a successful payment.
    |
    | A missing receipt is treated as a data-integrity problem rather than
    | silently creating a new financial record during a read operation.
    |
    */

    public function getReceipt(
        Payment $payment,
    ): Receipt {
        /*
        |--------------------------------------------------------------------------
        | Payment Must Be Successful
        |--------------------------------------------------------------------------
        */

        if (!$payment->isSuccessful()) {
            throw new RuntimeException(
                'A receipt is only available for a successful payment.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Get Existing Receipt
        |--------------------------------------------------------------------------
        */

        $receipt = Receipt::query()
            ->where('payment_id', $payment->id)
            ->first();

        /*
        |--------------------------------------------------------------------------
        | Receipt Must Exist
        |--------------------------------------------------------------------------
        |
        | A successful payment should always have a receipt because receipt
        | creation happens during the payment completion/verification
        | transaction.
        |
        */

        if (!$receipt) {
            throw new RuntimeException(
                'The completed payment does not have an official receipt.'
            );
        }

        return $receipt;
    }

    /*
    |--------------------------------------------------------------------------
    | Get Receipt Number
    |--------------------------------------------------------------------------
    */

    public function getReceiptNumber(
        Payment $payment,
    ): ?string {
        return Receipt::query()
            ->where('payment_id', $payment->id)
            ->value('receipt_number');
    }
}