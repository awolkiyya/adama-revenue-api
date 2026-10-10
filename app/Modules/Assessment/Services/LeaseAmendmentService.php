<?php

namespace App\Modules\Assessment\Services;

use App\Models\Assessment;
use App\Models\Citizen;
use App\Models\LeaseAmendment;
use App\Models\LeaseAmendmentChange;
use App\Models\User;
use App\Services\Authorization\AdministrativeScopeService;
use App\Services\Authorization\SectorAccessService;
use App\Services\DocumentSequenceService;
use App\Modules\Assessment\Services\LeaseAmendmentFinancialService;
use App\Services\Storage\StorageService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class LeaseAmendmentService
{
    private const LAND_AREA_FIELD_CODES = [
        'LAND_AREA',
        'LANDAREA',
        'LAND_SIZE',
        'PLOT_AREA',
        'PARCEL_AREA',
    ];

    private const AMENDMENT_TYPES = [
        'OWNERSHIP_TRANSFER',
        'LAND_AREA_CHANGE',
        'PARTIAL_TRANSFER',
        'LAND_MERGE',
        'OTHER',
    ];

    private const BLOCKING_STATUSES = [
        'PENDING_APPROVAL',
        'APPROVED',
        'APPLIED',
    ];

    private const RESOURCE_RELATIONSHIPS = [
        'previousAssessment.citizen',
        'previousAssessment.services.values.measurementUnit',
        'newAssessment.citizen',
        'newAssessment.services.values.measurementUnit',
        'changes.measurementUnit',
        'createdBy',
        'updatedBy',
        'decidedBy',
        'appliedBy',
        'files',
    ];

    public function __construct(
        private readonly StorageService $storageService,
        private readonly DocumentSequenceService $documentSequenceService,
        private readonly AdministrativeScopeService $administrativeScopeService,
        private readonly SectorAccessService $sectorAccessService,
        private readonly LeaseAmendmentFinancialService $financialService,
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    */

    private function isGlobalUser(User $user): bool
    {
        return $this->administrativeScopeService->isGlobal($user);
    }

    private function assertAssessmentInScope(
        Assessment $assessment,
        User $user,
        string $field = 'previous_assessment_id'
    ): void {
        if ($this->isGlobalUser($user)) {
            return;
        }

        if (
            !$assessment->administrative_unit_id ||
            !$this->administrativeScopeService->canAccessUnit(
                $user,
                (string) $assessment->administrative_unit_id
            )
        ) {
            throw ValidationException::withMessages([
                $field =>
                    'You are not authorized to access this assessment in your assigned administrative unit.',
            ]);
        }

        if (!$this->sectorAccessService->hasSector($user)) {
            throw ValidationException::withMessages([
                $field =>
                    'Your user account is not assigned to a sector.',
            ]);
        }

        if (
            !$this->sectorAccessService->canAccessEntireAssessment(
                $user,
                $assessment
            )
        ) {
            throw ValidationException::withMessages([
                $field =>
                    'Your assigned sector is not authorized to access every service associated with this assessment.',
            ]);
        }
    }

    private function assertAmendmentInScope(
        LeaseAmendment $amendment,
        User $user
    ): void {
        if ($this->isGlobalUser($user)) {
            return;
        }

        $amendment->loadMissing('previousAssessment');

        $assessment = $amendment->previousAssessment;

        if (!$assessment) {
            throw ValidationException::withMessages([
                'previous_assessment_id' =>
                    'The original assessment associated with this amendment was not found.',
            ]);
        }

        $this->assertAssessmentInScope($assessment, $user);
    }

    private function applyAssessmentScope(
        Builder $query,
        User $user
    ): void {
        $this->sectorAccessService->scopeAssessmentsForUser(
            $query,
            $user,
            requireAllServices: true
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Queries
    |--------------------------------------------------------------------------
    */

    public function paginate(
        array $filters,
        User $user
    ): LengthAwarePaginator {
        $perPage = min(
            max((int) ($filters['per_page'] ?? 20), 1),
            100
        );

        $query = LeaseAmendment::query()
            ->with(self::RESOURCE_RELATIONSHIPS)
            ->whereHas(
                'previousAssessment',
                function (Builder $assessmentQuery) use ($user) {
                    $this->applyAssessmentScope(
                        $assessmentQuery,
                        $user
                    );
                }
            )
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
            $search = trim((string) $filters['search']);

            $query->where(function (Builder $builder) use ($search) {
                $builder
                    ->where(
                        'amendment_number',
                        'ILIKE',
                        '%' . $search . '%'
                    )
                    ->orWhereHas(
                        'previousAssessment',
                        function (Builder $assessmentQuery) use ($search) {
                            $assessmentQuery->where(
                                'assessment_number',
                                'ILIKE',
                                '%' . $search . '%'
                            );
                        }
                    );
            });
        }

        return $query->paginate($perPage);
    }

    public function summary(
        array $filters,
        User $user
    ): array {
        $query = LeaseAmendment::query()
            ->whereHas(
                'previousAssessment',
                function (Builder $assessmentQuery) use ($user) {
                    $this->applyAssessmentScope(
                        $assessmentQuery,
                        $user
                    );
                }
            );

        if (!empty($filters['previous_assessment_id'])) {
            $query->where(
                'previous_assessment_id',
                $filters['previous_assessment_id']
            );
        }

        if (!empty($filters['amendment_type'])) {
            $query->where(
                'amendment_type',
                $filters['amendment_type']
            );
        }

        $counts = (clone $query)
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'total_amendments' => (int) $counts->sum(),
            'draft' => (int) ($counts['DRAFT'] ?? 0),
            'pending_approval' => (int) ($counts['PENDING_APPROVAL'] ?? 0),
            'approved' => (int) ($counts['APPROVED'] ?? 0),
            'applied' => (int) ($counts['APPLIED'] ?? 0),
            'rejected' => (int) ($counts['REJECTED'] ?? 0),
            'cancelled' => (int) ($counts['CANCELLED'] ?? 0),
        ];
    }

    public function find(
        LeaseAmendment $leaseAmendment,
        User $user
    ): LeaseAmendment {
        $amendment = LeaseAmendment::query()
            ->with(self::RESOURCE_RELATIONSHIPS)
            ->findOrFail($leaseAmendment->id);

        $this->assertAmendmentInScope($amendment, $user);

        return $amendment;
    }

    /*
    |--------------------------------------------------------------------------
    | Create
    |--------------------------------------------------------------------------
    */

    public function create(
        array $data,
        User $user
    ): LeaseAmendment {
        return DB::transaction(function () use ($data, $user) {
            $assessmentId = $data['previous_assessment_id'] ?? null;

            if (!$assessmentId) {
                throw ValidationException::withMessages([
                    'previous_assessment_id' =>
                        'An original assessment is required.',
                ]);
            }

            $assessment = Assessment::query()
                ->with('citizen')
                ->lockForUpdate()
                ->findOrFail($assessmentId);

            $this->assertAssessmentInScope($assessment, $user);
            $this->ensureAssessmentCanBeAmended($assessment);

            $this->ensureNoBlockingAmendment(
                (string) $assessment->id
            );

            $reason = trim((string) ($data['reason'] ?? ''));

            if (mb_strlen($reason) < 5) {
                throw ValidationException::withMessages([
                    'reason' =>
                        'The amendment reason must contain at least 5 characters.',
                ]);
            }

            $this->validateAmendmentData($assessment, $data);

            $amendment = new LeaseAmendment();
            $amendment->id = (string) Str::uuid();

            $amendment->amendment_number =
                $this->documentSequenceService->generate(
                    'lease_amendment'
                );

            $amendment->previous_assessment_id = $assessment->id;
            $amendment->amendment_type = $data['amendment_type'];
            $amendment->status = 'DRAFT';
            $amendment->reason = $reason;
            $amendment->submitted_at = null;

            $amendment->other_amendment_description =
                $data['other_amendment_description'] ?? null;

            $amendment->created_by = $user->id;
            $amendment->updated_by = $user->id;
            $amendment->save();

            $this->createChangeRecords(
                $amendment,
                $assessment,
                $data
            );

            if (!$amendment->changes()->exists()) {
                throw ValidationException::withMessages([
                    'changes' =>
                        'No amendment changes were generated. Verify the amendment type and its required input fields.',
                ]);
            }

            $this->storeSupportingDocuments(
                $amendment,
                $data['supporting_documents'] ?? [],
                $user
            );

            return $this->find($amendment, $user);
        }, 5);
    }

    /*
    |--------------------------------------------------------------------------
    | Duplicate Amendment Protection
    |--------------------------------------------------------------------------
    */

    private function ensureNoBlockingAmendment(
        string $assessmentId,
        ?string $exceptAmendmentId = null
    ): void {
        $query = LeaseAmendment::query()
            ->where('previous_assessment_id', $assessmentId)
            ->whereIn('status', self::BLOCKING_STATUSES);

        if ($exceptAmendmentId !== null) {
            $query->where('id', '!=', $exceptAmendmentId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'previous_assessment_id' =>
                    'This assessment already has an amendment that is pending approval, approved, or applied. Resolve the existing amendment before creating another one.',
            ]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Update
    |--------------------------------------------------------------------------
    */

    public function update(
        LeaseAmendment $leaseAmendment,
        array $data,
        User $user
    ): LeaseAmendment {
        return DB::transaction(function () use (
            $leaseAmendment,
            $data,
            $user
        ) {
            $amendment = LeaseAmendment::query()
                ->lockForUpdate()
                ->findOrFail($leaseAmendment->id);

            $this->assertAmendmentInScope($amendment, $user);
            $this->ensureDraft($amendment);

            $assessment = Assessment::query()
                ->with('citizen')
                ->lockForUpdate()
                ->findOrFail($amendment->previous_assessment_id);

            $this->assertAssessmentInScope($assessment, $user);
            $this->ensureAssessmentCanBeAmended($assessment);

            $this->ensureNoBlockingAmendment(
                (string) $assessment->id,
                (string) $amendment->id
            );

            $reason = trim((string) ($data['reason'] ?? ''));

            if (mb_strlen($reason) < 5) {
                throw ValidationException::withMessages([
                    'reason' =>
                        'The amendment reason must contain at least 5 characters.',
                ]);
            }

            $this->validateAmendmentData($assessment, $data);

            $amendment->amendment_type = $data['amendment_type'];
            $amendment->reason = $reason;

            $amendment->other_amendment_description =
                $data['other_amendment_description'] ?? null;

            $amendment->updated_by = $user->id;
            $amendment->save();

            $amendment->changes()->delete();

            $this->createChangeRecords(
                $amendment,
                $assessment,
                $data
            );

            if (!$amendment->changes()->exists()) {
                throw ValidationException::withMessages([
                    'changes' =>
                        'No amendment changes were generated. Verify the amendment type and its required input fields.',
                ]);
            }

            $this->storeSupportingDocuments(
                $amendment,
                $data['supporting_documents'] ?? [],
                $user
            );

            return $this->find($amendment, $user);
        }, 5);
    }

    /*
    |--------------------------------------------------------------------------
    | Submit
    |--------------------------------------------------------------------------
    */

    public function submit(
        LeaseAmendment $leaseAmendment,
        User $user
    ): LeaseAmendment {
        return DB::transaction(function () use (
            $leaseAmendment,
            $user
        ) {
            $amendment = LeaseAmendment::query()
                ->with('changes')
                ->lockForUpdate()
                ->findOrFail($leaseAmendment->id);

            $this->assertAmendmentInScope($amendment, $user);
            $this->ensureDraft($amendment);

            $assessment = Assessment::query()
                ->lockForUpdate()
                ->findOrFail($amendment->previous_assessment_id);

            $this->assertAssessmentInScope($assessment, $user);
            $this->ensureAssessmentCanBeAmended($assessment);

            $this->ensureNoBlockingAmendment(
                (string) $assessment->id,
                (string) $amendment->id
            );

            if (
                !is_string($amendment->reason) ||
                mb_strlen(trim($amendment->reason)) < 5
            ) {
                throw ValidationException::withMessages([
                    'reason' => 'A valid amendment reason is required.',
                ]);
            }

            if ($amendment->changes->isEmpty()) {
                throw ValidationException::withMessages([
                    'changes' =>
                        'At least one amendment change is required.',
                ]);
            }

            $amendment->status = 'PENDING_APPROVAL';
            $amendment->submitted_at = now();
            $amendment->updated_by = $user->id;
            $amendment->save();

            return $this->find($amendment, $user);
        }, 5);
    }

    /*
    |--------------------------------------------------------------------------
    | Approve
    |--------------------------------------------------------------------------
    */

    public function approve(
        LeaseAmendment $leaseAmendment,
        User $user
    ): LeaseAmendment {
        return DB::transaction(function () use (
            $leaseAmendment,
            $user
        ) {
            $amendment = LeaseAmendment::query()
                ->lockForUpdate()
                ->findOrFail($leaseAmendment->id);

            $this->assertAmendmentInScope($amendment, $user);

            if ($amendment->status !== 'PENDING_APPROVAL') {
                throw ValidationException::withMessages([
                    'status' =>
                        'Only amendments pending approval can be approved.',
                ]);
            }

            $assessment = Assessment::query()
                ->lockForUpdate()
                ->findOrFail($amendment->previous_assessment_id);

            $this->assertAssessmentInScope($assessment, $user);
            $this->ensureAssessmentCanBeAmended($assessment);

            if (!$amendment->changes()->exists()) {
                throw ValidationException::withMessages([
                    'changes' =>
                        'The amendment has no recorded changes.',
                ]);
            }

            $now = now();

            $amendment->status = 'APPROVED';
            $amendment->decided_by = $user->id;
            $amendment->decision_notes = null;
            $amendment->decided_at = $now;
            $amendment->approved_at = $now;
            $amendment->rejected_at = null;
            $amendment->updated_by = $user->id;
            $amendment->save();

            return $this->find($amendment, $user);
        }, 5);
    }

    /*
    |--------------------------------------------------------------------------
    | Reject
    |--------------------------------------------------------------------------
    */

    public function reject(
        LeaseAmendment $leaseAmendment,
        string $reason,
        User $user
    ): LeaseAmendment {
        return DB::transaction(function () use (
            $leaseAmendment,
            $reason,
            $user
        ) {
            $amendment = LeaseAmendment::query()
                ->lockForUpdate()
                ->findOrFail($leaseAmendment->id);

            $this->assertAmendmentInScope($amendment, $user);

            if ($amendment->status !== 'PENDING_APPROVAL') {
                throw ValidationException::withMessages([
                    'status' =>
                        'Only amendments pending approval can be rejected.',
                ]);
            }

            $reason = trim($reason);

            if (mb_strlen($reason) < 5) {
                throw ValidationException::withMessages([
                    'reason' =>
                        'The rejection reason must contain at least 5 characters.',
                ]);
            }

            $now = now();

            $amendment->status = 'REJECTED';
            $amendment->decided_by = $user->id;
            $amendment->decision_notes = $reason;
            $amendment->decided_at = $now;
            $amendment->rejected_at = $now;
            $amendment->updated_by = $user->id;
            $amendment->save();

            return $this->find($amendment, $user);
        }, 5);
    }

/*
|--------------------------------------------------------------------------
| Apply
|--------------------------------------------------------------------------
*/

public function apply(
    LeaseAmendment $leaseAmendment,
    User $user
): LeaseAmendment {
    return DB::transaction(function () use (
        $leaseAmendment,
        $user
    ) {
        $amendment = LeaseAmendment::query()
            ->with('changes')
            ->lockForUpdate()
            ->findOrFail($leaseAmendment->id);

        $this->assertAmendmentInScope($amendment, $user);

        if ($amendment->status !== 'APPROVED') {
            throw ValidationException::withMessages([
                'status' => 'Only approved amendments can be applied.',
            ]);
        }

        if ($amendment->status === 'APPLIED') {
            throw ValidationException::withMessages([
                'status' => 'This amendment has already been applied.',
            ]);
        }

        $oldAssessment = Assessment::query()
            ->with('citizen')
            ->lockForUpdate()
            ->findOrFail($amendment->previous_assessment_id);

        $this->assertAssessmentInScope($oldAssessment, $user);

        /*
         * Apply the approved lease changes to the existing assessment
         * and manage its payment schedules.
         *
         * This validates the financial obligations and closes eligible
         * future schedules. It does not create a replacement assessment.
         */
        $this->financialService
            ->validateAndCloseFutureSchedules($oldAssessment);

        /*
         * Mark the amendment as applied only after financial validation
         * and schedule management succeed.
         */
        $amendment->status = 'APPLIED';
        $amendment->applied_by = $user->id;
        $amendment->applied_at = now();
        $amendment->updated_by = $user->id;
        $amendment->save();

        return $this->find($amendment, $user);
    }, 5);
}

    private function validateReplacementAssessment(
        LeaseAmendment $amendment,
        Assessment $oldAssessment,
        Assessment $newAssessment
    ): void {
        if (in_array(
            $amendment->amendment_type,
            ['OWNERSHIP_TRANSFER', 'PARTIAL_TRANSFER'],
            true
        )) {
            $change = $amendment->changes()
                ->where('field_name', 'NEW_TAXPAYER_ID')
                ->first();

            $newCitizenId = $this->changeValue(
                $change?->new_value
            );

            if (
                !$newCitizenId ||
                (string) $newAssessment->citizen_id !==
                    (string) $newCitizenId
            ) {
                throw ValidationException::withMessages([
                    'new_assessment_id' =>
                        'The replacement assessment must belong to the citizen recorded in the ownership-transfer change.',
                ]);
            }

            return;
        }

        if (
            (string) $newAssessment->citizen_id !==
            (string) $oldAssessment->citizen_id
        ) {
            throw ValidationException::withMessages([
                'new_assessment_id' =>
                    'The replacement assessment must retain the current citizen for this amendment type.',
            ]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Cancel
    |--------------------------------------------------------------------------
    */

    public function cancel(
        LeaseAmendment $leaseAmendment,
        User $user
    ): LeaseAmendment {
        return DB::transaction(function () use (
            $leaseAmendment,
            $user
        ) {
            $amendment = LeaseAmendment::query()
                ->lockForUpdate()
                ->findOrFail($leaseAmendment->id);

            $this->assertAmendmentInScope($amendment, $user);

            if (!in_array(
                $amendment->status,
                ['DRAFT', 'PENDING_APPROVAL', 'APPROVED'],
                true
            )) {
                throw ValidationException::withMessages([
                    'status' =>
                        'This amendment cannot be cancelled.',
                ]);
            }

            $amendment->status = 'CANCELLED';
            $amendment->updated_by = $user->id;
            $amendment->save();

            return $this->find($amendment, $user);
        }, 5);
    }

    /*
    |--------------------------------------------------------------------------
    | Workflow Validation
    |--------------------------------------------------------------------------
    */

    private function ensureDraft(
        LeaseAmendment $amendment
    ): void {
        if ($amendment->status !== 'DRAFT') {
            throw ValidationException::withMessages([
                'status' =>
                    'Only draft amendments can be edited or submitted.',
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
        $type = $data['amendment_type'] ?? null;

        if (!in_array($type, self::AMENDMENT_TYPES, true)) {
            throw ValidationException::withMessages([
                'amendment_type' => 'Invalid amendment type.',
            ]);
        }

        switch ($type) {
            case 'LAND_AREA_CHANGE':
                $currentArea = $this->getLandAreaInfo(
                    $assessment
                )['area'];

                $newArea = $data['new_land_area'] ?? null;

                $this->assertPositiveNumber(
                    $newArea,
                    'new_land_area',
                    'New land area must be greater than zero.'
                );

                if (
                    abs((float) $newArea - $currentArea) < 0.000001
                ) {
                    throw ValidationException::withMessages([
                        'new_land_area' =>
                            'New land area must differ from the current land area.',
                    ]);
                }

                break;

            case 'OWNERSHIP_TRANSFER':
                $newCitizen = $this->resolveNewCitizen(
                    $data['new_taxpayer_id'] ?? null
                );

                $this->ensureDifferentCitizen(
                    $assessment,
                    $newCitizen
                );

                break;

            case 'PARTIAL_TRANSFER':
                $currentArea = $this->getLandAreaInfo(
                    $assessment
                )['area'];

                $transferArea = $data['transfer_area'] ?? null;

                $this->assertPositiveNumber(
                    $transferArea,
                    'transfer_area',
                    'Transfer area must be greater than zero.'
                );

                if ((float) $transferArea >= $currentArea) {
                    throw ValidationException::withMessages([
                        'transfer_area' =>
                            'Transfer area must be less than the current land area.',
                    ]);
                }

                $newCitizen = $this->resolveNewCitizen(
                    $data['new_taxpayer_id'] ?? null
                );

                $this->ensureDifferentCitizen(
                    $assessment,
                    $newCitizen
                );

                break;

            case 'LAND_MERGE':
                $otherArea = $data['other_land_area'] ?? null;

                $this->assertPositiveNumber(
                    $otherArea,
                    'other_land_area',
                    'Other land area must be greater than zero.'
                );

                break;

            case 'OTHER':
                $description = trim(
                    (string) (
                        $data['other_amendment_description'] ?? ''
                    )
                );

                if ($description === '') {
                    throw ValidationException::withMessages([
                        'other_amendment_description' =>
                            'A description is required for this amendment type.',
                    ]);
                }

                break;
        }
    }

    private function assertPositiveNumber(
        mixed $value,
        string $field,
        string $message
    ): void {
        if (
            $value === null ||
            $value === '' ||
            !is_numeric($value) ||
            !is_finite((float) $value) ||
            (float) $value <= 0
        ) {
            throw ValidationException::withMessages([
                $field => $message,
            ]);
        }
    }

    private function resolveNewCitizen(
        mixed $citizenId
    ): Citizen {
        if (
            !is_string($citizenId) ||
            trim($citizenId) === ''
        ) {
            throw ValidationException::withMessages([
                'new_taxpayer_id' =>
                    'A new citizen is required.',
            ]);
        }

        return Citizen::query()->findOrFail($citizenId);
    }

    private function ensureDifferentCitizen(
        Assessment $assessment,
        Citizen $newCitizen
    ): void {
        if (
            (string) $assessment->citizen_id ===
            (string) $newCitizen->id
        ) {
            throw ValidationException::withMessages([
                'new_taxpayer_id' =>
                    'The new citizen must differ from the current citizen.',
            ]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Land Area Lookup
    |--------------------------------------------------------------------------
    */

    private function getLandAreaInfo(
        Assessment $assessment
    ): array {
        $assessment->loadMissing(
            'services.values.measurementUnit'
        );

        foreach ($assessment->services as $service) {
            foreach ($service->values as $fieldValue) {
                $fieldCode = strtoupper(
                    trim((string) $fieldValue->field_code)
                );

                if (!in_array(
                    $fieldCode,
                    self::LAND_AREA_FIELD_CODES,
                    true
                )) {
                    continue;
                }

                $value = $this->decodeFieldValue(
                    $fieldValue->value
                );

                if (
                    !is_numeric($value) ||
                    !is_finite((float) $value) ||
                    (float) $value <= 0
                ) {
                    throw ValidationException::withMessages([
                        'previous_assessment_id' =>
                            'The assessment has an invalid land-area value.',
                    ]);
                }

                return [
                    'area' => (float) $value,
                    'unit_id' => $fieldValue->measurement_unit_id,
                    'field_value' => $fieldValue,
                ];
            }
        }

        throw ValidationException::withMessages([
            'previous_assessment_id' =>
                'The land-area field was not found in the assessment service values. Verify LAND_AREA_FIELD_CODES against the configured field_code values.',
        ]);
    }

    private function decodeFieldValue(
        mixed $value
    ): mixed {
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                $value = $decoded;
            }
        }

        if (
            is_array($value) &&
            array_key_exists('value', $value)
        ) {
            return $value['value'];
        }

        return $value;
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
        $type = $data['amendment_type'] ?? null;
        $reason = trim((string) ($data['reason'] ?? ''));

        switch ($type) {
            case 'LAND_AREA_CHANGE':
                $land = $this->getLandAreaInfo($assessment);

                $this->createChange(
                    $amendment,
                    'LAND_AREA',
                    'DECIMAL',
                    $land['area'],
                    (float) $data['new_land_area'],
                    $land['unit_id'],
                    $reason,
                    1
                );

                break;

            case 'OWNERSHIP_TRANSFER':
                $newCitizen = $this->resolveNewCitizen(
                    $data['new_taxpayer_id'] ?? null
                );

                $this->createChange(
                    $amendment,
                    'NAME',
                    'STRING',
                    $assessment->citizen?->name,
                    $newCitizen->name,
                    null,
                    $reason,
                    1
                );

                $this->createChange(
                    $amendment,
                    'NEW_TAXPAYER_ID',
                    'UUID',
                    null,
                    $newCitizen->id,
                    null,
                    $reason,
                    2
                );

                break;

            case 'PARTIAL_TRANSFER':
                $land = $this->getLandAreaInfo($assessment);

                $transferArea = (float) $data['transfer_area'];
                $remainingArea = $land['area'] - $transferArea;

                $newCitizen = $this->resolveNewCitizen(
                    $data['new_taxpayer_id'] ?? null
                );

                $this->createChange(
                    $amendment,
                    'TRANSFER_AREA',
                    'DECIMAL',
                    null,
                    $transferArea,
                    $land['unit_id'],
                    $reason,
                    1
                );

                $this->createChange(
                    $amendment,
                    'REMAINING_AREA',
                    'DECIMAL',
                    $land['area'],
                    $remainingArea,
                    $land['unit_id'],
                    $reason,
                    2
                );

                $this->createChange(
                    $amendment,
                    'NEW_TAXPAYER_ID',
                    'UUID',
                    null,
                    $newCitizen->id,
                    null,
                    $reason,
                    3
                );

                break;

            case 'LAND_MERGE':
                $land = $this->getLandAreaInfo($assessment);

                $otherArea = (float) $data['other_land_area'];
                $combinedArea = $land['area'] + $otherArea;

                $this->createChange(
                    $amendment,
                    'LAND_AREA',
                    'DECIMAL',
                    $land['area'],
                    $combinedArea,
                    $land['unit_id'],
                    $reason,
                    1
                );

                $this->createChange(
                    $amendment,
                    'OTHER_LAND_AREA',
                    'DECIMAL',
                    null,
                    $otherArea,
                    $land['unit_id'],
                    $reason,
                    2
                );

                break;

            case 'OTHER':
                $description = trim(
                    (string) (
                        $data['other_amendment_description'] ?? ''
                    )
                );

                $this->createChange(
                    $amendment,
                    'OTHER_AMENDMENT_DESCRIPTION',
                    'STRING',
                    null,
                    $description,
                    null,
                    $reason,
                    1
                );

                break;

            default:
                throw ValidationException::withMessages([
                    'amendment_type' =>
                        'No change-record handler exists for this amendment type.',
                ]);
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

        $change->id = (string) Str::uuid();
        $change->lease_amendment_id = $amendment->id;
        $change->field_name = $fieldName;
        $change->value_type = $valueType;

        $change->old_value = $oldValue === null
            ? null
            : ['value' => $oldValue];

        $change->new_value = $newValue === null
            ? null
            : ['value' => $newValue];

        $change->measurement_unit_id = $measurementUnitId;
        $change->reason = $reason;
        $change->change_order = $changeOrder;
        $change->save();

        return $change;
    }

    private function changeValue(
        mixed $value
    ): mixed {
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                $value = $decoded;
            }
        }

        if (
            is_array($value) &&
            array_key_exists('value', $value)
        ) {
            return $value['value'];
        }

        return $value;
    }

    /*
    |--------------------------------------------------------------------------
    | Supporting Documents
    |--------------------------------------------------------------------------
    */

    private function storeSupportingDocuments(
        LeaseAmendment $amendment,
        array $files,
        User $user
    ): void {
        $uploadedFiles = [];

        try {
            foreach ($files as $file) {
                if (!$file instanceof UploadedFile) {
                    throw ValidationException::withMessages([
                        'supporting_documents' =>
                            'The supporting documents payload contains an invalid file.',
                    ]);
                }

                if (!$file->isValid()) {
                    throw ValidationException::withMessages([
                        'supporting_documents' =>
                            'One of the uploaded supporting documents is invalid.',
                    ]);
                }

                $registeredFile = $this->storageService->upload(
                    $file,
                    'lease-amendments/' . $amendment->id,
                    (string) $user->id,
                    'LEASE_AMENDMENT_SUPPORTING_DOCUMENT',
                    'private'
                );

                $uploadedFiles[] = $registeredFile;

                $this->storageService->attachToModel(
                    $registeredFile,
                    $amendment
                );
            }
        } catch (Throwable $exception) {
            foreach ($uploadedFiles as $uploadedFile) {
                try {
                    $this->storageService->delete($uploadedFile);
                } catch (Throwable $cleanupException) {
                    report($cleanupException);
                }
            }

            throw $exception;
        }
    }
}