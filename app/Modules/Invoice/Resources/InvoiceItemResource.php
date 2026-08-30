<?php

namespace App\Modules\Invoice\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceItemResource extends JsonResource
{
    /**
     * Transform the invoice item into an API response.
     */
    public function toArray(Request $request): array
    {
        return [

            /*
            |--------------------------------------------------------------------------
            | IDENTIFICATION
            |--------------------------------------------------------------------------
            */

            'id' => $this->id,

            'line_number' => $this->line_number,


            /*
            |--------------------------------------------------------------------------
            | REVENUE SERVICE
            |--------------------------------------------------------------------------
            */

            'service' => $this->whenLoaded(
                'service',
                fn () => $this->service
                    ? [
                        'id' => $this->service->id,
                        'name' => $this->service->name,
                        'code' => $this->service->code,
                    ]
                    : null
            ),


            /*
            |--------------------------------------------------------------------------
            | DESCRIPTION
            |--------------------------------------------------------------------------
            */

            'description' => $this->description,


            /*
            |--------------------------------------------------------------------------
            | PRESENTATION VALUES
            |--------------------------------------------------------------------------
            */

            'quantity' => $this->quantity,

            'unit' => $this->unit,

            'unit_price' => $this->unit_price,


            /*
            |--------------------------------------------------------------------------
            | FINANCIAL
            |--------------------------------------------------------------------------
            */

            'financial' => [

                'amount' => $this->amount,

                'discount_amount' => $this->discount_amount,

                'penalty_amount' => $this->penalty_amount,

                'total_amount' => $this->total_amount,

                'currency' => $this->currency,
            ],


            /*
            |--------------------------------------------------------------------------
            | TARIFF SNAPSHOT
            |--------------------------------------------------------------------------
            */

            'tariff' => [

                'version_id' => $this->tariff_version_id,

                'rule_id' => $this->tariff_rule_id,
            ],


            /*
            |--------------------------------------------------------------------------
            | CALCULATION SNAPSHOT
            |--------------------------------------------------------------------------
            */

            'calculation_snapshot' =>
                $this->calculation_snapshot,


            /*
            |--------------------------------------------------------------------------
            | INPUT SNAPSHOT
            |--------------------------------------------------------------------------
            */

            'input_snapshot' =>
                $this->input_snapshot,
        ];
    }
}