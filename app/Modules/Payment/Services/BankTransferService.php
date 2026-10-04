<?php

namespace App\Modules\Payment\Services;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentSchedule;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class BankTransferService
{
    /**
     * Record a bank-transfer payment.
     *
     * The payment is created as PENDING_VERIFICATION.
     *
     * IMPORTANT:
     *
     * A submitted bank receipt/evidence does NOT mean that
     * the municipality has received the money.
     *
     * The payment becomes official only after verify().
     */
    public function record(
        User $user,
        array $data,
        ?string $requestId = null,
    ): Payment {
        return DB::transaction(function () use (
            $user,
            $data,
            $requestId
        ) {
            /*
             * Lock the invoice while checking the outstanding balance.
             *
             * This prevents two simultaneous payment submissions from
             * both passing the balance validation.
             */
            $invoice = Invoice::query()
                ->whereKey($data['invoice_id'])
                ->lockForUpdate()
                ->first();

            if (!$invoice) {
                throw ValidationException::withMessages([
                    'invoice_id' => [
                        'The selected invoice does not exist.',
                    ],
                ]);
            }

            $this->validateInvoiceForPayment($invoice);

            $amount = $this->normalizeAmount(
                $data['amount'] ?? null
            );

            $outstanding = $this->calculateOutstandingAmount(
                $invoice
            );

            /*
             * Never allow the submitted payment to exceed the
             * current outstanding invoice balance.
             */
            if (bccomp($amount, $outstanding, 4) === 1) {
                throw ValidationException::withMessages([
                    'amount' => [
                        'The payment amount cannot exceed the '
                        . 'outstanding invoice balance.',
                    ],
                ]);
            }

            /*
             * Prevent duplicate bank-transfer submissions.
             *
             * The transfer reference should identify the bank
             * transaction.
             */
            $transferReference = trim(
                (string) ($data['transfer_reference'] ?? '')
            );

            if ($transferReference === '') {
                throw ValidationException::withMessages([
                    'transfer_reference' => [
                        'The bank transfer reference is required.',
                    ],
                ]);
            }

            $this->ensureTransferReferenceIsUnique(
                transferReference: $transferReference,
                bankName: $data['bank_name'] ?? null,
            );

            /*
             * Generate an internal payment reference.
             *
             * This is different from:
             *
             * - transfer_reference
             * - payment_number
             * - receipt_number
             */
            $paymentReference =
                $this->generatePaymentReference();

            $payment = new Payment();

            /*
             * Core payment information.
             */
            $payment->invoice_id = $invoice->id;
            $payment->amount = $amount;
            $payment->currency =
                $invoice->currency ?? 'ETB';

            $payment->method = 'BANK_TRANSFER';
            $payment->status = 'PENDING_VERIFICATION';

            $payment->payment_reference =
                $paymentReference;

            /*
             * Taxpayer/customer relationship should come from
             * the invoice, not from an arbitrary frontend customer_id.
             */
            if ($this->paymentHasAttribute('citizen_id')) {
                $payment->citizen_id =
                    $invoice->citizen_id ?? null;
            }

            /*
             * The authenticated user is the person who submitted/
             * recorded the payment in the municipal system.
             */
            if ($this->paymentHasAttribute('recorded_by_user_id')) {
                $payment->recorded_by_user_id = $user->id;
            }

            /*
             * Payer information.
             *
             * The payer may be different from the taxpayer.
             */
            if ($this->paymentHasAttribute('payer_name')) {
                $payment->payer_name =
                    $data['payer_name'] ?? null;
            }

            if ($this->paymentHasAttribute('payer_phone')) {
                $payment->payer_phone =
                    $data['payer_phone'] ?? null;
            }

            /*
             * Bank information.
             */
            if ($this->paymentHasAttribute('bank_name')) {
                $payment->bank_name =
                    trim((string) $data['bank_name']);
            }

            if ($this->paymentHasAttribute('bank_account_name')) {
                $payment->bank_account_name =
                    $data['bank_account_name'] ?? null;
            }

            if ($this->paymentHasAttribute('bank_account_number')) {
                $payment->bank_account_number =
                    $data['bank_account_number'] ?? null;
            }

            if ($this->paymentHasAttribute('transfer_reference')) {
                $payment->transfer_reference =
                    $transferReference;
            }

            if ($this->paymentHasAttribute('transfer_date')) {
                $payment->transfer_date =
                    $data['transfer_date'];
            }

            /*
             * Description.
             */
            if ($this->paymentHasAttribute('description')) {
                $payment->description =
                    $data['description'] ?? null;
            }

            /*
             * Additional metadata.
             */
            if ($this->paymentHasAttribute('metadata')) {
                $payment->metadata =
                    $data['metadata'] ?? [];
            }

            /*
             * Evidence/file handling.
             *
             * The request contains an uploaded file, but the actual
             * storage should ideally happen through a dedicated
             * PaymentEvidenceService / Laravel Storage layer.
             */
            if (
                isset($data['evidence'])
                && $data['evidence'] instanceof
                    \Illuminate\Http\UploadedFile
            ) {
                $payment->evidence_path =
                    $this->storeEvidence(
                        $data['evidence'],
                        $paymentReference,
                    );
            }

            $payment->save();

            Log::info(
                'Bank transfer payment submitted.',
                [
                    'request_id' => $requestId,
                    'payment_id' => $payment->getKey(),
                    'invoice_id' => $invoice->id,
                    'amount' => $amount,
                    'currency' => $payment->currency,
                    'bank_name' =>
                        $data['bank_name'] ?? null,
                    'transfer_reference' =>
                        $transferReference,
                    'recorded_by_user_id' => $user->id,
                ]
            );

            return $payment->fresh();
        });
    }

