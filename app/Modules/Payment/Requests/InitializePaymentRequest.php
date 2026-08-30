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
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'invoice_id' => [
                'required',
                'uuid',
                'exists:invoices,id',
            ],

            'payment_method' => [
                'required',
                Rule::enum(PaymentMethod::class),
            ],

            'payment_provider' => [
                'required',
                Rule::enum(PaymentProvider::class),
            ],

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

            'description' => [
                'nullable',
                'string',
                'max:500',
            ],

            'metadata' => [
                'nullable',
                'array',
            ],

            'metadata.*' => [
                'nullable',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'invoice_id.required' =>
                'An invoice is required for payment.',

            'invoice_id.uuid' =>
                'The invoice ID must be a valid UUID.',

            'invoice_id.exists' =>
                'The selected invoice does not exist.',

            'payment_method.required' =>
                'A payment method is required.',

            'payment_method.enum' =>
                'The selected payment method is invalid.',

            'payment_provider.required' =>
                'A payment provider is required.',

            'payment_provider.enum' =>
                'The selected payment provider is invalid.',

            'customer_email.email' =>
                'Please provide a valid customer email address.',

            'return_url.url' =>
                'The return URL must be a valid URL.',

            'callback_url.url' =>
                'The callback URL must be a valid URL.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'customer_first_name' =>
                $this->filled('customer_first_name')
                    ? trim((string) $this->input('customer_first_name'))
                    : null,

            'customer_last_name' =>
                $this->filled('customer_last_name')
                    ? trim((string) $this->input('customer_last_name'))
                    : null,

            'customer_email' =>
                $this->filled('customer_email')
                    ? strtolower(trim((string) $this->input('customer_email')))
                    : null,

            'customer_phone' =>
                $this->filled('customer_phone')
                    ? trim((string) $this->input('customer_phone'))
                    : null,

            'description' =>
                $this->filled('description')
                    ? trim((string) $this->input('description'))
                    : null,
        ]);
    }

    /**
     * Convert the validated request into the payment DTO.
     *
     * IMPORTANT:
     * The frontend does NOT provide the payment amount.
     * The amount comes from the invoice.
     */
    public function toDTO(): InitializePaymentData
    {
        $validated = $this->validated();

        $invoice = Invoice::query()
            ->findOrFail($validated['invoice_id']);

        /*
         * IMPORTANT:
         *
         * Replace `total_amount` with the actual amount
         * column/property of your Invoice model if different.
         */
        $amount = (float) $invoice->total_amount;

        $customerName = trim(
            implode(' ', array_filter([
                $validated['customer_first_name'] ?? null,
                $validated['customer_last_name'] ?? null,
            ]))
        );

        return InitializePaymentData::fromArray([
            'invoice_id' =>
                $invoice->id,

            /*
             * Generated internally.
             * Never accept this from the frontend.
             */
            'payment_reference' =>
                'PAY-' . strtoupper(Str::uuid()->toString()),

            /*
             * Taken from the invoice, NOT the frontend.
             */
            'amount' =>
                $amount,

            'currency' =>
                $invoice->currency ?? 'ETB',

            'method' =>
                $validated['payment_method'],

            'provider' =>
                $validated['payment_provider'],

            'customer_id' =>
                $invoice->customer_id ?? null,

            'customer_name' =>
                $customerName !== ''
                    ? $customerName
                    : null,

            'customer_email' =>
                $validated['customer_email'] ?? null,

            'customer_phone' =>
                $validated['customer_phone'] ?? null,

            'return_url' =>
                $validated['return_url'] ?? null,

            'callback_url' =>
                $validated['callback_url'] ?? null,

            'description' =>
                $validated['description'] ?? null,

            'metadata' =>
                $validated['metadata'] ?? [],
        ]);
    }
}