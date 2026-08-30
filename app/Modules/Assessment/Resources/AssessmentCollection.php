<?php

namespace App\Modules\Assessment\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;


class AssessmentCollection extends ResourceCollection
{
    public $collects =
        AssessmentResource::class;


    public function toArray(
        Request $request
    ): array {

        return $this->collection
            ->toArray(
                $request
            );
    }


    public function with(
        Request $request
    ): array {

        return [
            'success' => true,

            'message' =>
                'Assessments retrieved successfully.',

            'errors' => null,
        ];
    }


    public function withResponse(
        $request,
        $response
    ): void {

        /*
        |--------------------------------------------------------------------------
        | Keep Laravel pagination metadata
        |--------------------------------------------------------------------------
        |
        | Your frontend ListResponse already supports
        | Laravel pagination through `meta`.
        |
        */
    }
}