    /**
     * Verify and post a bank-transfer payment.
     *
     * This operation should only be available to an authorized
     * municipal revenue officer.
     *
     * Flow:
     *
     * PENDING_VERIFICATION
     *          ↓
     *       VERIFIED
     *          ↓
     *        POSTED
     */
    public function verify(
        string $paymentId,
        User $user,
        ?string $verificationNotes = null,
        ?string $requestId = null,
    ): Payment {
        return DB::transaction(function () use (
            $paymentId,
            $user,
            $verificationNotes,
            $requestId
        ) {
            /*
             * Lock payment so two officers cannot verify the same
             * payment simultaneously.
             */
            $payment = Payment::query()
                ->whereKey($paymentId)
                ->lockForUpdate()
                ->first();

            if (!$payment) {
                throw new ModelNotFoundException(
                    'Bank transfer payment not found.'
                );
            }

            $this->validateBankTransferPayment(
                $payment
            );

            /*
             * Idempotency.
             *
             * If the payment is already POSTED, do not post it again.
             */
            if ($this->isPosted($payment)) {
                return $payment->fresh();
            }

            /*
             * Only pending bank transfers can be verified.
             */
            if (!$this->isPendingVerification($payment)) {
                throw ValidationException::withMessages([
                    'payment' => [
                        'Only bank transfers pending verification '
                        . 'can be verified.',
                    ],
                ]);
            }

            /*
             * Authorize the officer.
             *
             * Your actual permission name should match the permissions
             * already defined in your municipal system.
             */
            $this->authorizeVerification($user);

            /*
             * Load and lock invoice.
             */
            $invoice = Invoice::query()
                ->whereKey($payment->invoice_id)
                ->lockForUpdate()
                ->first();

            if (!$invoice) {
                throw ValidationException::withMessages([
                    'invoice' => [
                        'The invoice associated with this payment '
                        . 'does not exist.',
                    ],
                ]);
            }

            $this->validateInvoiceForPayment(
                $invoice
            );

            $amount = $this->normalizeAmount(
                $payment->amount
            );

            $outstanding =
                $this->calculateOutstandingAmount(
                    $invoice
                );

            /*
             * Re-check the balance at verification time.
             *
             * The invoice could have changed since the bank transfer
             * was originally submitted.
             */
            if (bccomp($amount, $outstanding, 4) === 1) {
                throw ValidationException::withMessages([
                    'payment' => [
                        'The bank transfer amount now exceeds '
                        . 'the outstanding invoice balance.',
                    ],
                ]);
            }

            /*
             * This is the point where the municipal officer confirms
             * that the money has actually been received according to
             * the municipality's bank evidence.
             *
             * The service assumes that the officer has already reviewed:
             *
             * - bank name
             * - transfer reference
             * - amount
             * - transfer date
             * - beneficiary account
             * - bank statement / confirmation
             * - submitted evidence
             */
            $payment->status = 'VERIFIED';

            if (
                $this->paymentHasAttribute(
                    'verified_by_user_id'
                )
            ) {
                $payment->verified_by_user_id =
                    $user->id;
            }

            if (
                $this->paymentHasAttribute(
                    'verified_at'
                )
            ) {
                $payment->verified_at = now();
            }

            if (
                $this->paymentHasAttribute(
                    'verification_notes'
                )
            ) {
                $payment->verification_notes =
                    $verificationNotes;
            }

            /*
             * Immediately post after successful verification.
             *
             * This means VERIFIED and POSTED are both represented
             * in the audit history/fields if your schema supports it,
             * while the final payment status becomes POSTED.
             */
            $payment->status = 'POSTED';

            if (
                $this->paymentHasAttribute(
                    'posted_by_user_id'
                )
            ) {
                $payment->posted_by_user_id =
                    $user->id;
            }

            if (
                $this->paymentHasAttribute(
                    'posted_at'
                )
            ) {
                $payment->posted_at = now();
            }

            /*
             * Generate official payment number.
             */
            if (
                $this->paymentHasAttribute(
                    'payment_number'
                )
                && empty($payment->payment_number)
            ) {
                $payment->payment_number =
                    $this->generatePaymentNumber();
            }

            /*
             * Generate official receipt number.
             */
            if (
                $this->paymentHasAttribute(
                    'receipt_number'
                )
                && empty($payment->receipt_number)
            ) {
                $payment->receipt_number =
                    $this->generateReceiptNumber();
            }

            $payment->save();

            /*
             * Update invoice accounting.
             */
            $this->applyPaymentToInvoice(
                invoice: $invoice,
                amount: $amount,
            );

            /*
             * Update payment schedule when this payment is explicitly
             * associated with an installment.
             */
            $this->applyPaymentToSchedule(
                payment: $payment,
                amount: $amount,
            );

            Log::info(
                'Bank transfer payment verified and posted.',
                [
                    'request_id' => $requestId,
                    'payment_id' => $payment->getKey(),
                    'invoice_id' => $invoice->id,
                    'amount' => $amount,
                    'transfer_reference' =>
                        $payment->transfer_reference ?? null,
                    'verified_by_user_id' => $user->id,
                ]
            );

            return $payment->fresh();
        });
    }

