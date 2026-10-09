<?php

namespace App\Modules\Assessment\Services;

use App\Models\Assessment;
use App\Models\LeaseAmendment;
use App\Models\LeaseAmendmentChange;
use App\Models\Taxpayer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LeaseAmendmentService
{
    /**
     * Paginate amendments.
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $perPage = min(
            max((int) ($filters['per_page'] ?? 20), 1),
            100
        );

        $query = LeaseAmendment::query()
            ->with([
                'previousAssessment.citizen',
                'newAssessment.citizen',
                'changes',
                'createdBy',
                'approvedBy',
                'rejectedBy',
                'appliedBy',
            ])
            ->latest();

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['amendment_type'])) {
            $query->where(
                'amendment_type',
                $filters['amendment_type']
            );
        }

        if (!empty($filters['previous_assessment_id'])) {
            $query->where(
                'previous_assessment_id',
                $filters['previous_assessment_id']
            );
        }

        if (!empty($filters['search'])) {
            $search = trim($filters['search']);

            $query->where(function (Builder $q) use ($search) {
                $q->where('amendment_number', 'ILIKE', "%{$search}%")
                    ->orWhereHas(
                        'previousAssessment',
                        function (Builder $assessmentQuery) use ($search) {
                            $assessmentQuery
                                ->where(
                                    'assessment_number',
                                    'ILIKE',
                                    "%{$search}%"
                                );
                        }
                    );
            });
        }

        return $query->paginate($perPage);
    }

    /**
     * Find an amendment with all relevant relationships.
     */
    public function find(
        LeaseAmendment $leaseAmendment
    ): LeaseAmendment {
        return $leaseAmendment
            ->load([
                'previousAssessment.citizen',
                'previousAssessment.services',
                'newAssessment.citizen',
                'newAssessment.services',
                'changes.measurementUnit',
                'createdBy',
                'approvedBy',
                'rejectedBy',
                'appliedBy',
                'files',
            ]);
    }

    /**
     * Create amendment.
     */
    public function create(
        array $data,
        $user
    ): LeaseAmendment {
        return DB::transaction(function () use ($data, $user) {

            $assessment = Assessment::query()
                ->with('citizen')
                ->lockForUpdate()
                ->findOrFail($data['previous_assessment_id']);

            $this->ensureAssessmentCanBeAmended($assessment);

            $this->validateAmendmentData(
                $assessment,
                $data
            );

            $amendment = new LeaseAmendment();

            $amendment->id = (string) Str::uuid();

            $amendment->amendment_number =
                $this->generateAmendmentNumber();

            $amendment->previous_assessment_id =
                $assessment->id;

            $amendment->amendment_type =
                $data['amendment_type'];

            $amendment->status = 'DRAFT';

            $amendment->reason =
                trim($data['reason']);

            $amendment->created_by =
                $user->id;

            $amendment->save();

            /*
             * Build authoritative change records from the
             * amendment type rather than blindly trusting
             * frontend "changes".
             */
            $this->createChangeRecords(
                $amendment,
                $assessment,
                $data
            );

            /*
             * Supporting files.
             */
            $this->storeSupportingDocuments(
                $amendment,
                $data['supporting_documents'] ?? [],
                $user
            );

            return $this->find($amendment);
        });
    }

    /**
     * Update a draft amendment.
     */
    public function update(
        LeaseAmendment $leaseAmendment,
        array $data,
        $user
    ): LeaseAmendment {
        return DB::transaction(function () use (
            $leaseAmendment,
            $data,
            $user
        ) {

            $leaseAmendment->load('previousAssessment.citizen');

            $this->ensureDraft($leaseAmendment);

            $assessment = $leaseAmendment->previousAssessment;

            $this->ensureAssessmentCanBeAmended($assessment);

            $this->validateAmendmentData(
                $assessment,
                $data
            );

            $leaseAmendment->amendment_type =
                $data['amendment_type'];

            $leaseAmendment->reason =
                trim($data['reason']);

            $leaseAmendment->save();

            /*
             * Rebuild change records.
             */
            $leaseAmendment
                ->changes()
                ->delete();

            $this->createChangeRecords(
                $leaseAmendment,
                $assessment,
                $data
            );

            /*
             * Add newly uploaded files.
             */
            $this->storeSupportingDocuments(
                $leaseAmendment,
                $data['supporting_documents'] ?? [],
                $user
            );

            return $this->find($leaseAmendment);
        });
    }

    /**
     * Submit for approval.
     */
    public function submit(
        LeaseAmendment $leaseAmendment,
        $user
    ): LeaseAmendment {
        return DB::transaction(function () use (
            $leaseAmendment,
            $user
        ) {

            $leaseAmendment->load(
                'previousAssessment.citizen',
                'changes'
            );

            $this->ensureDraft($leaseAmendment);

            $assessment =
                $leaseAmendment->previousAssessment;

            $this->ensureAssessmentCanBeAmended(
                $assessment
            );

            if (
                empty($leaseAmendment->reason) ||
                strlen(trim($leaseAmendment->reason)) < 5
            ) {
                throw ValidationException::withMessages([
                    'reason' =>
                        'A valid amendment reason is required.',
                ]);
            }

            if ($leaseAmendment->changes->isEmpty()) {
                throw ValidationException::withMessages([
                    'changes' =>
                        'At least one amendment change is required.',
                ]);
            }

            $leaseAmendment->status =
                'PENDING_APPROVAL';

            /*
             * If you have submitted_by/submitted_at columns,
             * populate them here.
             */
            if (
                $this->hasColumn(
                    'lease_amendments',
                    'submitted_by'
                )
            ) {
                $leaseAmendment->submitted_by =
                    $user->id;
            }

            if (
                $this->hasColumn(
                    'lease_amendments',
                    'submitted_at'
                )
            ) {
                $leaseAmendment->submitted_at =
                    now();
            }

            $leaseAmendment->save();

            return $this->find($leaseAmendment);
        });
    }

    /**
     * Approve amendment.
     */
    public function approve(
        LeaseAmendment $leaseAmendment,
        $user
    ): LeaseAmendment {
        return DB::transaction(function () use (
            $leaseAmendment,
            $user
        ) {

            $leaseAmendment->load(
                'previousAssessment',
                'changes'
            );

            if (
                $leaseAmendment->status !==
                'PENDING_APPROVAL'
            ) {
                throw ValidationException::withMessages([
                    'status' =>
                        'Only amendments pending approval can be approved.',
                ]);
            }

            $assessment =
                $leaseAmendment->previousAssessment;

            $this->ensureAssessmentCanBeAmended(
                $assessment
            );

            $leaseAmendment->status =
                'APPROVED';

            $leaseAmendment->approved_by =
                $user->id;

            $leaseAmendment->approved_at =
                now();

            /*
             * If rejected information previously existed,
             * clear it.
             */
            $leaseAmendment->rejected_by = null;
            $leaseAmendment->rejected_at = null;
            $leaseAmendment->rejection_reason = null;

            $leaseAmendment->save();

            return $this->find($leaseAmendment);
        });
    }

    /**
     * Reject amendment.
     */
    public function reject(
        LeaseAmendment $leaseAmendment,
        string $reason,
        $user
    ): LeaseAmendment {
        return DB::transaction(function () use (
            $leaseAmendment,
            $reason,
            $user
        ) {

            if (
                $leaseAmendment->status !==
                'PENDING_APPROVAL'
            ) {
                throw ValidationException::withMessages([
                    'status' =>
                        'Only amendments pending approval can be rejected.',
                ]);
            }

            $leaseAmendment->status =
                'REJECTED';

            $leaseAmendment->rejected_by =
                $user->id;

            $leaseAmendment->rejected_at =
                now();

            $leaseAmendment->rejection_reason =
                trim($reason);

            $leaseAmendment->save();

            return $this->find($leaseAmendment);
        });
    }

    /**
     * Apply approved amendment.
     *
     * The replacement assessment is created separately.
     *
     * This method ONLY:
     *
     * 1. verifies the amendment
     * 2. verifies the new assessment
     * 3. links it
     * 4. marks amendment APPLIED
     *
     * It does not recalculate the old assessment.
     */
    public function apply(
        LeaseAmendment $leaseAmendment,
        array $data,
        $user
    ): LeaseAmendment {
        return DB::transaction(function () use (
            $leaseAmendment,
            $data,
            $user
        ) {

            $leaseAmendment->load(
                'previousAssessment'
            );

            if (
                $leaseAmendment->status !==
                'APPROVED'
            ) {
                throw ValidationException::withMessages([
                    'status' =>
                        'Only approved amendments can be applied.',
                ]);
            }

            if ($leaseAmendment->new_assessment_id) {
                throw ValidationException::withMessages([
                    'new_assessment_id' =>
                        'This amendment has already been linked to a replacement assessment.',
                ]);
            }

            $oldAssessment =
                $leaseAmendment->previousAssessment;

            $newAssessment = Assessment::query()
                ->lockForUpdate()
                ->findOrFail(
                    $data['new_assessment_id']
                );

            /*
             * Prevent linking the same assessment.
             */
            if (
                $newAssessment->id ===
                $oldAssessment->id
            ) {
                throw ValidationException::withMessages([
                    'new_assessment_id' =>
                        'The replacement assessment must be different from the previous assessment.',
                ]);
            }

            /*
             * Replacement assessment should not be draft.
             */
            if (
                !in_array(
                    $newAssessment->status,
                    [
                        'APPROVED',
                        'ISSUED',
                    ],
                    true
                )
            ) {
                throw ValidationException::withMessages([
                    'new_assessment_id' =>
                        'The replacement assessment must be approved or issued before the amendment can be applied.',
                ]);
            }

            /*
             * Link replacement assessment.
             */
            $leaseAmendment->new_assessment_id =
                $newAssessment->id;

            $leaseAmendment->status =
                'APPLIED';

            $leaseAmendment->applied_by =
                $user->id;

            $leaseAmendment->applied_at =
                now();

            $leaseAmendment->save();

            /*
             * IMPORTANT:
             *
             * We intentionally do NOT:
             *
             * - update old assessment land_area
             * - update old assessment citizen
             * - modify old services
             * - modify old payment schedule
             * - transfer payments
             * - recalculate old assessment
             *
             * The new assessment is independent.
             */

            return $this->find($leaseAmendment);
        });
    }

    /**
     * Cancel amendment.
     */
    public function cancel(
        LeaseAmendment $leaseAmendment,
        $user
    ): LeaseAmendment {
        return DB::transaction(function () use (
            $leaseAmendment,
            $user
        ) {

            if (
                !in_array(
                    $leaseAmendment->status,
                    [
                        'DRAFT',
                        'PENDING_APPROVAL',
                        'APPROVED',
                    ],
                    true
                )
            ) {
                throw ValidationException::withMessages([
                    'status' =>
                        'This amendment cannot be cancelled.',
                ]);
            }

            $leaseAmendment->status =
                'CANCELLED';

            /*
             * If you have cancelled_by/cancelled_at,
             * populate them here.
             */
            if (
                $this->hasColumn(
                    'lease_amendments',
                    'cancelled_by'
                )
            ) {
                $leaseAmendment->cancelled_by =
                    $user->id;
            }

            if (
                $this->hasColumn(
                    'lease_amendments',
                    'cancelled_at'
                )
            ) {
                $leaseAmendment->cancelled_at =
                    now();
            }

            $leaseAmendment->save();

            return $this->find($leaseAmendment);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Business Rules
    |--------------------------------------------------------------------------
    */

    private function ensureDraft(
        LeaseAmendment $amendment
    ): void {
        if ($amendment->status !== 'DRAFT') {
            throw ValidationException::withMessages([
                'status' =>
                    'Only draft amendments can be edited.',
            ]);
        }
    }

    private function ensureAssessmentCanBeAmended(
        Assessment $assessment
    ): void {
        if ($assessment->status !== 'APPROVED') {
            throw ValidationException::withMessages([
                'previous_assessment_id' =>
                    'Only approved assessments can be amended.',
            ]);
        }
    }

    private function validateAmendmentData(
        Assessment $assessment,
        array $data
    ): void {
        $type = $data['amendment_type'];

        switch ($type) {

            /*
            |--------------------------------------------------------------------------
            | LAND AREA CHANGE
            |--------------------------------------------------------------------------
            */

            case 'LAND_AREA_CHANGE':

                $newArea =
                    $data['new_land_area'] ?? null;

                if (
                    $newArea === null ||
                    !is_numeric($newArea) ||
                    (float) $newArea <= 0
                ) {
                    throw ValidationException::withMessages([
                        'new_land_area' =>
                            'New land area must be greater than zero.',
                    ]);
                }

                if (
                    (float) $newArea ===
                    (float) $assessment->land_area
                ) {
                    throw ValidationException::withMessages([
                        'new_land_area' =>
                            'New land area must be different from the current land area.',
                    ]);
                }

                break;


            /*
            |--------------------------------------------------------------------------
            | NAME TRANSFER
            |--------------------------------------------------------------------------
            */

            case 'NAME_TRANSFER':

                if (
                    empty($data['new_taxpayer_id'])
                ) {
                    throw ValidationException::withMessages([
                        'new_taxpayer_id' =>
                            'A new taxpayer is required.',
                    ]);
                }

                $this->ensureDifferentTaxpayer(
                    $assessment,
                    $data['new_taxpayer_id']
                );

                break;


            /*
            |--------------------------------------------------------------------------
            | PARTIAL TRANSFER
            |--------------------------------------------------------------------------
            */

            case 'PARTIAL_TRANSFER':

                $transferArea =
                    $data['transfer_area'] ?? null;

                if (
                    $transferArea === null ||
                    !is_numeric($transferArea) ||
                    (float) $transferArea <= 0
                ) {
                    throw ValidationException::withMessages([
                        'transfer_area' =>
                            'Transfer area must be greater than zero.',
                    ]);
                }

                if (
                    (float) $transferArea >=
                    (float) $assessment->land_area
                ) {
                    throw ValidationException::withMessages([
                        'transfer_area' =>
                            'Transfer area must be less than the current land area.',
                    ]);
                }

                if (
                    empty($data['new_taxpayer_id'])
                ) {
                    throw ValidationException::withMessages([
                        'new_taxpayer_id' =>
                            'A new taxpayer is required for a partial transfer.',
                    ]);
                }

                $this->ensureDifferentTaxpayer(
                    $assessment,
                    $data['new_taxpayer_id']
                );

                break;


            /*
            |--------------------------------------------------------------------------
            | MERGE
            |--------------------------------------------------------------------------
            |
            | The other land is manually entered.
            |
            */

            case 'MERGE':

                $otherLandArea =
                    $data['other_land_area'] ?? null;

                if (
                    $otherLandArea === null ||
                    !is_numeric($otherLandArea) ||
                    (float) $otherLandArea <= 0
                ) {
                    throw ValidationException::withMessages([
                        'other_land_area' =>
                            'Other land area must be greater than zero.',
                    ]);
                }

                break;


            default:

                throw ValidationException::withMessages([
                    'amendment_type' =>
                        'Invalid amendment type.',
                ]);
        }
    }

    /**
     * Prevent transfer to the same taxpayer.
     *
     * The assessment stores a citizen while the amendment
     * references a taxpayer, so ID comparison alone may not
     * be safe if they are different namespaces.
     */
    private function ensureDifferentTaxpayer(
        Assessment $assessment,
        string $newTaxpayerId
    ): void {
        $taxpayer = Taxpayer::query()
            ->find($newTaxpayerId);

        if (!$taxpayer) {
            throw ValidationException::withMessages([
                'new_taxpayer_id' =>
                    'Selected taxpayer was not found.',
            ]);
        }

        $currentName =
            trim(strtolower(
                $assessment->citizen?->name ?? ''
            ));

        $newName =
            trim(strtolower(
                $taxpayer->name ?? ''
            ));

        if (
            $currentName !== '' &&
            $currentName === $newName
        ) {
            throw ValidationException::withMessages([
                'new_taxpayer_id' =>
                    'The new taxpayer must be different from the current taxpayer.',
            ]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Change Records
    |--------------------------------------------------------------------------
    */

    private function createChangeRecords(
        LeaseAmendment $amendment,
        Assessment $assessment,
        array $data
    ): void {
        $type = $data['amendment_type'];

        switch ($type) {

            /*
            |--------------------------------------------------------------------------
            | LAND AREA CHANGE
            |--------------------------------------------------------------------------
            */

            case 'LAND_AREA_CHANGE':

                $this->createChange(
                    $amendment,
                    'LAND_AREA',
                    'DECIMAL',
                    $assessment->land_area,
                    $data['new_land_area'],
                    $this->resolveLandAreaUnitId($assessment),
                    $data['reason'],
                    1
                );

                break;


            /*
            |--------------------------------------------------------------------------
            | NAME TRANSFER
            |--------------------------------------------------------------------------
            */

            case 'NAME_TRANSFER':

                $newTaxpayer = Taxpayer::findOrFail(
                    $data['new_taxpayer_id']
                );

                $this->createChange(
                    $amendment,
                    'NAME',
                    'STRING',
                    $assessment->citizen?->name,
                    $newTaxpayer->name,
                    null,
                    $data['reason'],
                    1
                );

                $this->createChange(
                    $amendment,
                    'NEW_TAXPAYER_ID',
                    'UUID',
                    null,
                    $newTaxpayer->id,
                    null,
                    $data['reason'],
                    2
                );

                break;


            /*
            |--------------------------------------------------------------------------
            | PARTIAL TRANSFER
            |--------------------------------------------------------------------------
            */

            case 'PARTIAL_TRANSFER':

                $transferArea =
                    (float) $data['transfer_area'];

                $remainingArea =
                    (float) $assessment->land_area -
                    $transferArea;

                $newTaxpayer = Taxpayer::findOrFail(
                    $data['new_taxpayer_id']
                );

                $unitId =
                    $this->resolveLandAreaUnitId(
                        $assessment
                    );

                $this->createChange(
                    $amendment,
                    'TRANSFER_AREA',
                    'DECIMAL',
                    null,
                    $transferArea,
                    $unitId,
                    $data['reason'],
                    1
                );

                $this->createChange(
                    $amendment,
                    'REMAINING_AREA',
                    'DECIMAL',
                    $assessment->land_area,
                    $remainingArea,
                    $unitId,
                    $data['reason'],
                    2
                );

                $this->createChange(
                    $amendment,
                    'NEW_TAXPAYER_ID',
                    'UUID',
                    null,
                    $newTaxpayer->id,
                    null,
                    $data['reason'],
                    3
                );

                break;


            /*
            |--------------------------------------------------------------------------
            | MERGE
            |--------------------------------------------------------------------------
            */

            case 'MERGE':

                $otherLandArea =
                    (float) $data['other_land_area'];

                $combinedArea =
                    (float) $assessment->land_area +
                    $otherLandArea;

                $unitId =
                    $this->resolveLandAreaUnitId(
                        $assessment
                    );

                /*
                 * Current land -> combined land.
                 */
                $this->createChange(
                    $amendment,
                    'LAND_AREA',
                    'DECIMAL',
                    $assessment->land_area,
                    $combinedArea,
                    $unitId,
                    $data['reason'],
                    1
                );

                /*
                 * Manually entered other land.
                 */
                $this->createChange(
                    $amendment,
                    'OTHER_LAND_AREA',
                    'DECIMAL',
                    null,
                    $otherLandArea,
                    $unitId,
                    $data['reason'],
                    2
                );

                break;
        }
    }

    private function createChange(
        LeaseAmendment $amendment,
        string $fieldName,
        string $valueType,
        mixed $oldValue,
        mixed $newValue,
        ?string $measurementUnitId,
        ?string $reason,
        int $changeOrder
    ): LeaseAmendmentChange {
        $change = new LeaseAmendmentChange();

        $change->id =
            (string) Str::uuid();

        $change->lease_amendment_id =
            $amendment->id;

        $change->field_name =
            $fieldName;

        $change->value_type =
            $valueType;

        $change->old_value =
            $oldValue;

        $change->new_value =
            $newValue;

        $change->measurement_unit_id =
            $measurementUnitId;

        $change->reason =
            $reason;

        $change->change_order =
            $changeOrder;

        $change->save();

        return $change;
    }

    /*
    |--------------------------------------------------------------------------
    | Supporting Documents
    |--------------------------------------------------------------------------
    */

    private function storeSupportingDocuments(
        LeaseAmendment $amendment,
        array $files,
        $user
    ): void {
        foreach ($files as $file) {

            if (!$file) {
                continue;
            }

            /*
             * Use your existing private-file/file service here
             * if you already have one.
             *
             * Do not expose this path directly to the frontend.
             */

            $path = $file->store(
                'lease-amendments/' . $amendment->id,
                'private'
            );

            /*
             * If your project already has a polymorphic files
             * table/service, replace this block with that service.
             */

            if (
                method_exists(
                    $amendment,
                    'files'
                )
            ) {
                $amendment->files()->create([
                    'id' => (string) Str::uuid(),
                    'disk' => 'private',
                    'path' => $path,
                    'original_name' =>
                        $file->getClientOriginalName(),
                    'mime_type' =>
                        $file->getClientMimeType(),
                    'size' =>
                        $file->getSize(),
                    'uploaded_by' =>
                        $user->id,
                ]);
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function generateAmendmentNumber(): string
    {
        $year = now()->year;

        $prefix = "LAM-{$year}-";

        $last = LeaseAmendment::query()
            ->where(
                'amendment_number',
                'like',
                "{$prefix}%"
            )
            ->orderByDesc('amendment_number')
            ->value('amendment_number');

        $next = 1;

        if ($last) {
            $number = (int) Str::after(
                $last,
                $prefix
            );

            $next = $number + 1;
        }

        return $prefix .
            str_pad(
                (string) $next,
                6,
                '0',
                STR_PAD_LEFT
            );
    }

    private function resolveLandAreaUnitId(
        Assessment $assessment
    ): ?string {
        /*
         * If assessment already has a measurement unit
         * relationship, use it here.
         *
         * Otherwise return null.
         *
         * Do not invent a measurement_unit UUID.
         */
        return $assessment->land_area_unit_id ?? null;
    }

    private function hasColumn(
        string $table,
        string $column
    ): bool {
        return Schema::hasColumn(
            $table,
            $column
        );
    }
}