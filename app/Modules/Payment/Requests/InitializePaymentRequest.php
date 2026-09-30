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
     * Authentication/ownership is additionally enforced by the
     * PaymentController and PaymentService.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validation rules.
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
             * This is the AMOUNT THE TAXPAYER WANTS TO PAY NOW.
             *
             * It is NOT necessarily the invoice total.
             *
             * Example:
             *
             * invoice total = 5,000.00
             * paid amount    = 2,000.00
             * balance due    = 3,000.00
             * requested      = 1,410.00
             *
             * The controller/service must additionally verify that
             * requested amount <= the CURRENT invoice balance.
             */
            'amount' => [
                'required',
                'numeric',
                'gt:0',
            ],

            /*
             * ---------------------------------------------------------
             * Payment method
             * ---------------------------------------------------------
             */
            'payment_method' => [
                'required',
                Rule::enum(PaymentMethod::class),
            ],

            /*
             * ---------------------------------------------------------
             * Payment provider
             * ---------------------------------------------------------
             */
            'payment_provider' => [
                'required',
                Rule::enum(PaymentProvider::class),
            ],

            /*
             * ---------------------------------------------------------
             * Customer information
             * ---------------------------------------------------------
             */
            'customer_first_name' => [
                'nullable',
                'string',
                'max:100',
            ],

            'customer_last_name' => [
                'nullable',
                'string',
                'max:100',
            ],

            'customer_email' => [
                'nullable',
                'email',
                'max:255',
            ],

            'customer_phone' => [
                'nullable',
                'string',
                'max:30',
            ],

            /*
             * ---------------------------------------------------------
             * Provider URLs
             * ---------------------------------------------------------
             */
            'return_url' => [
                'nullable',
                'url',
                'max:2048',
            ],

            'callback_url' => [
                'nullable',
                'url',
                'max:2048',
            ],

            /*
             * ---------------------------------------------------------
             * Description
             * ---------------------------------------------------------
             */
            'description' => [
                'nullable',
                'string',
                'max:500',
            ],

            /*
             * ---------------------------------------------------------
             * Metadata
             * ---------------------------------------------------------
             */
            'metadata' => [
                'nullable',
                'array',
            ],

            'metadata.*' => [
                'nullable',
            ],
        ];
    }

    /**
     * Custom validation messages.
     */
    public function messages(): array
    {
        return [
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
             * Payment method
             */
            'payment_method.required' =>
                'A payment method is required.',

            'payment_method.enum' =>
                'The selected payment method is invalid.',

            /*
             * Payment provider
             */
            'payment_provider.required' =>
                'A payment provider is required.',

            'payment_provider.enum' =>
                'The selected payment provider is invalid.',

            /*
             * Customer
             */
            'customer_first_name.string' =>
                'The customer first name must be a valid string.',

            'customer_last_name.string' =>
                'The customer last name must be a valid string.',

            'customer_email.email' =>
                'Please provide a valid customer email address.',

            'customer_phone.string' =>
                'The customer phone number must be a valid string.',

            /*
             * URLs
             */
            'return_url.url' =>
                'The return URL must be a valid URL.',

            'callback_url.url' =>
                'The callback URL must be a valid URL.',

            /*
             * Description
             */
            'description.string' =>
                'The payment description must be a valid string.',

            /*
             * Metadata
             */
            'metadata.array' =>
                'Payment metadata must be an object or array.',
        ];
    }

    /**
     * Normalize user-provided values before validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            /*
             * Normalize amount without converting it to float.
             *
             * Keeping the original decimal representation avoids
             * unnecessary floating-point manipulation at this layer.
             */
            'amount' =>
                $this->filled('amount')
                    ? trim((string) $this->input('amount'))
                    : null,

            'payment_method' =>
                $this->filled('payment_method')
                    ? strtoupper(
                        trim(
                            (string) $this->input(
                                'payment_method'
                            )
                        )
                    )
                    : null,

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

            'customer_first_name' =>
                $this->filled('customer_first_name')
                    ? trim(
                        (string) $this->input(
                            'customer_first_name'
                        )
                    )
                    : null,

            'customer_last_name' =>
                $this->filled('customer_last_name')
                    ? trim(
                        (string) $this->input(
                            'customer_last_name'
                        )
                    )
                    : null,

            'customer_email' =>
                $this->filled('customer_email')
                    ? strtolower(
                        trim(
                            (string) $this->input(
                                'customer_email'
                            )
                        )
                    )
                    : null,

            'customer_phone' =>
                $this->filled('customer_phone')
                    ? trim(
                        (string) $this->input(
                            'customer_phone'
                        )
                    )
                    : null,

            'description' =>
                $this->filled('description')
                    ? trim(
                        (string) $this->input(
                            'description'
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
     * The frontend-selected amount is the amount to pay NOW.
     *
     * The invoice total is NOT used as the payment amount.
     *
     * The PaymentController/PaymentService must still perform the
     * authoritative current-balance and ownership checks.
     */
    public function toDTO(): InitializePaymentData
    {
        $validated = $this->validated();

        /*
         * Retrieve the invoice.
         *
         * This is used for server-side invoice information such as:
         *
         * - invoice ID
         * - currency
         * - customer ID
         *
         * The payment amount itself comes from the validated request.
         */
        $invoice = Invoice::query()
            ->findOrFail(
                $validated['invoice_id']
            );

        /*
         * IMPORTANT:
         *
         * Do NOT use:
         *
         *     $invoice->total_amount
         *
         * here.
         *
         * That would make partial payments impossible.
         */
        $amount = (float) $validated['amount'];

        /*
         * Build customer name from first and last name.
         */
        $customerName = trim(
            implode(
                ' ',
                array_filter([
                    $validated['customer_first_name'] ?? null,
                    $validated['customer_last_name'] ?? null,
                ])
            )
        );

        /*
         * Create the DTO.
         */
        return InitializePaymentData::fromArray([
            /*
             * Invoice
             */
            'invoice_id' =>
                $invoice->id,

            /*
             * Generate internal transaction reference.
             *
             * This is different from payment_number.
             *
             * payment_number:
             *     PAY-2019-000001
             *
             * payment_reference / transaction_reference:
             *     PAY-<unique-reference>
             */
            'payment_reference' =>
                'PAY-' .
                strtoupper(
                    Str::uuid()->toString()
                ),

            /*
             * The amount being paid NOW.
             *
             * This may be:
             *
             * - full balance
             * - partial balance
             */
            'amount' =>
                $amount,

            /*
             * Currency comes from the invoice.
             *
             * The client must not be trusted to determine the
             * currency of the invoice.
             */
            'currency' =>
                $invoice->currency ?? 'ETB',

            /*
             * Payment method/provider come from validated input.
             *
             * The controller should additionally enforce the
             * CHAPA endpoint contract.
             */
            'method' =>
                $validated['payment_method'],

            'provider' =>
                $validated['payment_provider'],

            /*
             * Customer identity comes from the invoice.
             *
             * This should never be used as the authorization
             * mechanism. Ownership must be checked separately.
             */
            'customer_id' =>
                $invoice->customer_id ?? null,

            /*
             * Optional customer display information.
             */
            'customer_name' =>
                $customerName !== ''
                    ? $customerName
                    : null,

            'customer_email' =>
                $validated['customer_email'] ?? null,

            'customer_phone' =>
                $validated['customer_phone'] ?? null,

            /*
             * Provider URLs.
             */
            'return_url' =>
                $validated['return_url'] ?? null,

            'callback_url' =>
                $validated['callback_url'] ?? null,

            /*
             * Description.
             *
             * If the client does not provide one, create a useful
             * server-side description.
             */
            'description' =>
                $validated['description']
                    ?? (
                        'Payment for invoice ' .
                        $invoice->invoice_number
                    ),

            /*
             * Metadata.
             */
            'metadata' =>
                $validated['metadata'] ?? [],
        ]);
    }
}