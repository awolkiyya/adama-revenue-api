<?php

declare(strict_types=1);

namespace App\Modules\Payment\Services;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Jobs\SendPaymentNotificationJob;
use App\Models\CashPaymentDetail;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\DocumentSequenceService;
use App\Modules\Payment\Notifications\PaymentNotificationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class CashPaymentService
{
    protected const MONEY_SCALE = 4;

    protected const PAYABLE_INVOICE_STATUSES = [
        'ISSUED',
        'PARTIALLY_PAID',
        'OVERDUE',
    ];

    protected const PAYMENT_SOURCE = 'OFFICE_RECORDED';

    public function __construct(
        protected DocumentSequenceService $documentSequenceService,
        protected PaymentReceiptService $receiptService,
    ) {}

    /**
     * Record a cash payment as completed immediately.
     *
     * Cash reconciliation is a separate workflow and does not
     * determine whether the payment itself is completed.
     */
    public function record(
        User $user,
        array $data,
        ?string $requestId = null,
    ): Payment {
        $requestId = trim($requestId ?? '') ?: (string) Str::uuid();

        $this->authorizeCashRecording($user);

        // Validate before opening the transaction.
        $invoiceId = $data['invoice_id'] ?? null;
        $amount = $this->normalizeAmount($data['amount'] ?? null);

        if ($invoiceId === null || $invoiceId === '') {
            throw ValidationException::withMessages([
                'invoice_id' => ['An invoice is required.'],
            ]);
        }

        return DB::transaction(function () use (
            $user,
            $data,
            $requestId,
            $invoiceId,
            $amount,
        ): Payment {
            /*
             * 1. Idempotency.
             *
             * payments.request_id should be nullable and uniquely
             * indexed. A reused key cannot create a second payment.
             */
            $existingPayment = Payment::query()
                ->where('request_id', $requestId)
                ->first();

            if ($existingPayment !== null) {
                $this->validateIdempotentRetry(
                    payment: $existingPayment,
                    invoiceId: $invoiceId,
                    amount: $amount,
                );

                return $existingPayment->fresh([
                    'invoice',
                    'citizen',
                    'cashDetails.receivedBy',
                    'processedBy',
                    'verifiedBy',
                    'receipt.issuedBy',
                    'files',
                ]);
            }

            /*
             * 2. Lock the invoice so concurrent payments cannot
             * independently spend the same outstanding balance.
             */
            $invoice = Invoice::query()
                ->with('citizen')
                ->whereKey($invoiceId)
                ->lockForUpdate()
                ->first();

            if ($invoice === null) {
                throw ValidationException::withMessages([
                    'invoice_id' => ['The selected invoice does not exist.'],
                ]);
            }

            /*
             * 3. Validate invoice status and outstanding balance.
             */
            $this->validateInvoiceForPayment($invoice);

            $outstanding = $this->calculateOutstandingAmount($invoice);

            if (
                bccomp($amount, $outstanding, self::MONEY_SCALE) > 0
            ) {
                throw ValidationException::withMessages([
                    'amount' => [
                        'The payment amount cannot exceed the outstanding invoice balance.',
                    ],
                ]);
            }

            /*
             * 4. Generate server-controlled identifiers.
             */
            $paymentNumber = $this->documentSequenceService->generate(
                sequenceType: 'payment',
            );

            $transactionReference = $this->generateTransactionReference();

            /*
             * 5. Create the completed payment.
             *
             * The collector has already received the cash.
             * Reconciliation is not required to complete the payment.
             */
            $payment = new Payment();

            $payment->payment_number = $paymentNumber;
            $payment->request_id = $requestId;
            $payment->invoice_id = $invoice->getKey();
            $payment->citizen_id = $invoice->citizen_id;
            $payment->payment_method = PaymentMethod::CASH;
            $payment->payment_source = self::PAYMENT_SOURCE;
            $payment->status = PaymentStatus::COMPLETED;
            $payment->transaction_reference = $transactionReference;
            $payment->amount = $amount;
            $payment->currency = $invoice->currency ?? 'ETB';

            $payment->processed_by = $user->getKey();
            $payment->verified_by = $user->getKey();
            $payment->verified_at = now();

            $payment->payer_name = $invoice->citizen?->name;
            $payment->payer_email = $invoice->citizen?->email;
            $payment->payer_phone = $invoice->citizen?->phone;

            $payment->failure_reason = null;
            $payment->metadata = $data['metadata'] ?? null;

            $payment->save();

            /*
             * 6. Create cash-specific details.
             */
            $cashDetails = new CashPaymentDetail();

            $cashDetails->payment_id = $payment->getKey();
            $cashDetails->received_by = $user->getKey();
            $cashDetails->cashier_session_id = null;
            $cashDetails->cash_received_at = now();
            $cashDetails->notes = $data['notes'] ?? null;

            $cashDetails->save();

            /*
             * 7. Update authoritative invoice financial totals.
             */
            $this->applyPaymentToInvoice($invoice);

            /*
             * 8. Generate the official receipt.
             *
             * Receipt creation must use the actual implementation of
             * PaymentReceiptService and should enforce one receipt per
             * payment at the database level where appropriate.
             */
            $receipt = $this->receiptService->create(
                payment: $payment,
                user: $user,
            );

            /*
             * 9. Determine notification type from the updated invoice.
             */
            $invoice->refresh();

            $notificationType = bccomp(
                $this->normalizeMoney($invoice->balance_due),
                '0.0000',
                self::MONEY_SCALE,
            ) === 0
                ? PaymentNotificationService::PAYMENT_FULLY_PAID
                : PaymentNotificationService::PAYMENT_PARTIALLY_PAID;

            /*
             * 10. Dispatch only after the transaction commits.
             */
            $this->dispatchPaymentNotificationAfterCommit(
                payment: $payment,
                notificationType: $notificationType,
            );

            /*
             * 11. Audit the completed operation.
             */
            Log::info('Cash payment completed.', [
                'request_id' => $requestId,
                'payment_id' => $payment->getKey(),
                'payment_number' => $payment->payment_number,
                'transaction_reference' => $payment->transaction_reference,
                'receipt_id' => $receipt->getKey(),
                'invoice_id' => $invoice->getKey(),
                'amount' => $amount,
                'currency' => $payment->currency,
                'status' => PaymentStatus::COMPLETED->value,
                'processed_by' => $user->getKey(),
                'verified_by' => $user->getKey(),
                'invoice_paid_amount' => $invoice->paid_amount,
                'invoice_balance_due' => $invoice->balance_due,
                'invoice_status' => $this->invoiceStatus($invoice),
            ]);

            return $payment->fresh([
                'invoice',
                'citizen',
                'cashDetails.receivedBy',
                'processedBy',
                'verifiedBy',
                'receipt.issuedBy',
                'files',
            ]);
        }, 3);
    }

    /**
     * Retrieve an existing cash payment.
     */
    public function find(
        string $paymentId,
        User $user,
    ): Payment {
        $payment = Payment::query()
            ->with([
                'invoice',
                'citizen',
                'cashDetails.receivedBy',
                'processedBy',
                'verifiedBy',
                'receipt.issuedBy',
                'files',
            ])
            ->whereKey($paymentId)
            ->first();

        if ($payment === null) {
            throw (new ModelNotFoundException())
                ->setModel(Payment::class, [$paymentId]);
        }

        $this->validateCashPayment($payment);

        if (! $user->can('view', $payment)) {
            throw new AuthorizationException(
                'You are not authorized to view this payment.'
            );
        }

        return $payment;
    }

    /**
     * Authorize cash collection using the existing permission.
     */
    protected function authorizeCashRecording(User $user): void
    {
        if (! $user->can('payments.collect')) {
            throw new AuthorizationException(
                'You are not authorized to record cash payments.'
            );
        }
    }

    /**
     * Ensure an idempotent retry represents the same operation.
     */
    protected function validateIdempotentRetry(
        Payment $payment,
        mixed $invoiceId,
        string $amount,
    ): void {
        $method = strtoupper((string) (
            $payment->payment_method?->value
            ?? $payment->payment_method
            ?? ''
        ));

        if ($method !== PaymentMethod::CASH->value) {
            throw ValidationException::withMessages([
                'request_id' => [
                    'This request ID has already been used for another payment method.',
                ],
            ]);
        }

        if ((string) $payment->invoice_id !== (string) $invoiceId) {
            throw ValidationException::withMessages([
                'request_id' => [
                    'This request ID has already been used for a different invoice.',
                ],
            ]);
        }

        if (
            bccomp(
                $this->normalizeMoney($payment->amount),
                $amount,
                self::MONEY_SCALE,
            ) !== 0
        ) {
            throw ValidationException::withMessages([
                'request_id' => [
                    'This request ID has already been used with a different amount.',
                ],
            ]);
        }
    }

    /**
     * Validate invoice status and outstanding balance.
     */
    protected function validateInvoiceForPayment(Invoice $invoice): void
    {
        $status = $this->invoiceStatus($invoice);

        if (! in_array($status, self::PAYABLE_INVOICE_STATUSES, true)) {
            throw ValidationException::withMessages([
                'invoice_id' => [
                    match ($status) {
                        'DRAFT' => 'This invoice has not been issued and cannot receive a payment.',
                        'PAID' => 'This invoice has already been fully paid.',
                        'CANCELLED' => 'This invoice has been cancelled and cannot receive a payment.',
                        'VOID' => 'This invoice has been voided and cannot receive a payment.',
                        default => 'This invoice is not currently payable.',
                    },
                ],
            ]);
        }

        if (
            bccomp(
                $this->calculateOutstandingAmount($invoice),
                '0.0000',
                self::MONEY_SCALE,
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
     * Ensure that this service handles only cash payments.
     */
    protected function validateCashPayment(Payment $payment): void
    {
        $method = strtoupper((string) (
            $payment->payment_method?->value
            ?? $payment->payment_method
            ?? ''
        ));

        if ($method !== PaymentMethod::CASH->value) {
            throw ValidationException::withMessages([
                'payment' => [
                    'The selected payment is not a cash payment.',
                ],
            ]);
        }
    }

    /**
     * Calculate outstanding balance from the invoice total and completed
     * payments.
     */
    protected function calculateOutstandingAmount(Invoice $invoice): string
    {
        $invoiceTotal = $this->invoiceTotal($invoice);

        $completedAmount = $this->normalizeMoney(
            Payment::query()
                ->where('invoice_id', $invoice->getKey())
                ->where('status', PaymentStatus::COMPLETED->value)
                ->sum('amount')
        );

        if (
            bccomp(
                $completedAmount,
                $invoiceTotal,
                self::MONEY_SCALE,
            ) >= 0
        ) {
            return '0.0000';
        }

        return bcsub(
            $invoiceTotal,
            $completedAmount,
            self::MONEY_SCALE,
        );
    }

    /**
     * Read the authoritative invoice total.
     */
    protected function invoiceTotal(Invoice $invoice): string
    {
        if ($invoice->total_amount === null) {
            throw new RuntimeException(
                'Unable to determine the invoice total amount.'
            );
        }

        return $this->normalizeMoney($invoice->total_amount);
    }

    /**
     * Recalculate invoice financial totals using completed payments.
     */
    protected function applyPaymentToInvoice(Invoice $invoice): void
    {
        $invoiceTotal = $this->invoiceTotal($invoice);

        $paidAmount = $this->normalizeMoney(
            Payment::query()
                ->where('invoice_id', $invoice->getKey())
                ->where('status', PaymentStatus::COMPLETED->value)
                ->sum('amount')
        );

        if (
            bccomp(
                $paidAmount,
                $invoiceTotal,
                self::MONEY_SCALE,
            ) > 0
        ) {
            Log::critical(
                'Invoice overpayment detected while recording cash.',
                [
                    'invoice_id' => $invoice->getKey(),
                    'invoice_total' => $invoiceTotal,
                    'completed_payment_amount' => $paidAmount,
                ]
            );

            throw ValidationException::withMessages([
                'payment' => [
                    'Completed payments exceed the invoice total.',
                ],
            ]);
        }

        $balanceDue = bcsub(
            $invoiceTotal,
            $paidAmount,
            self::MONEY_SCALE,
        );

        $isFullyPaid = bccomp(
            $balanceDue,
            '0.0000',
            self::MONEY_SCALE,
        ) === 0;

        $invoice->paid_amount = $paidAmount;
        $invoice->balance_due = $isFullyPaid
            ? '0.0000'
            : $balanceDue;

        if ($isFullyPaid) {
            $invoice->status = 'PAID';
            $invoice->paid_at ??= now();
        } else {
            $isOverdue = $invoice->due_date !== null
                && $invoice->due_date->lt(now()->startOfDay());

            $invoice->status = $isOverdue
                ? 'OVERDUE'
                : 'PARTIALLY_PAID';

            $invoice->paid_at = null;
        }

        $invoice->save();
    }

    /**
     * Queue a payment notification after the transaction commits.
     */
    protected function dispatchPaymentNotificationAfterCommit(
        Payment $payment,
        string $notificationType,
    ): void {
        SendPaymentNotificationJob::dispatch(
            paymentId: (string) $payment->getKey(),
            notificationType: $notificationType,
        )->afterCommit();

        Log::info('Payment notification queued after commit.', [
            'payment_id' => $payment->getKey(),
            'notification_type' => $notificationType,
        ]);
    }

    protected function invoiceStatus(Invoice $invoice): string
    {
        return strtoupper((string) (
            $invoice->status?->value
            ?? $invoice->status
            ?? ''
        ));
    }

    protected function generateTransactionReference(): string
    {
        return 'TXN-' . strtoupper(Str::ulid()->toBase32());
    }

    protected function normalizeAmount(mixed $amount): string
    {
        if (
            $amount === null
            || $amount === ''
            || is_array($amount)
            || is_object($amount)
            || is_bool($amount)
        ) {
            throw ValidationException::withMessages([
                'amount' => ['A valid payment amount is required.'],
            ]);
        }

        $value = trim((string) $amount);

        if (! preg_match('/^\d+(?:\.\d+)?$/', $value)) {
            throw ValidationException::withMessages([
                'amount' => [
                    'The payment amount must be a valid decimal number.',
                ],
            ]);
        }

        $decimalPosition = strpos($value, '.');

        if (
            $decimalPosition !== false
            && strlen($value) - $decimalPosition - 1 > self::MONEY_SCALE
        ) {
            throw ValidationException::withMessages([
                'amount' => [
                    'The payment amount may have up to four decimal places.',
                ],
            ]);
        }

        if (bccomp($value, '0', self::MONEY_SCALE) <= 0) {
            throw ValidationException::withMessages([
                'amount' => ['The payment amount must be greater than zero.'],
            ]);
        }

        return bcadd($value, '0', self::MONEY_SCALE);
    }

    protected function normalizeMoney(mixed $amount): string
    {
        if ($amount === null || $amount === '') {
            return '0.0000';
        }

        if (
            is_array($amount)
            || is_object($amount)
            || is_bool($amount)
        ) {
            throw new RuntimeException('Invalid monetary value.');
        }

        $value = trim((string) $amount);

        if (! preg_match('/^-?\d+(?:\.\d+)?$/', $value)) {
            throw new RuntimeException('Invalid monetary value.');
        }

        $decimalPosition = strpos($value, '.');

        if (
            $decimalPosition !== false
            && strlen($value) - $decimalPosition - 1 > self::MONEY_SCALE
        ) {
            throw new RuntimeException(
                'Invalid monetary value precision.'
            );
        }

        return bcadd($value, '0', self::MONEY_SCALE);
    }
}