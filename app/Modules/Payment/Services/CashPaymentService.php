<?php

namespace App\Modules\Payment\Services;

use App\Enums\PaymentMethod;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentSchedule;
use App\Models\User;
use App\Services\DocumentSequenceService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CashPaymentService
{
    public function __construct(
        protected DocumentSequenceService $documentSequenceService,
    ) {
    }

    /**
     * Record a cash payment.
     *
     * The collector records the cash received, but the payment
     * is not financially finalized yet.
     *
     * Flow:
     *
     *     Invoice
     *        ↓
     *     Validate invoice
     *        ↓
     *     Validate outstanding amount
     *        ↓
     *     Create CASH payment
     *        ↓
     *     AWAITING_VERIFICATION
     *
     * The final confirmation/posting is handled by post().
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
             * Lock the invoice so two collectors cannot simultaneously
             * create payments against the same outstanding balance.
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

            if (bccomp($amount, $outstanding, 4) === 1) {
                throw ValidationException::withMessages([
                    'amount' => [
                        'The payment amount cannot exceed the outstanding invoice balance.',
                    ],
                ]);
            }

            /*
             * The collector is always resolved from the authenticated
             * backend user.
             *
             * The frontend must never provide collector identity.
             */
            $collectorId = $user->id;

            /*
             * Cash payments use CASH as both:
             *
             * payment_method   = CASH
             * payment_provider = CASH
             *
             * The provider is derived from the payment method using
             * the existing PaymentProvider domain mapping.
             */
            $paymentMethod = PaymentMethod::CASH;

            $paymentMethodValue =
                $paymentMethod instanceof \BackedEnum
                    ? (string) $paymentMethod->value
                    : (string) $paymentMethod;

            $paymentProvider =
                PaymentProvider::fromPaymentMethod(
                    $paymentMethod
                );

            /*
             * Generate the internal transaction reference.
             *
             * This is separate from payment_number.
             */
            $paymentReference =
                $this->generatePaymentReference();

            /*
             * Generate the official payment number immediately.
             *
             * payment_number is NOT the receipt number.
             *
             * The payment exists as a transaction even while it is
             * awaiting verification, so payment_number must be created
             * during record(), not during post().
             *
             * Example:
             *
             * PAY-2018-000001
             */
            $paymentNumber =
                $this->documentSequenceService->generate(
                    sequenceType: 'payment',
                    prefix: 'PAY',
                );

            /*
             * Create the payment.
             */
            $payment = new Payment();

            $payment->invoice_id = $invoice->id;

            /*
             * Use the invoice's authoritative citizen/customer ID
             * when the payment table supports it.
             */
            if ($this->paymentHasAttribute('citizen_id')) {
                $payment->citizen_id =
                    $invoice->citizen_id ?? null;
            }

            /*
             * payment_number is required by the database.
             */
            if ($this->paymentHasAttribute('payment_number')) {
                $payment->payment_number =
                    $paymentNumber;
            }

            $payment->amount = $amount;

            $payment->currency =
                $invoice->currency ?? 'ETB';

            /*
             * Payment method.
             */
            if ($this->paymentHasAttribute('payment_method')) {
                $payment->payment_method =
                    $paymentMethodValue;
            } elseif ($this->paymentHasAttribute('method')) {
                $payment->method =
                    $paymentMethodValue;
            }

            /*
             * Payment provider.
             *
             * For cash:
             *
             *     payment_provider = CASH
             */
            if (
                $this->paymentHasAttribute(
                    'payment_provider'
                )
            ) {
                $payment->payment_provider =
                    $paymentProvider->value;
            }

            /*
             * A newly recorded cash payment waits for verification.
             */
            $payment->status =
                PaymentStatus::AWAITING_VERIFICATION;

            /*
             * Store the internal transaction reference.
             */
            if (
                $this->paymentHasAttribute(
                    'payment_reference'
                )
            ) {
                $payment->payment_reference =
                    $paymentReference;
            }

            /*
             * Some schemas use transaction_reference.
             */
            if (
                $this->paymentHasAttribute(
                    'transaction_reference'
                )
            ) {
                $payment->transaction_reference =
                    $paymentReference;
            }

            /*
             * The authenticated user is the person who received/
             * recorded the cash.
             *
             * Prefer received_by because that is the payment-domain
             * field used for the cash collector.
             */
            if (
                $this->paymentHasAttribute(
                    'received_by'
                )
            ) {
                $payment->received_by =
                    $collectorId;
            } elseif (
                $this->paymentHasAttribute(
                    'recorded_by_user_id'
                )
            ) {
                $payment->recorded_by_user_id =
                    $collectorId;
            } elseif (
                $this->paymentHasAttribute(
                    'collector_id'
                )
            ) {
                $payment->collector_id =
                    $collectorId;
            }

            /*
             * Record the collection time automatically.
             */
            if (
                $this->paymentHasAttribute(
                    'payment_date'
                )
            ) {
                $payment->payment_date = now();
            }

            /*
             * Optional description.
             */
            if (
                $this->paymentHasAttribute(
                    'description'
                )
            ) {
                $payment->description =
                    $data['description'] ?? null;
            }

            /*
             * Optional metadata.
             */
            if (
                $this->paymentHasAttribute(
                    'metadata'
                )
            ) {
                $payment->metadata =
                    $data['metadata'] ?? [];
            }

            /*
             * Do NOT generate receipt_number here.
             *
             * Receipt is generated only after verification/posting.
             */
            $payment->save();

            Log::info(
                'Cash payment recorded and awaiting verification.',
                [
                    'request_id' => $requestId,
                    'payment_id' => $payment->getKey(),
                    'payment_number' =>
                        $payment->payment_number,
                    'invoice_id' => $invoice->id,
                    'amount' => $amount,
                    'currency' => $payment->currency,
                    'payment_method' =>
                        $paymentMethodValue,
                    'payment_provider' =>
                        $paymentProvider->value,
                    'status' =>
                        PaymentStatus::AWAITING_VERIFICATION->value,
                    'received_by' => $collectorId,
                ]
            );

            return $payment->fresh();
        });
    }

    /**
     * Verify and post a cash payment.
     *
     * This is the financial finalization event.
     *
     * Flow:
     *
     *     AWAITING_VERIFICATION
     *              ↓
     *       verify physical cash
     *              ↓
     *             PAID
     *              ↓
     *       update invoice
     *              ↓
     *       update schedule
     *              ↓
     *       generate receipt
     */
    public function post(
        string $paymentId,
        User $user,
        ?string $requestId = null,
    ): Payment {
        return DB::transaction(function () use (
            $paymentId,
            $user,
            $requestId
        ) {
            /*
             * Lock payment first to prevent duplicate posting.
             */
            $payment = Payment::query()
                ->whereKey($paymentId)
                ->lockForUpdate()
                ->first();

            if (!$payment) {
                throw new ModelNotFoundException(
                    'Cash payment not found.'
                );
            }

            $this->validateCashPayment($payment);

            /*
             * Idempotency:
             *
             * If already PAID, do not post it again.
             */
            if ($this->isPosted($payment)) {
                return $payment->fresh();
            }

            /*
             * Only payments awaiting verification may be finalized.
             */
            if (!$this->isAwaitingVerification($payment)) {
                throw ValidationException::withMessages([
                    'payment' => [
                        'Only cash payments awaiting verification can be posted.',
                    ],
                ]);
            }

            /*
             * Lock the invoice as well.
             */
            $invoice = Invoice::query()
                ->whereKey($payment->invoice_id)
                ->lockForUpdate()
                ->first();

            if (!$invoice) {
                throw ValidationException::withMessages([
                    'invoice' => [
                        'The invoice associated with this payment does not exist.',
                    ],
                ]);
            }

            $this->validateInvoiceForPayment($invoice);

            $paymentAmount = $this->normalizeAmount(
                $payment->amount
            );

            $outstanding =
                $this->calculateOutstandingAmount(
                    $invoice
                );

            /*
             * The invoice may have changed while this payment was
             * waiting for verification.
             */
            if (
                bccomp(
                    $paymentAmount,
                    $outstanding,
                    4
                ) === 1
            ) {
                throw ValidationException::withMessages([
                    'payment' => [
                        'The payment amount now exceeds the outstanding invoice balance.',
                    ],
                ]);
            }

            /*
             * Mark the payment as financially successful.
             */
            $payment->status =
                PaymentStatus::PAID;

            /*
             * Record who performed final verification/posting.
             */
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
             * Store verifier when supported by the schema.
             */
            if (
                $this->paymentHasAttribute(
                    'verified_by'
                )
            ) {
                $payment->verified_by =
                    $user->id;
            } elseif (
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

            /*
             * payment_number should already exist because it is
             * generated during record().
             *
             * This fallback is kept for older payment records that
             * may have been created before this service was updated.
             */
            if (
                $this->paymentHasAttribute(
                    'payment_number'
                )
                && empty($payment->payment_number)
            ) {
                $payment->payment_number =
                    $this->documentSequenceService->generate(
                        sequenceType: 'payment',
                        prefix: 'PAY',
                    );
            }

            /*
             * Generate receipt number only after successful
             * verification/posting.
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
             * Apply the payment to invoice accounting.
             */
            $this->applyPaymentToInvoice(
                invoice: $invoice,
                amount: $paymentAmount,
            );

            /*
             * Apply payment to an installment/payment schedule
             * when the payment explicitly identifies one.
             */
            $this->applyPaymentToSchedule(
                payment: $payment,
                invoice: $invoice,
                amount: $paymentAmount,
            );

            Log::info(
                'Cash payment verified and posted.',
                [
                    'request_id' => $requestId,
                    'payment_id' => $payment->getKey(),
                    'payment_number' =>
                        $payment->payment_number,
                    'receipt_number' =>
                        $payment->receipt_number ?? null,
                    'invoice_id' => $invoice->id,
                    'amount' => $paymentAmount,
                    'status' =>
                        PaymentStatus::PAID->value,
                    'posted_by_user_id' =>
                        $user->id,
                ]
            );

            return $payment->fresh();
        });
    }

    /**
     * Find a cash payment.
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
                'Cash payment not found.'
            );
        }

        $this->validateCashPayment($payment);

        $this->authorizeView(
            payment: $payment,
            user: $user,
        );

        return $payment;
    }

    /**
     * Validate that the invoice can receive a payment.
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

        $blockedStatuses = [
            'CANCELLED',
            'VOID',
        ];

        if (
            in_array(
                $status,
                $blockedStatuses,
                true
            )
        ) {
            throw ValidationException::withMessages([
                'invoice_id' => [
                    'This invoice cannot receive a payment because it is '
                    . strtolower($status) . '.',
                ],
            ]);
        }

        $outstanding =
            $this->calculateOutstandingAmount(
                $invoice
            );

        if (
            bccomp(
                $outstanding,
                '0.0000',
                4
            ) <= 0
        ) {
            throw ValidationException::withMessages([
                'invoice_id' => [
                    'This invoice has no outstanding balance.',
                ],
            ]);
        }
    }

    /**
     * Validate that payment belongs to CASH channel.
     */
    protected function validateCashPayment(
        Payment $payment
    ): void {
        $method = '';

        if (
            $this->paymentHasAttribute(
                'payment_method'
            )
        ) {
            $method = strtoupper(
                (string) (
                    $payment->payment_method?->value
                    ?? $payment->payment_method
                    ?? ''
                )
            );
        } elseif (
            $this->paymentHasAttribute(
                'method'
            )
        ) {
            $method = strtoupper(
                (string) (
                    $payment->method?->value
                    ?? $payment->method
                    ?? ''
                )
            );
        }

        if ($method !== 'CASH') {
            throw ValidationException::withMessages([
                'payment' => [
                    'The selected payment is not a cash payment.',
                ],
            ]);
        }
    }

    /**
     * Calculate invoice outstanding amount.
     *
     * Only PAID payments are considered financially posted.
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

        /*
         * Fallback:
         *
         * invoice total - PAID payments.
         *
         * AWAITING_VERIFICATION payments are intentionally excluded.
         */
        $invoiceTotal =
            $this->invoiceTotal($invoice);

        $paid = Payment::query()
            ->where(
                'invoice_id',
                $invoice->getKey()
            )
            ->where(
                'status',
                PaymentStatus::PAID->value
            )
            ->sum('amount');

        $paid =
            $this->normalizeAmount($paid);

        $outstanding = bcsub(
            $invoiceTotal,
            $paid,
            4
        );

        if (
            bccomp(
                $outstanding,
                '0.0000',
                4
            ) < 0
        ) {
            return '0.0000';
        }

        return $outstanding;
    }

    /**
     * Get invoice total.
     */
    protected function invoiceTotal(
        Invoice $invoice
    ): string {
        $possibleColumns = [
            'total_amount',
            'grand_total',
            'amount',
            'total',
        ];

        foreach ($possibleColumns as $column) {
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
     * Update invoice after payment posting.
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

        if (
            $this->invoiceHasAttribute(
                $invoice,
                'status'
            )
        ) {
            $newOutstanding = null;

            if (
                $this->invoiceHasAttribute(
                    $invoice,
                    'balance_due'
                )
            ) {
                $newOutstanding =
                    $this->normalizeAmount(
                        $invoice->balance_due
                    );
            } elseif (
                $this->invoiceHasAttribute(
                    $invoice,
                    'outstanding_amount'
                )
            ) {
                $newOutstanding =
                    $this->normalizeAmount(
                        $invoice->outstanding_amount
                    );
            }

            if (
                $newOutstanding !== null
                && bccomp(
                    $newOutstanding,
                    '0.0000',
                    4
                ) <= 0
            ) {
                $invoice->status = 'PAID';
            } else {
                $invoice->status =
                    'PARTIALLY_PAID';
            }
        }

        if (
            $this->invoiceHasAttribute(
                $invoice,
                'paid_at'
            )
        ) {
            $invoiceStatus = strtoupper(
                (string) (
                    $invoice->status?->value
                    ?? $invoice->status
                    ?? ''
                )
            );

            if ($invoiceStatus === 'PAID') {
                $invoice->paid_at = now();
            }
        }

        $invoice->save();
    }

    /**
     * Apply payment to an installment/payment schedule.
     */
    protected function applyPaymentToSchedule(
        Payment $payment,
        Invoice $invoice,
        string $amount,
    ): void {
        if (
            $this->paymentHasAttribute(
                'payment_schedule_id'
            )
            && !empty(
                $payment->payment_schedule_id
            )
        ) {
            $schedule =
                PaymentSchedule::query()
                    ->whereKey(
                        $payment->payment_schedule_id
                    )
                    ->lockForUpdate()
                    ->first();

            if ($schedule) {
                $this->applyAmountToSchedule(
                    schedule: $schedule,
                    amount: $amount,
                );
            }

            return;
        }
    }

    /**
     * Apply amount to a specific payment schedule.
     */
    protected function applyAmountToSchedule(
        PaymentSchedule $schedule,
        string $amount,
    ): void {
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

            if (
                $this->scheduleHasAttribute(
                    $schedule,
                    'paid_at'
                )
            ) {
                $schedule->paid_at = now();
            }
        } else {
            $schedule->amount_paid =
                $newPaid;

            $schedule->status =
                'PARTIALLY_PAID';
        }

        $schedule->save();
    }

    /**
     * Authorize viewing a cash payment.
     *
     * Prefer Laravel Policies/Spatie permissions for the
     * actual authorization rules.
     */
    protected function authorizeView(
        Payment $payment,
        User $user,
    ): void {
        /*
         * Keep actual authorization in policies/permissions.
         */
    }

    /**
     * Determine whether payment is already financially posted.
     */
    protected function isPosted(
        Payment $payment
    ): bool {
        $status = strtoupper(
            (string) (
                $payment->status?->value
                ?? $payment->status
                ?? ''
            )
        );

        return $status ===
            PaymentStatus::PAID->value;
    }

    /**
     * Determine whether payment is awaiting verification.
     */
    protected function isAwaitingVerification(
        Payment $payment
    ): bool {
        $status = strtoupper(
            (string) (
                $payment->status?->value
                ?? $payment->status
                ?? ''
            )
        );

        return $status ===
            PaymentStatus::AWAITING_VERIFICATION->value;
    }

    /**
     * Payment method value.
     */
    protected function paymentMethodValue(): string
    {
        $method = PaymentMethod::CASH;

        return $method instanceof \BackedEnum
            ? (string) $method->value
            : (string) $method;
    }

    /**
     * Generate an internal transaction reference.
     *
     * This is NOT the official payment_number.
     */
    protected function generatePaymentReference(): string
    {
        return 'PAY-' .
            strtoupper(
                Str::ulid()->toBase32()
            );
    }

    /**
     * Generate a receipt number.
     *
     * Receipt number is generated only after payment verification.
     */
    protected function generateReceiptNumber(): string
    {
        return 'RCP-' .
            now()->format('Y') .
            '-' .
            strtoupper(
                Str::ulid()->toBase32()
            );
    }

    /**
     * Normalize monetary value.
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

        return number_format(
            (float) $value,
            4,
            '.',
            ''
        );
    }

    /**
     * Determine whether a payment model contains an attribute.
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
     * Determine whether an invoice contains an attribute.
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

    /**
     * Determine whether a payment schedule contains an attribute.
     */
    protected function scheduleHasAttribute(
        PaymentSchedule $schedule,
        string $attribute
    ): bool {
        return array_key_exists(
            $attribute,
            $schedule->getAttributes()
        ) || in_array(
            $attribute,
            $schedule->getFillable(),
            true
        );
    }
}