    /**
     * Reject a bank-transfer payment.
     *
     * Rejection never deletes the payment.
     */
    public function reject(
        string $paymentId,
        User $user,
        string $reason,
        ?string $requestId = null,
    ): Payment {
        return DB::transaction(function () use (
            $paymentId,
            $user,
            $reason,
            $requestId
        ) {
            $payment = Payment::query()
                ->whereKey($paymentId)
                ->lockForUpdate()
                ->first();

            if (!$payment) {
                throw new ModelNotFoundException(
                    'Bank transfer payment not found.'
                );
            }

            $this->validateBankTransferPayment(
                $payment
            );

            if ($this->isPosted($payment)) {
                throw ValidationException::withMessages([
                    'payment' => [
                        'A posted bank transfer cannot be rejected. '
                        . 'Use the payment reversal process instead.',
                    ],
                ]);
            }

            if (!$this->isPendingVerification($payment)) {
                throw ValidationException::withMessages([
                    'payment' => [
                        'Only bank transfers pending verification '
                        . 'can be rejected.',
                    ],
                ]);
            }

            $this->authorizeVerification($user);

            $payment->status = 'REJECTED';

            if (
                $this->paymentHasAttribute(
                    'verified_by_user_id'
                )
            ) {
                $payment->verified_by_user_id =
                    $user->id;
            }

            if (
                $this->paymentHasAttribute(
                    'verified_at'
                )
            ) {
                $payment->verified_at = now();
            }

            if (
                $this->paymentHasAttribute(
                    'verification_notes'
                )
            ) {
                $payment->verification_notes =
                    $reason;
            }

            $payment->save();

            Log::warning(
                'Bank transfer payment rejected.',
                [
                    'request_id' => $requestId,
                    'payment_id' => $payment->getKey(),
                    'invoice_id' => $payment->invoice_id,
                    'rejected_by_user_id' => $user->id,
                    'reason' => $reason,
                ]
            );

            return $payment->fresh();
        });
    }

