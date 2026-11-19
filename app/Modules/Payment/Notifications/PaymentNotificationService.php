<?php

declare(strict_types=1);

namespace App\Modules\Payment\Notifications;

use App\Models\Payment;
use App\Services\SmsService;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

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

    /*
    |--------------------------------------------------------------------------
    | Constructor
    |--------------------------------------------------------------------------
    */

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
    public function notifyPaymentReceived(Payment $payment): void
    {
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
    public function notifyPaymentPartiallyPaid(Payment $payment): void
    {
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
    public function notifyPaymentFullyPaid(Payment $payment): void
    {
        $this->sendSms(
            payment: $payment,
            message: $this->paymentFullyPaidMessage($payment),
            notificationType: self::PAYMENT_FULLY_PAID,
        );
    }

    /**
     * Notify taxpayer that a payment failed.
     */
    public function notifyPaymentFailed(Payment $payment): void
    {
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
    public function notifyBankTransferSubmitted(Payment $payment): void
    {
        $this->sendSms(
            payment: $payment,
            message: $this->bankTransferSubmittedMessage($payment),
            notificationType: self::BANK_TRANSFER_SUBMITTED,
        );
    }

    /**
     * Notify taxpayer that a bank transfer has been rejected.
     */
    public function notifyBankTransferRejected(Payment $payment): void
    {
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
                    $notificationType,
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
     * Missing phone numbers are skipped because retrying cannot
     * resolve missing taxpayer contact information.
     *
     * Gateway failures throw exceptions so Laravel can retry
     * the queued notification job.
     */
    protected function sendSms(
        Payment $payment,
        string $message,
        string $notificationType,
    ): void {
        $phone = $this->resolvePhoneNumber($payment);

        if ($phone === null) {
            Log::warning(
                'Payment notification skipped: taxpayer phone number unavailable.',
                [
                    'payment_id' => $payment->getKey(),
                    'payment_number' => $payment->payment_number,
                    'notification_type' => $notificationType,
                    'citizen_id' => $payment->citizen_id,
                    'skip_reason' => 'MISSING_PHONE_NUMBER',
                ]
            );

            return;
        }

        Log::info(
            'Payment notification SMS submission started.',
            [
                'payment_id' => $payment->getKey(),
                'payment_number' => $payment->payment_number,
                'notification_type' => $notificationType,
                'phone' => $this->maskPhoneNumber($phone),
                'message_length' => mb_strlen($message),
            ]
        );

        try {
            $result = $this->smsService->sendByPhone(
                phone: $phone,
                message: $message,
            );
        } catch (Throwable $exception) {
            Log::error(
                'Payment notification SMS submission threw an exception.',
                [
                    'payment_id' => $payment->getKey(),
                    'payment_number' => $payment->payment_number,
                    'notification_type' => $notificationType,
                    'phone' => $this->maskPhoneNumber($phone),
                    'exception' => $exception::class,
                ]
            );

            throw new RuntimeException(
                sprintf(
                    'Payment notification SMS submission failed for payment [%s].',
                    $payment->payment_number,
                ),
                previous: $exception,
            );
        }

        /*
         * SmsService returns:
         *
         * [
         *     'status' => 'success'|'failed',
         *     'http_status' => 200,
         *     'request_id' => '...',
         *     'duration_ms' => 100,
         * ]
         *
         * A successful gateway response confirms submission,
         * not necessarily final delivery to the taxpayer.
         */
        if (
            ! is_array($result)
            || ! isset($result['status'])
            || $result['status'] !== 'success'
        ) {
            Log::error(
                'Payment notification SMS submission failed.',
                [
                    'payment_id' => $payment->getKey(),
                    'payment_number' => $payment->payment_number,
                    'notification_type' => $notificationType,
                    'phone' => $this->maskPhoneNumber($phone),
                    'sms_result' => is_array($result)
                        ? $this->sanitizeSmsResult($result)
                        : ['status' => 'invalid_response'],
                ]
            );

            throw new RuntimeException(
                sprintf(
                    'Payment notification SMS submission failed for payment [%s].',
                    $payment->payment_number,
                )
            );
        }

        Log::info(
            'Payment notification SMS accepted by gateway.',
            [
                'payment_id' => $payment->getKey(),
                'payment_number' => $payment->payment_number,
                'notification_type' => $notificationType,
                'phone' => $this->maskPhoneNumber($phone),
                'sms_request_id' => $result['request_id'] ?? null,
                'http_status' => $result['http_status'] ?? null,
                'duration_ms' => $result['duration_ms'] ?? null,
                'submission_status' => 'ACCEPTED',
            ]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | SMS Messages — Afan Oromo
    |--------------------------------------------------------------------------
    */

    /**
     * Payment received message.
     */
    protected function paymentReceivedMessage(Payment $payment): string
    {
        return sprintf(
            "Bulchiinsi Magaalaa Adaamaa kaffaltii keessan ETB %s, lakkoofsa kaffaltii %s, invooyisii %s irratti milkaa'inaan fudhateera. Galatoomaa!",
            $this->formatAmount($payment->amount),
            $payment->payment_number,
            $this->invoiceNumber($payment),
        );
    }

    /**
     * Partially paid message.
     */
    protected function paymentPartiallyPaidMessage(Payment $payment): string
    {
        return sprintf(
            "Bulchiinsi Magaalaa Adaamaa kaffaltii ETB %s invooyisii %s irratti fudhateera. Hafe: ETB %s. Kaffaltii keessaniif galatoomaa!",
            $this->formatAmount($payment->amount),
            $this->invoiceNumber($payment),
            $this->formatAmount(
                $this->resolveInvoiceBalance($payment)
            ),
        );
    }

    /**
     * Fully paid message.
     *
     * Appreciative confirmation that the taxpayer has
     * completely settled the invoice.
     */
    protected function paymentFullyPaidMessage(Payment $payment): string
    {
        return sprintf(
            "Baga gammaddan! Bulchiinsi Magaalaa Adaamaa kaffaltii keessan guutumaan guutuutti fudhateera. Invooyisiin %s guutumaan kaffalameera. Kaffaltii keessaniif galatoomaa! Lakkoofsa ragaa: %s.",
            $this->invoiceNumber($payment),
            $payment->receipt?->receipt_number ?? 'N/A',
        );
    }

    /**
     * Payment failed message.
     *
     * Do not expose internal exception messages or raw gateway
     * responses to taxpayers.
     */
    protected function paymentFailedMessage(Payment $payment): string
    {
        return sprintf(
            "Bulchiinsi Magaalaa Adaamaa kaffaltiin ETB %s invooyisii %s irratti hin milkoofne. Maaloo, gargaarsaaf waajjira Bulchiinsa Magaalaa Adaamaa qunnamaa.",
            $this->formatAmount($payment->amount),
            $this->invoiceNumber($payment),
        );
    }

    /**
     * Bank transfer submitted message.
     */
    protected function bankTransferSubmittedMessage(Payment $payment): string
    {
        return sprintf(
            "Kaffaltiin baankii ETB %s invooyisii %s irratti ergameera. Mirkaneessa eeggachaa jira. Galatoomaa!",
            $this->formatAmount($payment->amount),
            $this->invoiceNumber($payment),
        );
    }

    /**
     * Bank transfer rejected message.
     *
     * Use a controlled message instead of exposing raw internal
     * verification or gateway failure details.
     */
    protected function bankTransferRejectedMessage(Payment $payment): string
    {
        return sprintf(
            "Kaffaltiin baankii ETB %s invooyisii %s irratti hin fudhatamne. Odeeffannoo dabalataaf Bulchiinsa Magaalaa Adaamaa qunnamaa.",
            $this->formatAmount($payment->amount),
            $this->invoiceNumber($payment),
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
     */
    protected function resolvePhoneNumber(Payment $payment): ?string
    {
        $paymentPhone = trim(
            (string) ($payment->payer_phone ?? '')
        );

        if ($paymentPhone !== '') {
            return $paymentPhone;
        }

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
    protected function invoiceNumber(Payment $payment): string
    {
        $invoiceNumber = $payment->invoice?->invoice_number;

        if ($invoiceNumber !== null && $invoiceNumber !== '') {
            return (string) $invoiceNumber;
        }

        if ($payment->invoice_id !== null) {
            return (string) $payment->invoice_id;
        }

        throw new RuntimeException(
            sprintf(
                'Invoice reference is unavailable for payment [%s].',
                $payment->payment_number,
            )
        );
    }

    /**
     * Resolve the authoritative invoice outstanding balance.
     */
    protected function resolveInvoiceBalance(Payment $payment): string
    {
        $invoice = $payment->invoice;

        if ($invoice === null) {
            throw new RuntimeException(
                sprintf(
                    'Invoice is unavailable for payment [%s].',
                    $payment->payment_number,
                )
            );
        }

        $balance = $invoice->balance_due;

        if ($balance === null || $balance === '') {
            throw new RuntimeException(
                sprintf(
                    'Invoice balance is unavailable for payment [%s].',
                    $payment->payment_number,
                )
            );
        }

        return (string) $balance;
    }

    /**
     * Format a monetary value for SMS display without converting
     * the amount to a floating-point number.
     *
     * Monetary amounts are rounded to two decimal places.
     */
    protected function formatAmount(mixed $amount): string
    {
        if (
            $amount === null
            || $amount === ''
            || is_array($amount)
            || is_object($amount)
            || is_bool($amount)
        ) {
            throw new RuntimeException(
                'Invalid monetary value supplied for SMS formatting.'
            );
        }

        $value = trim((string) $amount);

        if (! preg_match('/^\d+(?:\.\d+)?$/', $value)) {
            throw new RuntimeException(
                'Invalid monetary value supplied for SMS formatting.'
            );
        }

        if (! function_exists('bcadd')) {
            throw new RuntimeException(
                'BCMath extension is required for monetary formatting.'
            );
        }

        /*
         * Four-decimal precision is supported by the payment system.
         * Add 0.005 before truncating to two decimal places.
         */
        $rounded = bcadd(
            $value,
            '0.0050',
            2,
        );

        $parts = explode('.', $rounded);

        $integer = $parts[0];

        $formattedInteger = preg_replace(
            '/\B(?=(\d{3})+(?!\d))/',
            ',',
            $integer,
        );

        return ($formattedInteger ?: '0')
            . '.'
            . ($parts[1] ?? '00');
    }

    /*
    |--------------------------------------------------------------------------
    | Logging Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Mask phone number before writing it to logs.
     */
    protected function maskPhoneNumber(string $phone): string
    {
        $length = strlen($phone);

        if ($length <= 4) {
            return '****';
        }

        return str_repeat(
            '*',
            $length - 4,
        ) . substr($phone, -4);
    }

    /**
     * Restrict SMS gateway response data written to application logs.
     *
     * Never log raw gateway response bodies or complete SMS messages.
     */
    protected function sanitizeSmsResult(array $result): array
    {
        return [
            'status' => $result['status'] ?? null,
            'http_status' => $result['http_status'] ?? null,
            'request_id' => $result['request_id'] ?? null,
            'duration_ms' => $result['duration_ms'] ?? null,
            'error' => isset($result['error'])
                ? mb_substr((string) $result['error'], 0, 250)
                : null,
        ];
    }
}
