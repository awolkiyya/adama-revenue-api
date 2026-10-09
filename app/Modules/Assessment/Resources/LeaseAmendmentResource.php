<?php

namespace App\Modules\Assessment\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeaseAmendmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $assessment =
            $this->whenLoaded('previousAssessment');

        $newAssessment =
            $this->whenLoaded('newAssessment');

        return [
            'id' =>
                $this->id,

            'amendment_number' =>
                $this->amendment_number,

            'amendment_type' =>
                $this->amendment_type,

            'amendment_type_label' =>
                $this->amendmentTypeLabel(),

            'status' =>
                $this->status,

            'status_label' =>
                $this->statusLabel(),

            'reason' =>
                $this->reason,

            /*
            |--------------------------------------------------------------------------
            | Previous Assessment
            |--------------------------------------------------------------------------
            */

            'previous_assessment' =>
                $assessment
                    ? new AssessmentResource($assessment)
                    : null,

            'previous_assessment_id' =>
                $this->previous_assessment_id,

            /*
            |--------------------------------------------------------------------------
            | New Assessment
            |--------------------------------------------------------------------------
            |
            | Nullable until the independent replacement assessment
            | has been created.
            |
            */

            'new_assessment_id' =>
                $this->new_assessment_id,

            'new_assessment' =>
                $newAssessment
                    ? new AssessmentResource($newAssessment)
                    : null,

            /*
            |--------------------------------------------------------------------------
            | Changes
            |--------------------------------------------------------------------------
            */

            'changes' =>
                LeaseAmendmentChangeResource::collection(
                    $this->whenLoaded('changes')
                ),

            /*
            |--------------------------------------------------------------------------
            | Workflow
            |--------------------------------------------------------------------------
            */

            'workflow' => [
                'created_by' =>
                    $this->createdBy
                        ? new UserResource($this->createdBy)
                        : null,

                'created_at' =>
                    $this->created_at,

                'approved_by' =>
                    $this->approvedBy
                        ? new UserResource($this->approvedBy)
                        : null,

                'approved_at' =>
                    $this->approved_at,

                'rejected_by' =>
                    $this->rejectedBy
                        ? new UserResource($this->rejectedBy)
                        : null,

                'rejected_at' =>
                    $this->rejected_at,

                'rejection_reason' =>
                    $this->rejection_reason,

                'applied_by' =>
                    $this->appliedBy
                        ? new UserResource($this->appliedBy)
                        : null,

                'applied_at' =>
                    $this->applied_at,
            ],

            /*
            |--------------------------------------------------------------------------
            | Files
            |--------------------------------------------------------------------------
            */

            'supporting_documents' =>
                $this->whenLoaded(
                    'files',
                    fn () => FileResource::collection(
                        $this->files
                    )
                ),

            'created_at' =>
                $this->created_at,

            'updated_at' =>
                $this->updated_at,
        ];
    }

    private function amendmentTypeLabel(): string
    {
        return match ($this->amendment_type) {
            'LAND_AREA_CHANGE' =>
                'Land Area Change',

            'NAME_TRANSFER' =>
                'Name Transfer',

            'PARTIAL_TRANSFER' =>
                'Partial Transfer',

            'MERGE' =>
                'Land Merge',

            default =>
                $this->amendment_type,
        };
    }

    private function statusLabel(): string
    {
        return match ($this->status) {
            'DRAFT' =>
                'Draft',

            'PENDING_APPROVAL' =>
                'Pending Approval',

            'APPROVED' =>
                'Approved',

            'REJECTED' =>
                'Rejected',

            'CANCELLED' =>
                'Cancelled',

            'APPLIED' =>
                'Applied',

            default =>
                $this->status,
        };
    }
}