<?php

namespace App\Modules\Assessment\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;


class AssessmentSummaryResource extends JsonResource
{
    public function toArray(
        Request $request
    ): array {

        return [

            'total' =>
                $this->resource['total']
                ?? 0,

            'draft' =>
                $this->resource['draft']
                ?? 0,

            'pendingApproval' =>
                $this->resource['pendingApproval']
                ?? 0,

            'approved' =>
                $this->resource['approved']
                ?? 0,

            'rejected' =>
                $this->resource['rejected']
                ?? 0,

            'cancelled' =>
                $this->resource['cancelled']
                ?? 0,
        ];
    }
}