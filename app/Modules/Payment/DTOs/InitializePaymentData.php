<?php

namespace App\Modules\Payment\DTOs;

use App\Enums\PaymentMethod;
use App\Enums\PaymentProvider;

final readonly class InitializePaymentData
{
    public function __construct(

        /*
        |--------------------------------------------------------------------------
        | Invoice
        |--------------------------------------------------------------------------
        */

        public string $invoiceId,

        /*
        |--------------------------------------------------------------------------
        | Citizen
        |--------------------------------------------------------------------------
        |
        | Trusted citizen ID.
        | This must come from the authenticated user's citizen account
        | or a controlled testing mechanism.
        |
        */

        public string $citizenId,

        /*
        |--------------------------------------------------------------------------
        | Internal Payment Reference
        |--------------------------------------------------------------------------
        */

        public string $paymentReference,

        /*
        |--------------------------------------------------------------------------
        | Amount
        |--------------------------------------------------------------------------
        */

        public float $amount,

        /*
        |--------------------------------------------------------------------------
        | Currency
        |--------------------------------------------------------------------------
        */

        public string $currency,

        /*
        |--------------------------------------------------------------------------
        | Payment Method
        |--------------------------------------------------------------------------
        */

        public PaymentMethod $method,

        /*
        |--------------------------------------------------------------------------
        | Payment Provider
        |--------------------------------------------------------------------------
        */

        public PaymentProvider $provider,

        /*
        |--------------------------------------------------------------------------
        | Customer Information
        |--------------------------------------------------------------------------
        */

        public ?string $customerId = null,

        public ?string $customerName = null,

        public ?string $customerEmail = null,

        public ?string $customerPhone = null,

        /*
        |--------------------------------------------------------------------------
        | Provider URLs
        |--------------------------------------------------------------------------
        */

        public ?string $returnUrl = null,

        public ?string $callbackUrl = null,

        /*
        |--------------------------------------------------------------------------
        | Description
        |--------------------------------------------------------------------------
        */

        public ?string $description = null,

        /*
        |--------------------------------------------------------------------------
        | Metadata
        |--------------------------------------------------------------------------
        */

        public array $metadata = [],
    ) {
    }

    /**
     * Create DTO from normalized array.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(

            /*
            |--------------------------------------------------------------------------
            | Required Values
            |--------------------------------------------------------------------------
            */

            invoiceId:
                (string) ($data['invoice_id'] ?? throw new \InvalidArgumentException(
                    'invoice_id is required.'
                )),

            citizenId:
                (string) ($data['citizen_id'] ?? throw new \InvalidArgumentException(
                    'citizen_id is required.'
                )),

            paymentReference:
                (string) ($data['payment_reference'] ?? throw new \InvalidArgumentException(
                    'payment_reference is required.'
                )),

            amount:
                (float) ($data['amount'] ?? throw new \InvalidArgumentException(
                    'amount is required.'
                )),

            /*
            |--------------------------------------------------------------------------
            | Currency
            |--------------------------------------------------------------------------
            */

            currency:
                strtoupper(
                    (string) ($data['currency'] ?? 'ETB')
                ),

            /*
            |--------------------------------------------------------------------------
            | Payment Method
            |--------------------------------------------------------------------------
            */

            method:
                $data['method'] instanceof PaymentMethod
                    ? $data['method']
                    : PaymentMethod::from(
                        strtoupper(
                            (string) ($data['method'] ?? throw new \InvalidArgumentException(
                                'payment method is required.'
                            ))
                        )
                    ),

            /*
            |--------------------------------------------------------------------------
            | Payment Provider
            |--------------------------------------------------------------------------
            */

            provider:
                $data['provider'] instanceof PaymentProvider
                    ? $data['provider']
                    : PaymentProvider::from(
                        strtoupper(
                            (string) ($data['provider'] ?? throw new \InvalidArgumentException(
                                'payment provider is required.'
                            ))
                        )
                    ),

            /*
            |--------------------------------------------------------------------------
            | Customer
            |--------------------------------------------------------------------------
            */

            customerId:
                filled($data['customer_id'] ?? null)
                    ? (string) $data['customer_id']
                    : null,

            customerName:
                filled($data['customer_name'] ?? null)
                    ? (string) $data['customer_name']
                    : null,

            customerEmail:
                filled($data['customer_email'] ?? null)
                    ? (string) $data['customer_email']
                    : null,

            customerPhone:
                filled($data['customer_phone'] ?? null)
                    ? (string) $data['customer_phone']
                    : null,

            /*
            |--------------------------------------------------------------------------
            | Provider URLs
            |--------------------------------------------------------------------------
            */

            returnUrl:
                filled($data['return_url'] ?? null)
                    ? (string) $data['return_url']
                    : null,

            callbackUrl:
                filled($data['callback_url'] ?? null)
                    ? (string) $data['callback_url']
                    : null,

            /*
            |--------------------------------------------------------------------------
            | Description
            |--------------------------------------------------------------------------
            */

            description:
                filled($data['description'] ?? null)
                    ? (string) $data['description']
                    : null,

            /*
            |--------------------------------------------------------------------------
            | Metadata
            |--------------------------------------------------------------------------
            */

            metadata:
                is_array($data['metadata'] ?? null)
                    ? $data['metadata']
                    : [],
        );
    }

    /**
     * Convert DTO to array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [

            'invoice_id' =>
                $this->invoiceId,

            'citizen_id' =>
                $this->citizenId,

            'payment_reference' =>
                $this->paymentReference,

            'amount' =>
                $this->amount,

            'currency' =>
                $this->currency,

            'method' =>
                $this->method->value,

            'provider' =>
                $this->provider->value,

            'customer_id' =>
                $this->customerId,

            'customer_name' =>
                $this->customerName,

            'customer_email' =>
                $this->customerEmail,

            'customer_phone' =>
                $this->customerPhone,

            'return_url' =>
                $this->returnUrl,

            'callback_url' =>
                $this->callbackUrl,

            'description' =>
                $this->description,

            'metadata' =>
                $this->metadata,
        ];
    }
}