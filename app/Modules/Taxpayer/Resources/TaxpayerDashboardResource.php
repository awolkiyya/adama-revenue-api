<?php

namespace App\Modules\Taxpayer\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaxpayerDashboardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'summary' => [
                'total_invoices' => $this['summary']['total_invoices'],
                'outstanding_invoices' => $this['summary']['outstanding_invoices'],
                'paid_invoices' => $this['summary']['paid_invoices'],

                'total_outstanding' => number_format(
                    (float) $this['summary']['total_outstanding'],
                    2,
                    '.',
                    ''
                ),

                'total_paid' => number_format(
                    (float) $this['summary']['total_paid'],
                    2,
                    '.',
                    ''
                ),

                'currency' => $this['summary']['currency'],
            ],

            'recent_invoices' => TaxpayerInvoiceResource::collection(
                $this['recent_invoices']
            ),

            'recent_payments' => TaxpayerPaymentResource::collection(
                $this['recent_payments']
            ),

            'overdue_invoices' => TaxpayerInvoiceResource::collection(
                $this['overdue_invoices']
            ),

            'due_soon_invoices' => TaxpayerInvoiceResource::collection(
                $this['due_soon_invoices']
            ),
        ];
    }
}