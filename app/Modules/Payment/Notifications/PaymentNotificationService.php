<?php

declare(strict_types=1);

namespace App\Modules\Payment\Notifications;

use App\Models\Payment;
use App\Services\SmsService;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class PaymentNotificationService
{
    /*
    |--------------------------------------------------------------------------
    | Notification Types
    |--------------------------------------------------------------------------
    */

    public const PAYMENT_RECEIVED = 'payment_received';

    public const PAYMENT_PARTIALLY_PAID = 'payment_partially_paid';

    public const PAYMENT_FULLY_PAID = 'payment_fully_paid';

    public const PAYMENT_FAILED = 'payment_failed';

    public const BANK_TRANSFER_SUBMITTED = 'bank_transfer_submitted';

    public const BANK_TRANSFER_REJECTED = 'bank_transfer_rejected';

    public function __construct(
        private readonly SmsService $smsService,
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | Public Notification Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Notify taxpayer that a payment has been received.
     */
    public function notifyPaymentReceived(
        Payment $payment
    ): void {
        $this->sendSms(
            payment: $payment,
            message: $this->paymentReceivedMessage($payment),
            notificationType: self::PAYMENT_RECEIVED,
        );
    }

    /**
     * Notify taxpayer that a payment was completed
     * but the invoice still has an outstanding balance.
     */
    public function notifyPaymentPartiallyPaid(
        Payment $payment
    ): void {
        $this->sendSms(
            payment: $payment,
            message: $this->paymentPartiallyPaidMessage($payment),
            notificationType: self::PAYMENT_PARTIALLY_PAID,
        );
    }

    /**
     * Notify taxpayer that a payment was completed
     * and the invoice is fully paid.
     */
    public function notifyPaymentFullyPaid(
        Payment $payment
    ): void {
        $this->sendSms(
            payment: $payment,
            message: $this->paymentFullyPaidMessage($payment),
            notificationType: self::PAYMENT_FULLY_PAID,
        );
    }

    /**
     * Notify taxpayer that a payment failed.
     */
    public function notifyPaymentFailed(
        Payment $payment
    ): void {
        $this->sendSms(
            payment: $payment,
            message: $this->paymentFailedMessage($payment),
            notificationType: self::PAYMENT_FAILED,
        );
    }

    /**
     * Notify taxpayer that a bank transfer has been submitted
     * and is waiting for verification.
     */
    public function notifyBankTransferSubmitted(
        Payment $payment
    ): void {
        $this->sendSms(
            payment: $payment,
            message: $this->bankTransferSubmittedMessage($payment),
            notificationType: self::BANK_TRANSFER_SUBMITTED,
        );
    }

    /**
     * Notify taxpayer that a bank transfer has been rejected.
     */
    public function notifyBankTransferRejected(
        Payment $payment
    ): void {
        $this->sendSms(
            payment: $payment,
            message: $this->bankTransferRejectedMessage($payment),
            notificationType: self::BANK_TRANSFER_REJECTED,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Dispatcher Entry Point
    |--------------------------------------------------------------------------
    */

    /**
     * Send a notification based on its notification type.
     *
     * This method is called by SendPaymentNotificationJob.
     */
    public function send(
        Payment $payment,
        string $notificationType,
    ): void {
        match ($notificationType) {
            self::PAYMENT_RECEIVED =>
                $this->notifyPaymentReceived($payment),

            self::PAYMENT_PARTIALLY_PAID =>
                $this->notifyPaymentPartiallyPaid($payment),

            self::PAYMENT_FULLY_PAID =>
                $this->notifyPaymentFullyPaid($payment),

            self::PAYMENT_FAILED =>
                $this->notifyPaymentFailed($payment),

            self::BANK_TRANSFER_SUBMITTED =>
                $this->notifyBankTransferSubmitted($payment),

            self::BANK_TRANSFER_REJECTED =>
                $this->notifyBankTransferRejected($payment),

            default => throw new RuntimeException(
                sprintf(
                    'Unsupported payment notification type [%s].',
                    $notificationType
                )
            ),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | SMS Delivery
    |--------------------------------------------------------------------------
    */

    /**
     * Send a payment notification through the municipal SMS service.
     *
     * SmsService::sendByPhone() returns a result array.
     * If the gateway reports failure, we throw so the queue job
     * can retry the notification.
     */
    protected function sendSms(
        Payment $payment,
        string $message,
        string $notificationType,
    ): void {
        $phone = $this->resolvePhoneNumber($payment);

        if ($phone === null) {
            Log::warning(
                'Payment notification skipped because taxpayer phone number is unavailable.',
                [
                    'payment_id' => $payment->id,
                    'payment_number' => $payment->payment_number,
                    'notification_type' => $notificationType,
                    'citizen_id' => $payment->citizen_id,
                ]
            );

            return;
        }

        Log::info(
            'Payment notification SMS sending started.',
            [
                'payment_id' => $payment->id,
                'payment_number' => $payment->payment_number,
                'notification_type' => $notificationType,
                'phone' => $this->maskPhoneNumber($phone),
                'message_length' => mb_strlen($message),
            ]
        );

        $result = $this->smsService->sendByPhone(
            phone: $phone,
            message: $message,
        );

        /*
         * SmsService returns:
         *
         * [
         *     'status' => 'success'|'failed',
         *     ...
         * ]
         */
        if (
            !isset($result['status'])
            || $result['status'] !== 'success'
        ) {
            Log::error(
                'Payment notification SMS delivery failed.',
                [
                    'payment_id' => $payment->id,
                    'payment_number' => $payment->payment_number,
                    'notification_type' => $notificationType,
                    'phone' => $this->maskPhoneNumber($phone),
                    'sms_result' => $this->sanitizeSmsResult($result),
                ]
            );

            throw new RuntimeException(
                sprintf(
                    'Payment notification SMS delivery failed for payment [%s].',
                    $payment->payment_number
                )
            );
        }

        Log::info(
            'Payment notification SMS sent successfully.',
            [
                'payment_id' => $payment->id,
                'payment_number' => $payment->payment_number,
                'notification_type' => $notificationType,
                'phone' => $this->maskPhoneNumber($phone),
                'sms_request_id' => $result['request_id'] ?? null,
                'http_status' => $result['http_status'] ?? null,
                'duration_ms' => $result['duration_ms'] ?? null,
            ]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | SMS Messages
    |--------------------------------------------------------------------------
    */

    /**
     * Payment received message.
     */
    protected function paymentReceivedMessage(
        Payment $payment
    ): string {
        return sprintf(
            'Adama City Municipality: Payment %s of ETB %s has been received successfully for invoice %s. Thank you.',
            $payment->payment_number,
            $this->formatAmount($payment->amount),
            $this->invoiceNumber($payment),
        );
    }

    /**
     * Partially paid message.
     */
    protected function paymentPartiallyPaidMessage(
        Payment $payment
    ): string {
        return sprintf(
            'Adama City Municipality: Payment %s of ETB %s was received for invoice %s. Outstanding balance: ETB %s.',
            $payment->payment_number,
            $this->formatAmount($payment->amount),
            $this->invoiceNumber($payment),
            $this->formatAmount(
                $this->resolveInvoiceBalance($payment)
            ),
        );
    }

    /**
     * Fully paid message.
     */
    protected function paymentFullyPaidMessage(
        Payment $payment
    ): string {
        return sprintf(
            'Adama City Municipality: Payment %s of ETB %s was received successfully. Invoice %s is now fully paid. Receipt: %s.',
            $payment->payment_number,
            $this->formatAmount($payment->amount),
            $this->invoiceNumber($payment),
            $payment->receipt?->receipt_number ?? 'N/A',
        );
    }

    /**
     * Payment failed message.
     */
    protected function paymentFailedMessage(
        Payment $payment
    ): string {
        $reason = trim(
            (string) ($payment->failure_reason ?? '')
        );

        if ($reason === '') {
            $reason = 'The payment could not be completed.';
        }

        return sprintf(
            'Adama City Municipality: Payment %s of ETB %s for invoice %s failed. %s',
            $payment->payment_number,
            $this->formatAmount($payment->amount),
            $this->invoiceNumber($payment),
            $reason,
        );
    }

    /**
     * Bank transfer submitted message.
     */
    protected function bankTransferSubmittedMessage(
        Payment $payment
    ): string {
        return sprintf(
            'Adama City Municipality: Bank transfer payment %s of ETB %s for invoice %s has been submitted and is awaiting verification.',
            $payment->payment_number,
            $this->formatAmount($payment->amount),
            $this->invoiceNumber($payment),
        );
    }

    /**
     * Bank transfer rejected message.
     */
    protected function bankTransferRejectedMessage(
        Payment $payment
    ): string {
        $reason = trim(
            (string) ($payment->failure_reason ?? '')
        );

        if ($reason === '') {
            $reason = 'Please contact the municipality for more information.';
        }

        return sprintf(
            'Adama City Municipality: Bank transfer payment %s of ETB %s for invoice %s was rejected. %s',
            $payment->payment_number,
            $this->formatAmount($payment->amount),
            $this->invoiceNumber($payment),
            $reason,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Payment / Invoice Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Resolve the taxpayer phone number.
     *
     * Priority:
     *
     * 1. Payment-specific payer_phone.
     * 2. Registered citizen phone.
     *
     * The payment-specific phone takes priority because it represents
     * the phone captured at the time the payment was initiated.
     */
    protected function resolvePhoneNumber(
        Payment $payment
    ): ?string {
        /*
         * First, use the phone stored directly on the payment.
         */
        $paymentPhone = trim(
            (string) ($payment->payer_phone ?? '')
        );

        if ($paymentPhone !== '') {
            return $paymentPhone;
        }

        /*
         * If the payment does not contain a phone number,
         * fall back to the registered taxpayer/citizen phone.
         */
        $citizenPhone = trim(
            (string) ($payment->citizen?->phone ?? '')
        );

        if ($citizenPhone !== '') {
            return $citizenPhone;
        }

        return null;
    }

    /**
     * Resolve invoice number.
     */
    protected function invoiceNumber(
        Payment $payment
    ): string {
        return $payment->invoice?->invoice_number
            ?? $payment->invoice_id;
    }

    /**
     * Resolve the invoice outstanding balance.
     *
     * The invoice model should expose the authoritative balance
     * calculated by the payment/settlement process.
     */
    protected function resolveInvoiceBalance(
        Payment $payment
    ): string {
        $invoice = $payment->invoice;

        if (! $invoice) {
            return '0.00';
        }

        /*
         * Use the authoritative invoice balance field first.
         */
        $balance = $invoice->balance_due ?? null;

        if ($balance !== null) {
            return (string) $balance;
        }

        /*
         * Fallbacks kept for compatibility.
         */
        $balance = $invoice->balance_amount
            ?? $invoice->outstanding_amount
            ?? null;

        if ($balance !== null) {
            return (string) $balance;
        }

        return '0.00';
    }

    /**
     * Format monetary value for SMS display.
     */
    protected function formatAmount(
        mixed $amount
    ): string {
        if ($amount === null || $amount === '') {
            return '0.00';
        }

        return number_format(
            (float) $amount,
            2,
            '.',
            ','
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Logging Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Mask phone number before writing it to logs.
     */
    protected function maskPhoneNumber(
        string $phone
    ): string {
        $length = strlen($phone);

        if ($length <= 4) {
            return '****';
        }

        return str_repeat(
            '*',
            max(0, $length - 4)
        ) . substr($phone, -4);
    }

    /**
     * Prevent unnecessary sensitive SMS response data
     * from being written to logs.
     */
    protected function sanitizeSmsResult(
        array $result
    ): array {
        return [
            'status' => $result['status'] ?? null,
            'http_status' => $result['http_status'] ?? null,
            'request_id' => $result['request_id'] ?? null,
            'duration_ms' => $result['duration_ms'] ?? null,
            'error' => $result['error'] ?? null,
        ];
    }
}
