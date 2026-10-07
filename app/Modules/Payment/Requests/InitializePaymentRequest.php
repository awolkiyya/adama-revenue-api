<?php

namespace App\Modules\Payment\Requests;

use App\Enums\PaymentMethod;
use App\Enums\PaymentProvider;
use App\Models\Invoice;
use App\Modules\Payment\DTOs\InitializePaymentData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class InitializePaymentRequest extends FormRequest
{
    /**
     * Determine whether the user is authorized to make this request.
     *
     * Authentication and invoice ownership/access are additionally
     * enforced by the PaymentController and PaymentService.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validation rules.
     *
     * This request is specifically for initializing an ONLINE payment.
     *
     * Client-controlled values:
     *
     * - invoice_id
     * - amount
     * - payment_provider
     *
     * Server-controlled values:
     *
     * - payment_method
     * - currency
     * - customer information
     * - payment reference
     * - description
     * - callback/return URLs
     * - metadata
     */
    public function rules(): array
    {
        return [
            /*
             * ---------------------------------------------------------
             * Invoice
             * ---------------------------------------------------------
             */
            'invoice_id' => [
                'required',
                'uuid',
                'exists:invoices,id',
            ],

            /*
             * ---------------------------------------------------------
             * Payment amount
             * ---------------------------------------------------------
             *
             * This is the amount the taxpayer wants to pay NOW.
             *
             * It can be:
             *
             * - the full outstanding balance
             * - a partial payment
             *
             * The PaymentService must additionally verify that the
             * requested amount does not exceed the CURRENT outstanding
             * invoice balance.
             */
            'amount' => [
                'required',
                'numeric',
                'gt:0',
            ],

            /*
             * ---------------------------------------------------------
             * Payment provider
             * ---------------------------------------------------------
             *
             * The frontend selects the online payment provider.
             *
             * Example:
             *
             * - CHAPA
             * - TELEBIRR
             * - CBE_BIRR
             *
             * The provider is validated against the PaymentProvider
             * enum.
             */
            'payment_provider' => [
                'required',
                Rule::enum(PaymentProvider::class),
            ],
        ];
    }

    /**
     * Custom validation messages.
     */
    public function messages(): array
    {
        return [
            /*
             * Invoice
             */
            'invoice_id.required' =>
                'An invoice is required for payment.',

            'invoice_id.uuid' =>
                'The invoice ID must be a valid UUID.',

            'invoice_id.exists' =>
                'The selected invoice does not exist.',

            /*
             * Amount
             */
            'amount.required' =>
                'A payment amount is required.',

            'amount.numeric' =>
                'The payment amount must be a valid number.',

            'amount.gt' =>
                'The payment amount must be greater than zero.',

            /*
             * Payment provider
             */
            'payment_provider.required' =>
                'A payment provider is required.',

            'payment_provider.enum' =>
                'The selected payment provider is invalid.',
        ];
    }

    /**
     * Normalize user-provided values before validation.
     *
     * Only values accepted from the frontend are normalized here.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            /*
             * Normalize amount without converting it to float.
             *
             * Keeping the decimal representation as a string during
             * validation avoids unnecessary floating-point
             * manipulation at the request layer.
             */
            'amount' =>
                $this->filled('amount')
                    ? trim(
                        (string) $this->input('amount')
                    )
                    : null,

            /*
             * Normalize payment provider.
             *
             * Example:
             *
             *     telebirr
             *
             * becomes:
             *
             *     TELEBIRR
             */
            'payment_provider' =>
                $this->filled('payment_provider')
                    ? strtoupper(
                        trim(
                            (string) $this->input(
                                'payment_provider'
                            )
                        )
                    )
                    : null,
        ]);
    }

    /**
     * Convert the validated request into the payment DTO.
     *
     * IMPORTANT:
     *
     * The amount supplied by the frontend is the amount to pay NOW.
     *
     * The invoice total is NOT used as the payment amount.
     *
     * The PaymentService remains responsible for:
     *
     * - invoice ownership/access
     * - invoice status
     * - current outstanding balance
     * - amount <= outstanding balance
     * - provider compatibility
     * - duplicate/active payment attempts
     */
    public function toDTO(): InitializePaymentData
    {
        $validated = $this->validated();

        /*
         * ---------------------------------------------------------
         * Retrieve invoice
         * ---------------------------------------------------------
         *
         * The invoice is retrieved from the database so trusted
         * server-side invoice information is used.
         */
        $invoice = Invoice::query()
            ->findOrFail(
                $validated['invoice_id']
            );

        /*
         * ---------------------------------------------------------
         * Payment amount
         * ---------------------------------------------------------
         *
         * This is the amount requested by the taxpayer.
         *
         * Do NOT replace this with:
         *
         *     $invoice->total_amount
         *
         * because partial payments must be supported.
         */
        $amount = (float) $validated['amount'];

        /*
         * ---------------------------------------------------------
         * Payment DTO
         * ---------------------------------------------------------
         */
        return InitializePaymentData::fromArray([
            /*
             * -----------------------------------------------------
             * Invoice
             * -----------------------------------------------------
             */
            'invoice_id' =>
                $invoice->id,

            /*
             * -----------------------------------------------------
             * Internal payment reference
             * -----------------------------------------------------
             *
             * This identifies this payment initialization attempt.
             */
            'payment_reference' =>
                'PAY-' .
                strtoupper(
                    Str::uuid()->toString()
                ),

            /*
             * -----------------------------------------------------
             * Payment amount
             * -----------------------------------------------------
             *
             * This is the amount being paid NOW.
             *
             * Supports:
             *
             * - full payment
             * - partial payment
             */
            'amount' =>
                $amount,

            /*
             * -----------------------------------------------------
             * Currency
             * -----------------------------------------------------
             *
             * The currency comes from the invoice.
             *
             * The frontend cannot choose the currency.
             */
            'currency' =>
                $invoice->currency ?? 'ETB',

            /*
             * -----------------------------------------------------
             * Payment method
             * -----------------------------------------------------
             *
             * This endpoint is specifically for ONLINE payments.
             *
             * Therefore the backend determines the method.
             *
             * The frontend does NOT send payment_method.
             */
            'method' =>
                PaymentMethod::ONLINE,

            /*
             * -----------------------------------------------------
             * Payment provider
             * -----------------------------------------------------
             *
             * Selected by the frontend and validated above.
             */
            'provider' =>
                $validated['payment_provider'],

            /*
             * -----------------------------------------------------
             * Customer
             * -----------------------------------------------------
             *
             * Customer identity comes from the invoice.
             *
             * It must not be used as the authorization mechanism.
             * Ownership/access must be checked separately.
             */
            'customer_id' =>
                $invoice->customer_id ?? null,

            /*
             * Customer details are not accepted from the frontend.
             *
             * If your payment provider requires these values,
             * they should be resolved by the payment service from
             * trusted municipal/customer records.
             */
            'customer_name' => null,

            'customer_email' => null,

            'customer_phone' => null,

            /*
             * -----------------------------------------------------
             * Provider URLs
             * -----------------------------------------------------
             *
             * These are backend/provider configuration values,
             * not frontend-controlled values.
             */
            'return_url' => null,

            'callback_url' => null,

            /*
             * -----------------------------------------------------
             * Description
             * -----------------------------------------------------
             *
             * Generated server-side from the invoice.
             */
            'description' =>
                'Payment for invoice ' .
                $invoice->invoice_number,

            /*
             * -----------------------------------------------------
             * Metadata
             * -----------------------------------------------------
             *
             * Internal metadata can be added later by the
             * payment service/provider integration.
             */
            'metadata' => [],
        ]);
    }
}