    /**
     * Retrieve one bank-transfer payment.
     */
    public function find(
        string $paymentId,
        User $user,
    ): Payment {
        $payment = Payment::query()
            ->with([
                'invoice',
            ])
            ->whereKey($paymentId)
            ->first();

        if (!$payment) {
            throw new ModelNotFoundException(
                'Bank transfer payment not found.'
            );
        }

        $this->validateBankTransferPayment(
            $payment
        );

        $this->authorizeView(
            payment: $payment,
            user: $user,
        );

        return $payment;
    }

    /**
     * Get payments waiting for verification.
     */
    public function pending(
        User $user,
        int $perPage = 20,
    ): LengthAwarePaginator {
        $this->authorizeVerification(
            $user
        );

        return Payment::query()
            ->with([
                'invoice',
            ])
            ->where('method', 'BANK_TRANSFER')
            ->where('status', 'PENDING_VERIFICATION')
            ->latest('created_at')
            ->paginate(
                min(max($perPage, 1), 100)
            );
    }

    /**
     * Validate invoice before accepting payment.
     */
    protected function validateInvoiceForPayment(
        Invoice $invoice
    ): void {
        $status = strtoupper(
            (string) (
                $invoice->status?->value
                ?? $invoice->status
                ?? ''
            )
        );

        if (in_array($status, [
            'CANCELLED',
            'VOID',
        ], true)) {
            throw ValidationException::withMessages([
                'invoice_id' => [
                    'This invoice cannot receive a payment because '
                    . 'it is ' . strtolower($status) . '.',
                ],
            ]);
        }

        $outstanding =
            $this->calculateOutstandingAmount(
                $invoice
            );

        if (bccomp($outstanding, '0.0000', 4) <= 0) {
            throw ValidationException::withMessages([
                'invoice_id' => [
                    'This invoice has no outstanding balance.',
                ],
            ]);
        }
    }

    /**
     * Validate that the payment is a bank transfer.
     */
    protected function validateBankTransferPayment(
        Payment $payment
    ): void {
        $method = strtoupper(
            (string) (
                $payment->method?->value
                ?? $payment->method
                ?? ''
            )
        );

        if ($method !== 'BANK_TRANSFER') {
            throw ValidationException::withMessages([
                'payment' => [
                    'The selected payment is not a bank transfer.',
                ],
            ]);
        }
    }

    /**
     * Authorize bank-transfer verification.
     *
     * Replace the permission name with your exact Spatie permission.
     */
    protected function authorizeVerification(
        User $user
    ): void {
        /*
         * Recommended permission:
         *
         * BANK_TRANSFER_PAYMENTS_VERIFY
         *
         * If your permission names are already established, use
         * that exact permission here.
         */

        if (
            method_exists($user, 'can')
            && !$user->can(
                'BANK_TRANSFER_PAYMENTS_VERIFY'
            )
        ) {
            abort(
                403,
                'You are not authorized to verify bank transfer payments.'
            );
        }
    }

    /**
     * Authorize viewing a bank-transfer payment.
     */
    protected function authorizeView(
        Payment $payment,
        User $user
    ): void {
        if (
            method_exists($user, 'can')
            && !$user->can(
                'BANK_TRANSFER_PAYMENTS_VIEW'
            )
        ) {
            abort(
                403,
                'You are not authorized to view bank transfer payments.'
            );
        }
    }

