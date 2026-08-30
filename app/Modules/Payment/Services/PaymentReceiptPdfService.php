<?php

namespace App\Modules\Payment\Services;

use App\Models\Payment;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use RuntimeException;

class PaymentReceiptPdfService
{
    public function __construct(
        protected PaymentReceiptService $receiptService,
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | Generate PDF Download
    |--------------------------------------------------------------------------
    |
    | Generates the official municipal payment receipt PDF and returns
    | it as a downloadable HTTP response.
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
    | Opens the receipt in the browser instead of forcing a download.
    |
    | This is useful for:
    |
    | - View Receipt
    | - Print Receipt
    | - Browser PDF preview
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
    | This method only generates the PDF object.
    |
    | It does NOT return a response, which makes it reusable for:
    |
    | - HTTP download
    | - HTTP stream
    | - Email attachments
    | - Storage
    | - Background jobs
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
        | Ensure Receipt Exists
        |--------------------------------------------------------------------------
        |
        | Receipt generation is idempotent.
        |
        | If receipt_number already exists, nothing is changed.
        |
        */

        $payment = $this->receiptService->create(
            $payment
        );

        /*
        |--------------------------------------------------------------------------
        | Load Required Relationships
        |--------------------------------------------------------------------------
        |
        | Prevent N+1 queries when the Blade template accesses
        | invoice/citizen information.
        |
        */

        $payment->loadMissing([
            'invoice',
            'citizen',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Build Receipt Data
        |--------------------------------------------------------------------------
        |
        | Keep the Blade template presentation-focused.
        |
        | Business logic belongs here.
        |
        */

        $receipt = $this->buildReceiptData(
            $payment
        );

        /*
        |--------------------------------------------------------------------------
        | Generate PDF
        |--------------------------------------------------------------------------
        */

        return Pdf::loadView(
            'payments.receipts.pdf',
            [
                'receipt' => $receipt,
            ]
        )
            ->setPaper(
                'a4',
                'portrait'
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
                'DejaVu Sans'
            );
    }

    /*
    |--------------------------------------------------------------------------
    | Build Receipt Data
    |--------------------------------------------------------------------------
    |
    | Convert the Payment model into a stable structure for the
    | PDF template.
    |
    */

    protected function buildReceiptData(
        Payment $payment
    ): array {

        /*
        |--------------------------------------------------------------------------
        | Customer
        |--------------------------------------------------------------------------
        */

        $customerName =
            $payment->payer_name
            ?: $this->resolveCitizenName($payment);

        /*
        |--------------------------------------------------------------------------
        | Receipt
        |--------------------------------------------------------------------------
        */

        return [

            /*
            |--------------------------------------------------------------------------
            | Receipt Information
            |--------------------------------------------------------------------------
            */

            'receipt_number' =>
                $payment->receipt_number,

            'receipt_issued_at' =>
                $payment->receipt_issued_at,

            /*
            |--------------------------------------------------------------------------
            | Payment Information
            |--------------------------------------------------------------------------
            */

            'payment_reference' =>
                $payment->transaction_reference,

            'provider_reference' =>
                $payment->provider_reference,

            'payment_method' =>
                $this->enumValue(
                    $payment->payment_method
                ),

            'payment_provider' =>
                $this->enumValue(
                    $payment->payment_provider
                ),

            'payment_date' =>
                $payment->payment_date
                ?? $payment->verified_at
                ?? $payment->updated_at,

            'status' =>
                $this->enumValue(
                    $payment->status
                ),

            /*
            |--------------------------------------------------------------------------
            | Financial Information
            |--------------------------------------------------------------------------
            */

            'amount' =>
                (float) $payment->amount,

            'currency' =>
                $payment->currency,

            /*
            |--------------------------------------------------------------------------
            | Customer Information
            |--------------------------------------------------------------------------
            */

            'customer' => [

                'name' =>
                    $customerName,

                'email' =>
                    $payment->payer_email,

                'phone' =>
                    $payment->payer_phone,
            ],

            /*
            |--------------------------------------------------------------------------
            | Invoice Information
            |--------------------------------------------------------------------------
            */

            'invoice' => [
                'id' =>
                    $payment->invoice?->id,

                'reference' =>
                    $this->resolveInvoiceReference(
                        $payment
                    ),

            ],

            /*
            |--------------------------------------------------------------------------
            | Municipal Information
            |--------------------------------------------------------------------------
            |
            | Keep these configurable instead of hard-coding them
            | into the PDF service.
            |
            */

            'municipality' => [

                'name' =>
                    config(
                        'app.municipality_name',
                        'Adama City Administration'
                    ),

                'address' =>
                    config(
                        'app.municipality_address',
                        ''
                    ),

                'phone' =>
                    config(
                        'app.municipality_phone',
                        ''
                    ),

                'email' =>
                    config(
                        'app.municipality_email',
                        ''
                    ),

                'website' =>
                    config(
                        'app.municipality_website',
                        ''
                    ),
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

        /*
        |--------------------------------------------------------------------------
        | Payment Must Be Successful
        |--------------------------------------------------------------------------
        */

        if (!$payment->isSuccessful()) {
            throw new RuntimeException(
                'A payment receipt can only be generated for a successful payment.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Receipt Must Exist After Creation
        |--------------------------------------------------------------------------
        */

        if (
            !$payment->receipt_number
            &&
            !$payment->exists
        ) {
            throw new RuntimeException(
                'The payment must exist before generating a receipt.'
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Resolve Customer Name
    |--------------------------------------------------------------------------
    */

    protected function resolveCitizenName(
        Payment $payment
    ): ?string {

        if (!$payment->citizen) {
            return null;
        }

        /*
        |--------------------------------------------------------------------------
        | Prefer Full Name
        |--------------------------------------------------------------------------
        */

        if (
            isset($payment->citizen->name)
            &&
            filled($payment->citizen->name)
        ) {
            return $payment->citizen->name;
        }

        /*
        |--------------------------------------------------------------------------
        | First + Middle + Last
        |--------------------------------------------------------------------------
        */

        $parts = array_filter([
            $payment->citizen->first_name ?? null,
            $payment->citizen->middle_name ?? null,
            $payment->citizen->last_name ?? null,
        ]);

        return $parts
            ? implode(' ', $parts)
            : null;
    }

    /*
    |--------------------------------------------------------------------------
    | Resolve Invoice Reference
    |--------------------------------------------------------------------------
    */

    protected function resolveInvoiceReference(
        Payment $payment
    ): ?string {

        if (!$payment->invoice) {
            return null;
        }

        /*
        |--------------------------------------------------------------------------
        | Preferred Invoice Reference
        |--------------------------------------------------------------------------
        */

        if (
            isset($payment->invoice->invoice_number)
            &&
            filled($payment->invoice->invoice_number)
        ) {
            return $payment->invoice->invoice_number;
        }

        if (
            isset($payment->invoice->reference)
            &&
            filled($payment->invoice->reference)
        ) {
            return $payment->invoice->reference;
        }

        return $payment->invoice->id;
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
            return $value->value;
        }

        if ($value instanceof \UnitEnum) {
            return $value->name;
        }

        return (string) $value;
    }

    /*
    |--------------------------------------------------------------------------
    | PDF Filename
    |--------------------------------------------------------------------------
    */

    protected function filename(
        Payment $payment
    ): string {

        $receiptNumber =
            $payment->receipt_number
            ?: 'payment-' . $payment->id;

        /*
        |--------------------------------------------------------------------------
        | Sanitize Filename
        |--------------------------------------------------------------------------
        */

        $receiptNumber = preg_replace(
            '/[^A-Za-z0-9\-_]/',
            '-',
            $receiptNumber
        );

        return sprintf(
            'payment-receipt-%s.pdf',
            $receiptNumber
        );
    }
}