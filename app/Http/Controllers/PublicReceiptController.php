<?php

namespace App\Http\Controllers;

use App\Models\Receipt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicReceiptController extends Controller
{
    /**
     * Verify a payment receipt publicly.
     *
     * Endpoint:
     * GET /api/v1/public/receipts/verify?receipt_number=...
     *
     * No authentication required.
     */
    public function verify(Request $request): JsonResponse
    {
        // --------------------------------------------------------
        // Validate receipt number
        // --------------------------------------------------------

        $validated = $request->validate([
            'receipt_number' => [
                'required',
                'string',
                'max:100',
            ],
        ]);

        $receiptNumber = trim($validated['receipt_number']);

        // --------------------------------------------------------
        // Find receipt
        // --------------------------------------------------------

        $receipt = Receipt::query()
            ->with([
                'payment.invoice',
            ])
            ->where('receipt_number', $receiptNumber)
            ->first();

        // --------------------------------------------------------
        // Receipt not found
        // --------------------------------------------------------

        if (! $receipt) {
            return response()->json([
                'verified' => false,
                'message' => 'Receipt could not be verified.',
                'data' => null,
            ], 404);
        }

        // --------------------------------------------------------
        // Receipt must be issued
        // --------------------------------------------------------

        if ($this->enumValue($receipt->status) !== 'ISSUED') {
            return response()->json([
                'verified' => false,
                'message' => 'This receipt is not currently valid.',
                'data' => null,
            ], 422);
        }

        // --------------------------------------------------------
        // Payment must exist
        // --------------------------------------------------------

        $payment = $receipt->payment;

        if (! $payment) {
            return response()->json([
                'verified' => false,
                'message' => 'Receipt payment information is unavailable.',
                'data' => null,
            ], 422);
        }

        // --------------------------------------------------------
        // Payment must be completed
        // --------------------------------------------------------

        if ($this->enumValue($payment->status) !== 'COMPLETED') {
            return response()->json([
                'verified' => false,
                'message' => 'This receipt is not currently valid.',
                'data' => null,
            ], 422);
        }

        // --------------------------------------------------------
        // Invoice
        // --------------------------------------------------------

        $invoice = $payment->invoice;

        // --------------------------------------------------------
        // Successful verification
        // --------------------------------------------------------

        return response()->json([
            'verified' => true,

            'receipt' => [
                'receipt_number' => $receipt->receipt_number,
                'issued_at' => $receipt->issued_at?->toIso8601String(),
                'status' => $this->enumValue($receipt->status),
            ],

            'payment' => [
                'payment_number' => $payment->payment_number,

                'payment_date' => $payment->paid_at?->toIso8601String()
                    ?? $payment->created_at?->toIso8601String(),

                'payment_method' => $this->paymentMethod($payment),

                'amount' => $this->formatAmount($payment->amount),

                'currency' => strtoupper(
                    (string) ($payment->currency ?? 'ETB')
                ),
            ],

            'invoice' => $invoice
                ? [
                    'invoice_number' => $invoice->invoice_number,
                ]
                : null,

            'municipality' => [
                'name' => 'Adama City Administration',
                'department' => 'Revenue Office',
            ],
        ]);
    }

    /**
     * Get the string value from an enum or normal value.
     */
    private function enumValue($value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \BackedEnum) {
            return strtoupper((string) $value->value);
        }

        return strtoupper((string) $value);
    }

    /**
     * Resolve the public payment method label.
     */
    private function paymentMethod($payment): string
    {
        $method = $payment->payment_method
            ?? $payment->method
            ?? null;

        if ($method === null) {
            return 'UNKNOWN';
        }

        if ($method instanceof \BackedEnum) {
            $method = $method->value;
        }

        return strtoupper(
            str_replace(
                ['-', '_'],
                ' ',
                (string) $method
            )
        );
    }

    /**
     * Format payment amount for public display.
     */
    private function formatAmount($amount): string
    {
        if ($amount === null) {
            return '0.00';
        }

        return number_format(
            (float) $amount,
            2,
            '.',
            ','
        );
    }
}