<?php

namespace App\Modules\Payment\Services;

use App\Models\Payment;
use App\Models\Receipt;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use RuntimeException;

class PaymentReceiptPdfService
{
    /*
    |--------------------------------------------------------------------------
    | PDF Configuration
    |--------------------------------------------------------------------------
    */

    private const VIEW = 'payments.receipts.pdf';

    private const PAPER = 'a4';

    private const ORIENTATION = 'portrait';

    private const DEFAULT_FONT = 'DejaVu Sans';

    /*
    |--------------------------------------------------------------------------
    | Download PDF
    |--------------------------------------------------------------------------
    |
    | Generates the official receipt PDF and downloads it.
    |
    */

    public function download(
        Payment $payment
    ): Response {
        $pdf = $this->generate($payment);

        return $pdf->download(
            $this->filename($payment)
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Stream PDF
    |--------------------------------------------------------------------------
    |
    | Generates the official receipt PDF and streams it to the browser.
    |
    */

    public function stream(
        Payment $payment
    ): Response {
        $pdf = $this->generate($payment);

        return $pdf->stream(
            $this->filename($payment)
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Generate PDF
    |--------------------------------------------------------------------------
    |
    | Read-only operation.
    |
    | This method does NOT:
    |
    | - create payments
    | - create receipts
    | - modify payments
    | - modify invoices
    | - modify receipt records
    | - change payment status
    |
    */

    public function generate(
        Payment $payment
    ) {
        /*
        |--------------------------------------------------------------------------
        | Validate Payment
        |--------------------------------------------------------------------------
        */

        $this->validatePayment($payment);

        /*
        |--------------------------------------------------------------------------
        | Load Required Relationships
        |--------------------------------------------------------------------------
        */

        $payment->loadMissing([
            'invoice',
            'citizen',
            'receipt',
            'receipt.issuedBy',
            'bankTransferDetails',
            'onlineDetails',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Require Official Receipt
        |--------------------------------------------------------------------------
        */

        $receipt = $payment->receipt;

        if (! $receipt instanceof Receipt) {
            throw new RuntimeException(
                'The completed payment does not have an official receipt.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Validate Receipt
        |--------------------------------------------------------------------------
        */

        $this->validateReceipt($receipt);

        /*
        |--------------------------------------------------------------------------
        | Build Stable PDF DTO
        |--------------------------------------------------------------------------
        */

        $receiptData = $this->buildReceiptData(
            payment: $payment,
            receipt: $receipt,
        );

        /*
        |--------------------------------------------------------------------------
        | Render PDF
        |--------------------------------------------------------------------------
        */

        return Pdf::loadView(
            self::VIEW,
            [
                'receipt' => $receiptData,
            ]
        )
            ->setPaper(
                self::PAPER,
                self::ORIENTATION
            )
            ->setOption(
                'isRemoteEnabled',
                false
            )
            ->setOption(
                'isHtml5ParserEnabled',
                true
            )
            ->setOption(
                'defaultFont',
                self::DEFAULT_FONT
            );
    }

    /*
    |--------------------------------------------------------------------------
    | Build Receipt Data
    |--------------------------------------------------------------------------
    |
    | This is the contract consumed by:
    |
    | resources/views/payments/receipts/pdf.blade.php
    |
    | IMPORTANT:
    |
    | Monetary values returned here are already presentation-ready.
    |
    */

    protected function buildReceiptData(
        Payment $payment,
        Receipt $receipt
    ): array {
        return [
            /*
            |--------------------------------------------------------------------------
            | Municipality
            |--------------------------------------------------------------------------
            */

            'municipality' => [
                'name' => $this->municipalityName(),

                'address' => $this->configString(
                    'app.municipality_address'
                ),

                'phone' => $this->configString(
                    'app.municipality_phone'
                ),

                'email' => $this->configString(
                    'app.municipality_email'
                ),

                'website' => $this->configString(
                    'app.municipality_website'
                ),
            ],

            /*
            |--------------------------------------------------------------------------
            | Receipt
            |--------------------------------------------------------------------------
            */

            'receipt_number' => $this->stringOrNull(
                $receipt->receipt_number
            ),

            'receipt_issued_at' => $receipt->issued_at,

            'receipt_status' => $this->enumValue(
                $receipt->status
            ),

            'receipt_issued_by' => $this->stringOrNull(
                $receipt->issuedBy?->name
            ),

            /*
            |--------------------------------------------------------------------------
            | Payment
            |--------------------------------------------------------------------------
            */

            'payment_number' => $this->stringOrNull(
                $payment->payment_number
            ),

            'payment_reference' => $this->stringOrNull(
                $payment->transaction_reference
            ),

            'method_reference' => $this->resolveMethodReference(
                $payment
            ),

            'payment_method' => $this->enumValue(
                $payment->payment_method
            ),

            'payment_date' => $payment->verified_at
                ?? $payment->updated_at,

            'status' => $this->enumValue(
                $payment->status
            ),

            /*
            |--------------------------------------------------------------------------
            | Amount
            |--------------------------------------------------------------------------
            |
            | IMPORTANT:
            |
            | formatMoney() returns a STRING.
            |
            | Example:
            |
            | 73260
            |
            | becomes:
            |
            | "73,260.00"
            |
            | Blade MUST NOT call number_format() on this value again.
            |
            */

            'amount' => $this->formatMoney(
                $payment->amount
            ),

            'currency' => $this->stringOrNull(
                $payment->currency
            ),

            /*
            |--------------------------------------------------------------------------
            | Customer
            |--------------------------------------------------------------------------
            */

            'customer' => [
                'id' => $payment->citizen?->id,

                'name' => $this->resolveCustomerName(
                    $payment
                ),

                'email' => $this->stringOrNull(
                    $payment->payer_email
                ),

                'phone' => $this->stringOrNull(
                    $payment->payer_phone
                ),
            ],

            /*
            |--------------------------------------------------------------------------
            | Invoice
            |--------------------------------------------------------------------------
            */

            'invoice' => [
                'id' => $payment->invoice?->id,

                'reference' => $this->resolveInvoiceNumber(
                    $payment
                ),

                'status' => $payment->invoice
                    ? $this->enumValue(
                        $payment->invoice->status
                    )
                    : null,
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Validate Payment
    |--------------------------------------------------------------------------
    */

    protected function validatePayment(
        Payment $payment
    ): void {
        if (! $payment->exists) {
            throw new RuntimeException(
                'The payment does not exist.'
            );
        }

        if (! $payment->isSuccessful()) {
            throw new RuntimeException(
                'A payment receipt is only available for a completed payment.'
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Validate Receipt
    |--------------------------------------------------------------------------
    */

    protected function validateReceipt(
        Receipt $receipt
    ): void {
        if (! $receipt->exists) {
            throw new RuntimeException(
                'The official receipt does not exist.'
            );
        }

        if (blank($receipt->receipt_number)) {
            throw new RuntimeException(
                'The official receipt does not have a receipt number.'
            );
        }

        if (blank($receipt->issued_at)) {
            throw new RuntimeException(
                'The official receipt does not have an issue date.'
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Resolve Customer Name
    |--------------------------------------------------------------------------
    */

    protected function resolveCustomerName(
        Payment $payment
    ): ?string {
        /*
        |--------------------------------------------------------------------------
        | Payer Name
        |--------------------------------------------------------------------------
        */

        if (filled($payment->payer_name)) {
            return trim(
                (string) $payment->payer_name
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Citizen
        |--------------------------------------------------------------------------
        */

        $citizen = $payment->citizen;

        if (! $citizen) {
            return null;
        }

        /*
        |--------------------------------------------------------------------------
        | Citizen Full Name
        |--------------------------------------------------------------------------
        */

        if (
            isset($citizen->name)
            && filled($citizen->name)
        ) {
            return trim(
                (string) $citizen->name
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Citizen Name Components
        |--------------------------------------------------------------------------
        */

        $parts = array_filter(
            [
                $citizen->first_name ?? null,
                $citizen->middle_name ?? null,
                $citizen->last_name ?? null,
            ],
            static fn ($value): bool => filled($value)
        );

        if ($parts === []) {
            return null;
        }

        return implode(
            ' ',
            array_map(
                static fn ($value): string => trim(
                    (string) $value
                ),
                $parts
            )
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Resolve Invoice Number
    |--------------------------------------------------------------------------
    */

    protected function resolveInvoiceNumber(
        Payment $payment
    ): ?string {
        $invoice = $payment->invoice;

        if (! $invoice) {
            return null;
        }

        if (
            isset($invoice->invoice_number)
            && filled($invoice->invoice_number)
        ) {
            return trim(
                (string) $invoice->invoice_number
            );
        }

        if (
            isset($invoice->reference)
            && filled($invoice->reference)
        ) {
            return trim(
                (string) $invoice->reference
            );
        }

        return (string) $invoice->id;
    }

    /*
    |--------------------------------------------------------------------------
    | Resolve Payment Method Reference
    |--------------------------------------------------------------------------
    */

    protected function resolveMethodReference(
        Payment $payment
    ): ?string {
        /*
        |--------------------------------------------------------------------------
        | Bank Transfer
        |--------------------------------------------------------------------------
        */

        $bankDetails = $payment->bankTransferDetails;

        if (
            $bankDetails
            && filled($bankDetails->transfer_reference)
        ) {
            return trim(
                (string) $bankDetails->transfer_reference
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Online Payment
        |--------------------------------------------------------------------------
        */

        $onlineDetails = $payment->onlineDetails;

        if (
            $onlineDetails
            && filled(
                $onlineDetails->provider_transaction_id
            )
        ) {
            return trim(
                (string) $onlineDetails->provider_transaction_id
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Common Transaction Reference
        |--------------------------------------------------------------------------
        */

        if (
            filled($payment->transaction_reference)
        ) {
            return trim(
                (string) $payment->transaction_reference
            );
        }

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | Format Money
    |--------------------------------------------------------------------------
    |
    | This method is ONLY for PDF presentation.
    |
    | Do not use this method for financial calculations.
    |
    */

    protected function formatMoney(
        mixed $amount
    ): string {
        if (
            $amount === null
            || $amount === ''
        ) {
            return '0.00';
        }

        if (! is_numeric($amount)) {
            throw new RuntimeException(
                'The payment amount is not a valid numeric value.'
            );
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
    | Enum Value
    |--------------------------------------------------------------------------
    */

    protected function enumValue(
        mixed $value
    ): ?string {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        if ($value instanceof \UnitEnum) {
            return $value->name;
        }

        return (string) $value;
    }

    /*
    |--------------------------------------------------------------------------
    | Nullable String
    |--------------------------------------------------------------------------
    */

    protected function stringOrNull(
        mixed $value
    ): ?string {
        if (
            $value === null
            || $value === ''
        ) {
            return null;
        }

        $value = trim(
            (string) $value
        );

        return $value === ''
            ? null
            : $value;
    }

    /*
    |--------------------------------------------------------------------------
    | Municipality Name
    |--------------------------------------------------------------------------
    */

    protected function municipalityName(): string
    {
        $name = config(
            'app.municipality_name'
        );

        if (
            $name === null
            || $name === ''
        ) {
            return 'Adama City Administration';
        }

        return trim(
            (string) $name
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Configuration String
    |--------------------------------------------------------------------------
    */

    protected function configString(
        string $key
    ): string {
        $value = config($key);

        if (
            $value === null
            || $value === ''
        ) {
            return '';
        }

        return trim(
            (string) $value
        );
    }

    /*
    |--------------------------------------------------------------------------
    | PDF Filename
    |--------------------------------------------------------------------------
    */

    protected function filename(
        Payment $payment
    ): string {
        $payment->loadMissing(
            'receipt'
        );

        $receiptNumber =
            $payment->receipt?->receipt_number;

        if (blank($receiptNumber)) {
            $receiptNumber =
                'payment-' . $payment->id;
        }

        $safeReceiptNumber = preg_replace(
            '/[^A-Za-z0-9\-_]/',
            '-',
            (string) $receiptNumber
        );

        $safeReceiptNumber = trim(
            (string) $safeReceiptNumber,
            '-'
        );

        if ($safeReceiptNumber === '') {
            $safeReceiptNumber =
                'payment-' . $payment->id;
        }

        return sprintf(
            'payment-receipt-%s.pdf',
            $safeReceiptNumber
        );
    }
}