    /**
     * Make sure the same bank transaction is not submitted twice.
     */
    protected function ensureTransferReferenceIsUnique(
        string $transferReference,
        ?string $bankName = null,
    ): void {
        $query = Payment::query()
            ->where('method', 'BANK_TRANSFER')
            ->where(
                'transfer_reference',
                $transferReference
            );

        /*
         * If bank_name exists, use it to make the uniqueness
         * check more precise.
         */
        if (
            $bankName !== null
            && $this->paymentHasAttribute('bank_name')
        ) {
            $query->where(
                'bank_name',
                trim($bankName)
            );
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'transfer_reference' => [
                    'This bank transfer reference has already '
                    . 'been submitted.',
                ],
            ]);
        }
    }

    /**
     * Calculate outstanding invoice amount.
     *
     * Only POSTED payments reduce the official balance.
     */
    protected function calculateOutstandingAmount(
        Invoice $invoice
    ): string {
        if (
            $this->invoiceHasAttribute(
                $invoice,
                'balance_due'
            )
            && $invoice->balance_due !== null
        ) {
            return $this->normalizeAmount(
                $invoice->balance_due
            );
        }

        if (
            $this->invoiceHasAttribute(
                $invoice,
                'outstanding_amount'
            )
            && $invoice->outstanding_amount !== null
        ) {
            return $this->normalizeAmount(
                $invoice->outstanding_amount
            );
        }

        $invoiceTotal =
            $this->invoiceTotal($invoice);

        $paid = Payment::query()
            ->where(
                'invoice_id',
                $invoice->getKey()
            )
            ->where('status', 'POSTED')
            ->sum('amount');

        $paid =
            $this->normalizeAmount($paid);

        $outstanding = bcsub(
            $invoiceTotal,
            $paid,
            4
        );

        return bccomp(
            $outstanding,
            '0.0000',
            4
        ) < 0
            ? '0.0000'
            : $outstanding;
    }

    /**
     * Determine invoice total.
     */
    protected function invoiceTotal(
        Invoice $invoice
    ): string {
        foreach ([
            'total_amount',
            'grand_total',
            'amount',
            'total',
        ] as $column) {
            if (
                $this->invoiceHasAttribute(
                    $invoice,
                    $column
                )
                && $invoice->{$column} !== null
            ) {
                return $this->normalizeAmount(
                    $invoice->{$column}
                );
            }
        }

        throw new \RuntimeException(
            'Unable to determine the invoice total amount.'
        );
    }

    /**
     * Apply posted payment to invoice.
     */
    protected function applyPaymentToInvoice(
        Invoice $invoice,
        string $amount,
    ): void {
        if (
            $this->invoiceHasAttribute(
                $invoice,
                'paid_amount'
            )
        ) {
            $currentPaid =
                $this->normalizeAmount(
                    $invoice->paid_amount ?? 0
                );

            $invoice->paid_amount =
                bcadd(
                    $currentPaid,
                    $amount,
                    4
                );
        }

        if (
            $this->invoiceHasAttribute(
                $invoice,
                'balance_due'
            )
        ) {
            $currentBalance =
                $this->normalizeAmount(
                    $invoice->balance_due
                );

            $newBalance =
                bcsub(
                    $currentBalance,
                    $amount,
                    4
                );

            $invoice->balance_due =
                bccomp(
                    $newBalance,
                    '0.0000',
                    4
                ) < 0
                    ? '0.0000'
                    : $newBalance;
        }

        if (
            $this->invoiceHasAttribute(
                $invoice,
                'outstanding_amount'
            )
        ) {
            $currentOutstanding =
                $this->normalizeAmount(
                    $invoice->outstanding_amount
                );

            $newOutstanding =
                bcsub(
                    $currentOutstanding,
                    $amount,
                    4
                );

            $invoice->outstanding_amount =
                bccomp(
                    $newOutstanding,
                    '0.0000',
                    4
                ) < 0
                    ? '0.0000'
                    : $newOutstanding;
        }

        /*
         * Determine final invoice status.
         */
        if (
            $this->invoiceHasAttribute(
                $invoice,
                'status'
            )
        ) {
            /*
             * Use the stored balance if available.
             */
            if (
                $this->invoiceHasAttribute(
                    $invoice,
                    'balance_due'
                )
            ) {
                $balance =
                    $this->normalizeAmount(
                        $invoice->balance_due
                    );
            } else {
                $balance =
                    $this->calculateOutstandingAmount(
                        $invoice
                    );
            }

            $invoice->status =
                bccomp(
                    $balance,
                    '0.0000',
                    4
                ) <= 0
                    ? 'PAID'
                    : 'PARTIALLY_PAID';
        }

        $invoice->save();
    }

    /**
     * Apply payment to explicitly linked payment schedule.
     */
    protected function applyPaymentToSchedule(
        Payment $payment,
        string $amount,
    ): void {
        if (
            !$this->paymentHasAttribute(
                'payment_schedule_id'
            )
            || empty($payment->payment_schedule_id)
        ) {
            return;
        }

        $schedule =
            PaymentSchedule::query()
                ->whereKey(
                    $payment->payment_schedule_id
                )
                ->lockForUpdate()
                ->first();

        if (!$schedule) {
            throw ValidationException::withMessages([
                'payment_schedule_id' => [
                    'The payment schedule associated with '
                    . 'this payment could not be found.',
                ],
            ]);
        }

        $amountDue =
            $this->normalizeAmount(
                $schedule->amount_due
            );

        $amountPaid =
            $this->normalizeAmount(
                $schedule->amount_paid ?? 0
            );

        $newPaid =
            bcadd(
                $amountPaid,
                $amount,
                4
            );

        /*
         * Never allow schedule overpayment.
         */
        if (
            bccomp(
                $newPaid,
                $amountDue,
                4
            ) >= 0
        ) {
            $schedule->amount_paid =
                $amountDue;

            $schedule->status =
                'PAID';

            $schedule->paid_at =
                now();
        } else {
            $schedule->amount_paid =
                $newPaid;

            $schedule->status =
                'PARTIALLY_PAID';
        }

        $schedule->save();
    }

    /**
     * Check POSTED state.
     */
    protected function isPosted(
        Payment $payment
    ): bool {
        return strtoupper(
            (string) (
                $payment->status?->value
                ?? $payment->status
                ?? ''
            )
        ) === 'POSTED';
    }

    /**
     * Check PENDING_VERIFICATION state.
     */
    protected function isPendingVerification(
        Payment $payment
    ): bool {
        return strtoupper(
            (string) (
                $payment->status?->value
                ?? $payment->status
                ?? ''
            )
        ) === 'PENDING_VERIFICATION';
    }

    /**
     * Store bank-transfer evidence.
     *
     * For a larger system, move this to a dedicated
     * PaymentEvidenceService.
     */
    protected function storeEvidence(
        \Illuminate\Http\UploadedFile $file,
        string $paymentReference,
    ): string {
        return $file->store(
            'payment-evidence/bank-transfers/' .
            now()->format('Y/m'),
            'private'
        );
    }

    /**
     * Generate internal payment reference.
     */
    protected function generatePaymentReference(): string
    {
        return 'PAY-' .
            strtoupper(
                Str::ulid()->toBase32()
            );
    }

    /**
     * Generate official payment number.
     *
     * Replace this with your DocumentSequenceService when available.
     */
    protected function generatePaymentNumber(): string
    {
        return 'PAY-' .
            now()->format('Y') .
            '-' .
            strtoupper(
                Str::ulid()->toBase32()
            );
    }

    /**
     * Generate official receipt number.
     *
     * Replace this with your centralized receipt sequence.
     */
    protected function generateReceiptNumber(): string
    {
        return 'RCT-' .
            now()->format('Y') .
            '-' .
            strtoupper(
                Str::ulid()->toBase32()
            );
    }

    /**
     * Normalize monetary values without relying on float arithmetic.
     */
    protected function normalizeAmount(
        mixed $amount
    ): string {
        if (
            $amount === null
            || $amount === ''
        ) {
            throw ValidationException::withMessages([
                'amount' => [
                    'A payment amount is required.',
                ],
            ]);
        }

        $value = trim(
            (string) $amount
        );

        if (!is_numeric($value)) {
            throw ValidationException::withMessages([
                'amount' => [
                    'The payment amount must be a valid number.',
                ],
            ]);
        }

        if (
            bccomp(
                $value,
                '0',
                4
            ) <= 0
        ) {
            throw ValidationException::withMessages([
                'amount' => [
                    'The payment amount must be greater than zero.',
                ],
            ]);
        }

        /*
         * Keep four decimal places because your payment and
         * assessment system uses decimal(18,4).
         */
        return number_format(
            (float) $value,
            4,
            '.',
            ''
        );
    }

    /**
     * Check payment attribute existence.
     */
    protected function paymentHasAttribute(
        string $attribute
    ): bool {
        $payment = new Payment();

        return array_key_exists(
            $attribute,
            $payment->getAttributes()
        ) || in_array(
            $attribute,
            $payment->getFillable(),
            true
        );
    }

    /**
     * Check invoice attribute existence.
     */
    protected function invoiceHasAttribute(
        Invoice $invoice,
        string $attribute
    ): bool {
        return array_key_exists(
            $attribute,
            $invoice->getAttributes()
        ) || in_array(
            $attribute,
            $invoice->getFillable(),
            true
        );
    }
}