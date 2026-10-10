<?php

namespace App\Modules\Assessment\Resources;

use App\Models\File;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeaseAmendmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $assessment = $this->whenLoaded('previousAssessment');
        $newAssessment = $this->whenLoaded('newAssessment');

        return [
            'id' => $this->id,

            /*
            |--------------------------------------------------------------------------
            | Amendment Information
            |--------------------------------------------------------------------------
            */

            'amendment_number' => $this->amendment_number,
            'amendment_type' => $this->amendment_type,
            'amendment_type_label' => $this->amendmentTypeLabel(),
            'status' => $this->status,
            'status_label' => $this->statusLabel(),
            'reason' => $this->reason,
            'other_amendment_description' =>
                $this->other_amendment_description,

            /*
            |--------------------------------------------------------------------------
            | Previous Assessment
            |--------------------------------------------------------------------------
            */

            'previous_assessment_id' => $this->previous_assessment_id,

            'previous_assessment' => $assessment
                ? new AssessmentResource($assessment)
                : null,

            /*
            |--------------------------------------------------------------------------
            | Replacement Assessment
            |--------------------------------------------------------------------------
            */

            'new_assessment_id' => $this->new_assessment_id,

            'new_assessment' => $newAssessment
                ? new AssessmentResource($newAssessment)
                : null,

            /*
            |--------------------------------------------------------------------------
            | Amendment Changes
            |--------------------------------------------------------------------------
            */

            'changes' => LeaseAmendmentChangeResource::collection(
                $this->whenLoaded('changes')
            ),

            /*
            |--------------------------------------------------------------------------
            | Workflow
            |--------------------------------------------------------------------------
            */

            'workflow' => [
                'created_by' => $this->created_by,

                'created_by_user' => $this->whenLoaded(
                    'createdBy',
                    fn () => $this->formatUser($this->createdBy)
                ),

                'updated_by' => $this->updated_by,

                'updated_by_user' => $this->whenLoaded(
                    'updatedBy',
                    fn () => $this->formatUser($this->updatedBy)
                ),

                'created_at' => $this->created_at,

                'decided_by' => $this->decided_by,

                'decided_by_user' => $this->whenLoaded(
                    'decidedBy',
                    fn () => $this->formatUser($this->decidedBy)
                ),

                'decision_notes' => $this->decision_notes,
                'decided_at' => $this->decided_at,
                'approved_at' => $this->approved_at,
                'rejected_at' => $this->rejected_at,

                'applied_by' => $this->applied_by,

                'applied_by_user' => $this->whenLoaded(
                    'appliedBy',
                    fn () => $this->formatUser($this->appliedBy)
                ),

                'applied_at' => $this->applied_at,
            ],

            /*
            |--------------------------------------------------------------------------
            | Supporting Documents
            |--------------------------------------------------------------------------
            |
            | Serialize the existing File model directly. Do not expose
            | internal storage paths, stored filenames, or disk names.
            |
            */

            'supporting_documents' => $this->whenLoaded(
                'files',
                fn () => $this->files
                    ->map(fn (File $file) => $this->formatFile($file))
                    ->values()
            ),

            /*
            |--------------------------------------------------------------------------
            | Metadata
            |--------------------------------------------------------------------------
            */

            'metadata' => $this->metadata,

            /*
            |--------------------------------------------------------------------------
            | Timestamps
            |--------------------------------------------------------------------------
            */

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * Format a related user without depending on a UserResource class.
     */
    private function formatUser($user): ?array
    {
        if (! $user) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name ?? null,
            'email' => $user->email ?? null,
        ];
    }

    /**
     * Format supporting-document metadata.
     */
    private function formatFile(File $file): array
    {
        return [
            'id' => $file->id,
            'uuid' => $file->uuid,          // add this
            'original_name' => $file->original_name,
            'mime_type' => $file->mime_type,
            'extension' => $file->extension,
            'size_bytes' => $file->size_bytes,
            'size_in_kb' => $file->size_in_kb,
            'visibility' => $file->visibility,
            'status' => $file->status,
            'uploaded_at' => $file->uploaded_at?->toISOString(),
            'created_at' => $file->created_at?->toISOString(),
        ];
    }

    /**
     * Return a human-readable amendment type.
     */
    private function amendmentTypeLabel(): string
    {
        return match ($this->amendment_type) {
            'LAND_AREA_CHANGE' => 'Land Area Change',
            'NAME_TRANSFER' => 'Name Transfer',
            'PARTIAL_TRANSFER' => 'Partial Transfer',
            'MERGE' => 'Land Merge',
            default => (string) $this->amendment_type,
        };
    }

    /**
     * Return a human-readable workflow status.
     */
    private function statusLabel(): string
    {
        return match ($this->status) {
            'DRAFT' => 'Draft',
            'PENDING_APPROVAL' => 'Pending Approval',
            'APPROVED' => 'Approved',
            'REJECTED' => 'Rejected',
            'CANCELLED' => 'Cancelled',
            'APPLIED' => 'Applied',
            default => (string) $this->status,
        };
    }
}