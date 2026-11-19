<?php

declare(strict_types=1);

namespace App\Modules\Invoice\Notifications;

use App\Models\Invoice;
use App\Services\SmsService;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class InvoiceNotificationService
{
    /*
    |--------------------------------------------------------------------------
    | Configuration
    |--------------------------------------------------------------------------
    */

    private const DEFAULT_TAXPAYER_APP_URL = 'http://192.168.3.1:3000/en';

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
    | Public Notification Method
    |--------------------------------------------------------------------------
    */

    /**
     * Send an Afan Oromo SMS after an invoice has been issued.
     *
     * Missing taxpayer or phone information is logged and skipped.
     * Gateway failures throw exceptions so Laravel's queued job
     * can retry the notification.
     */
    public function sendInvoiceIssued(Invoice $invoice): void
    {
        try {
            /*
            |--------------------------------------------------------------------------
            | LOAD TAXPAYER
            |--------------------------------------------------------------------------
            */

            $citizen = $invoice->citizen;

            if ($citizen === null) {
                Log::warning(
                    'Invoice SMS notification skipped: taxpayer not found.',
                    [
                        'invoice_id' => $invoice->getKey(),
                        'invoice_number' => $invoice->invoice_number,
                        'citizen_id' => $invoice->citizen_id,
                        'skip_reason' => 'TAXPAYER_NOT_FOUND',
                    ]
                );

                return;
            }

            /*
            |--------------------------------------------------------------------------
            | RESOLVE PHONE NUMBER
            |--------------------------------------------------------------------------
            */

            $phone = trim(
                (string) ($citizen->phone ?? '')
            );

            if ($phone === '') {
                Log::warning(
                    'Invoice SMS notification skipped: taxpayer phone number unavailable.',
                    [
                        'invoice_id' => $invoice->getKey(),
                        'invoice_number' => $invoice->invoice_number,
                        'citizen_id' => $citizen->getKey(),
                        'skip_reason' => 'MISSING_PHONE_NUMBER',
                    ]
                );

                return;
            }

            /*
            |--------------------------------------------------------------------------
            | RESOLVE TAXPAYER NAME
            |--------------------------------------------------------------------------
            */

            $taxpayerName = $this->getTaxpayerName($citizen);

            /*
            |--------------------------------------------------------------------------
            | RESOLVE INVOICE NUMBER
            |--------------------------------------------------------------------------
            */

            $invoiceNumber = trim(
                (string) ($invoice->invoice_number ?? '')
            );

            if ($invoiceNumber === '') {
                throw new RuntimeException(
                    'Cannot send invoice notification: invoice number is missing.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | RESOLVE AMOUNT DUE
            |--------------------------------------------------------------------------
            */

            $balanceDue = $invoice->balance_due;

            if ($balanceDue === null || $balanceDue === '') {
                throw new RuntimeException(
                    'Cannot send invoice notification: invoice balance is unavailable.'
                );
            }

            $amount = $this->formatAmount(
                (string) $balanceDue
            );

            /*
            |--------------------------------------------------------------------------
            | RESOLVE TAXPAYER APPLICATION URL
            |--------------------------------------------------------------------------
            */

            $appUrl = $this->taxpayerAppUrl();

            /*
            |--------------------------------------------------------------------------
            | BUILD AFAN OROMO SMS
            |--------------------------------------------------------------------------
            */

            $message = $this->invoiceIssuedMessage(
                taxpayerName: $taxpayerName,
                invoiceNumber: $invoiceNumber,
                amount: $amount,
                appUrl: $appUrl,
            );

            /*
            |--------------------------------------------------------------------------
            | LOG SUBMISSION START
            |--------------------------------------------------------------------------
            */

            Log::info(
                'Invoice SMS notification submission started.',
                [
                    'invoice_id' => $invoice->getKey(),
                    'invoice_number' => $invoiceNumber,
                    'citizen_id' => $citizen->getKey(),
                    'phone' => $this->maskPhoneNumber($phone),
                    'message_length' => mb_strlen($message),
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | SEND SMS
            |--------------------------------------------------------------------------
            */

            $response = $this->smsService->sendByPhone(
                phone: $phone,
                message: $message,
            );

            /*
            |--------------------------------------------------------------------------
            | VALIDATE GATEWAY RESPONSE
            |--------------------------------------------------------------------------
            */

            if (
                ! is_array($response)
                || ($response['status'] ?? null) !== 'success'
            ) {
                Log::error(
                    'Invoice SMS notification submission failed.',
                    [
                        'invoice_id' => $invoice->getKey(),
                        'invoice_number' => $invoiceNumber,
                        'citizen_id' => $citizen->getKey(),
                        'phone' => $this->maskPhoneNumber($phone),
                        'sms_response' => $this->sanitizeSmsResponse(
                            $response
                        ),
                    ]
                );

                throw new RuntimeException(
                    sprintf(
                        'Invoice SMS notification was not accepted by the gateway for invoice [%s].',
                        $invoiceNumber,
                    )
                );
            }

            /*
            |--------------------------------------------------------------------------
            | SUCCESS LOG
            |--------------------------------------------------------------------------
            |
            | Gateway acceptance confirms submission, not final
            | delivery to the taxpayer's mobile phone.
            |
            */

            Log::info(
                'Invoice SMS notification accepted by gateway.',
                [
                    'invoice_id' => $invoice->getKey(),
                    'invoice_number' => $invoiceNumber,
                    'citizen_id' => $citizen->getKey(),
                    'phone' => $this->maskPhoneNumber($phone),
                    'request_id' => $response['request_id'] ?? null,
                    'http_status' => $response['http_status'] ?? null,
                    'duration_ms' => $response['duration_ms'] ?? null,
                    'submission_status' => 'ACCEPTED',
                ]
            );

        } catch (Throwable $exception) {
            /*
            |--------------------------------------------------------------------------
            | LOG AND RE-THROW
            |--------------------------------------------------------------------------
            |
            | Do not swallow exceptions.
            | The queued job must receive the exception so Laravel
            | can retry the notification.
            |
            */

            Log::error(
                'Invoice SMS notification encountered an exception.',
                [
                    'invoice_id' => $invoice->getKey(),
                    'invoice_number' => $invoice->invoice_number,
                    'citizen_id' => $invoice->citizen_id,
                    'exception' => $exception::class,
                ]
            );

            throw $exception;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | AFAN OROMO MESSAGE
    |--------------------------------------------------------------------------
    */

    /**
     * Build the invoice-issued SMS in Afan Oromo.
     *
     * The message includes:
     * - Taxpayer name
     * - Invoice number
     * - Amount due
     * - Payment instructions
     * - Taxpayer application URL
     */
    private function invoiceIssuedMessage(
        string $taxpayerName,
        string $invoiceNumber,
        string $amount,
        string $appUrl,
    ): string {
        return sprintf(
            "Kabajamoo %s, invooyisiin gibiraa/kaffaltii keessanii %s Bulchiinsa Magaalaa Adaamaatiin baheera. Maallaqa kaffalamu: ETB %s. Kaffaltii raawwachuuf lakkoofsa invooyisii kana fayyadamaa. Odeeffannoo dabalataaf Appii Kaffalaa Gibiraa daawwadhaa: %s",
            $taxpayerName,
            $invoiceNumber,
            $amount,
            $appUrl,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | TAXPAYER HELPERS
    |--------------------------------------------------------------------------
    */

    /**
     * Resolve the taxpayer's display name.
     *
     * Supports common citizen name attributes.
     */
    private function getTaxpayerName(object $citizen): string
    {
        $fullName = trim(
            (string) ($citizen->full_name ?? '')
        );

        if ($fullName !== '') {
            return $fullName;
        }

        $name = trim(
            (string) ($citizen->name ?? '')
        );

        if ($name !== '') {
            return $name;
        }

        $parts = array_filter(
            [
                trim((string) ($citizen->first_name ?? '')),
                trim((string) ($citizen->middle_name ?? '')),
                trim((string) ($citizen->last_name ?? '')),
            ],
            static fn (string $part): bool => $part !== '',
        );

        $name = trim(
            implode(' ', $parts)
        );

        return $name !== '' ? $name : 'Kabajamaa';
    }

    /*
    |--------------------------------------------------------------------------
    | APPLICATION URL
    |--------------------------------------------------------------------------
    */

    /**
     * Resolve the taxpayer application URL.
     */
    private function taxpayerAppUrl(): string
    {
        $url = trim(
            (string) config(
                'app.taxpayer_app_url',
                self::DEFAULT_TAXPAYER_APP_URL,
            )
        );

        if (
            $url === ''
            || ! filter_var($url, FILTER_VALIDATE_URL)
            || ! in_array(
                strtolower((string) parse_url($url, PHP_URL_SCHEME)),
                ['http', 'https'],
                true,
            )
        ) {
            throw new RuntimeException(
                'The taxpayer application URL is invalid.'
            );
        }

        return rtrim($url, '/');
    }

    /*
    |--------------------------------------------------------------------------
    | MONETARY FORMATTING
    |--------------------------------------------------------------------------
    */

    /**
     * Format a monetary amount without floating-point conversion.
     *
     * Supports four-decimal payment precision and rounds to
     * two decimal places.
     */
    private function formatAmount(string $amount): string
    {
        $amount = trim($amount);

        if (
            ! function_exists('bcadd')
            || ! preg_match('/^\d+(?:\.\d+)?$/', $amount)
        ) {
            throw new RuntimeException(
                'Invoice balance due is invalid or BCMath is unavailable.'
            );
        }

        /*
         * Round to two decimal places.
         */
        $rounded = bcadd(
            $amount,
            '0.005',
            2,
        );

        [$integer, $decimal] = array_pad(
            explode('.', $rounded, 2),
            2,
            '00',
        );

        /*
         * Add thousands separators.
         */
        $integer = preg_replace(
            '/\B(?=(\d{3})+(?!\d))/',
            ',',
            $integer,
        );

        return ($integer ?: '0')
            . '.'
            . str_pad($decimal, 2, '0');
    }

    /*
    |--------------------------------------------------------------------------
    | LOGGING HELPERS
    |--------------------------------------------------------------------------
    */

    /**
     * Mask phone number before writing it to logs.
     */
    private function maskPhoneNumber(string $phone): string
    {
        $length = strlen($phone);

        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return str_repeat('*', $length - 4)
            . substr($phone, -4);
    }

    /**
     * Restrict gateway response data written to application logs.
     *
     * Never log raw gateway response bodies or complete SMS messages.
     */
    private function sanitizeSmsResponse(mixed $response): array
    {
        if (! is_array($response)) {
            return [
                'status' => 'invalid_response',
            ];
        }

        return [
            'status' => $response['status'] ?? null,
            'http_status' => $response['http_status'] ?? null,
            'request_id' => $response['request_id'] ?? null,
            'duration_ms' => $response['duration_ms'] ?? null,
            'error' => isset($response['error'])
                ? mb_substr((string) $response['error'], 0, 250)
                : null,
        ];
    }
}
