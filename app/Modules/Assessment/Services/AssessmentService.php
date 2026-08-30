<?php

namespace App\Modules\Assessment\Services;

use App\Models\Assessment;
use App\Models\AssessmentService as AssessmentServiceModel;
use App\Models\AssessmentServiceValue;
use App\Models\File;
use App\Modules\Assessment\Requests\StoreAssessmentRequest;
use App\Modules\Assessment\Requests\UpdateAssessmentRequest;
use App\Services\AssessmentCalculationService;
use App\Services\Storage\StorageService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class AssessmentService
{
    public function __construct(
        protected StorageService $storageService,
        protected AssessmentCalculationService $calculationService,
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | RELATIONSHIPS
    |--------------------------------------------------------------------------
    */

    protected function assessmentRelations(): array
    {
        return [
            'taxpayer',

            /*
            |--------------------------------------------------------------------------
            | Audit Users
            |--------------------------------------------------------------------------
            */

            'creator',
            'updater',
            'decisionOfficer',

            /*
            |--------------------------------------------------------------------------
            | Services
            |--------------------------------------------------------------------------
            */

            'services',
            'services.service',
            'services.values',
            'services.values.files',
        ];
    }


    /*
|--------------------------------------------------------------------------
| APPLY LIST FILTERS
|--------------------------------------------------------------------------
|
| Shared by:
|
| - paginate()
| - summary()
|
| This guarantees both endpoints always use
| exactly the same filtering rules.
|
*/

protected function applyAssessmentFilters(
    Builder $query,
    Request $request
): Builder {

    /*
    |--------------------------------------------------------------------------
    | Search
    |--------------------------------------------------------------------------
    */

    if ($request->filled('search')) {

        $search = trim(
            (string) $request->input('search')
        );

        if ($search !== '') {

            $query->where(function (Builder $q) use ($search) {

                $q->where(
                    'assessment_number',
                    'ILIKE',
                    "%{$search}%"
                );

                $q->orWhereHas(
                    'taxpayer',
                    function (Builder $taxpayer) use ($search) {

                        $taxpayer
                            ->where(
                                'full_name',
                                'ILIKE',
                                "%{$search}%"
                            )
                            ->orWhere(
                                'national_id',
                                'ILIKE',
                                "%{$search}%"
                            );
                    }
                );
            });
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Taxpayer
    |--------------------------------------------------------------------------
    */

    if ($request->filled('taxpayerId')) {

        $query->where(
            'citizen_id',
            $request->input('taxpayerId')
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Status / Pending Queue
    |--------------------------------------------------------------------------
    |
    | pending=true is a semantic shortcut for:
    |
    | status = PENDING_APPROVAL
    |
    | Pending takes precedence over an explicitly
    | supplied status to avoid contradictory filters.
    |
    */

    $pending = filter_var(
        $request->input('pending', false),
        FILTER_VALIDATE_BOOLEAN
    );

    if ($pending) {

        $query->where(
            'status',
            'PENDING_APPROVAL'
        );

    } elseif ($request->filled('status')) {

        $status = strtoupper(
            trim(
                (string) $request->input('status')
            )
        );

        if ($status !== 'ALL') {

            $this->assertValidAssessmentStatus(
                $status
            );

            $query->where(
                'status',
                $status
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Service
    |--------------------------------------------------------------------------
    */

    if ($request->filled('serviceId')) {

        $query->whereHas(
            'services',
            function (Builder $serviceQuery) use ($request) {

                $serviceQuery->where(
                    'service_id',
                    $request->input('serviceId')
                );
            }
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Service Code
    |--------------------------------------------------------------------------
    */

    if ($request->filled('serviceCode')) {

        $query->whereHas(
            'services',
            function (Builder $serviceQuery) use ($request) {

                $serviceQuery->where(
                    'service_code',
                    $request->input('serviceCode')
                );
            }
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Date From
    |--------------------------------------------------------------------------
    */

    if ($request->filled('date_from')) {

        $query->whereDate(
            'assessment_date',
            '>=',
            $request->input('date_from')
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Date To
    |--------------------------------------------------------------------------
    */

    if ($request->filled('date_to')) {

        $query->whereDate(
            'assessment_date',
            '<=',
            $request->input('date_to')
        );
    }

    return $query;
}


    /*
|--------------------------------------------------------------------------
| LIST
|--------------------------------------------------------------------------
*/

public function paginate(
    Request $request
): LengthAwarePaginator {

    $query = Assessment::query()
        ->with($this->assessmentRelations());

    /*
    |--------------------------------------------------------------------------
    | Filters
    |--------------------------------------------------------------------------
    */

    $this->applyAssessmentFilters(
        $query,
        $request
    );

    /*
    |--------------------------------------------------------------------------
    | Sorting
    |--------------------------------------------------------------------------
    */

    $allowedSorts = [
        'created_at',
        'assessment_date',
        'assessment_number',
        'status',
    ];

    $sortBy = (string) $request->input(
        'sort_by',
        'created_at'
    );

    if (!in_array(
        $sortBy,
        $allowedSorts,
        true
    )) {

        $sortBy = 'created_at';
    }

    $sortDirection = strtolower(
        (string) $request->input(
            'sort_direction',
            'desc'
        )
    );

    $sortDirection =
        $sortDirection === 'asc'
            ? 'asc'
            : 'desc';

    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    */

    $perPage = min(
        max(
            (int) $request->input(
                'per_page',
                10
            ),
            1
        ),
        100
    );

    return $query
        ->orderBy(
            $sortBy,
            $sortDirection
        )
        ->paginate(
            $perPage
        );
}
/*
|--------------------------------------------------------------------------
| SUMMARY
|--------------------------------------------------------------------------
*/

public function summary(
    Request $request
): array {

    /*
    |--------------------------------------------------------------------------
    | BASE QUERY
    |--------------------------------------------------------------------------
    */

    $query = Assessment::query();


    /*
    |--------------------------------------------------------------------------
    | APPLY FILTERS
    |--------------------------------------------------------------------------
    */

    $this->applyAssessmentFilters(
        $query,
        $request
    );


    /*
    |--------------------------------------------------------------------------
    | AGGREGATE
    |--------------------------------------------------------------------------
    |
    | Assessment workflow:
    |
    | DRAFT
    | PENDING_APPROVAL
    | RETURNED
    | APPROVED
    | CANCELLED
    |
    | RETURNED means the assessment was sent back to
    | the creator for correction.
    |
    */

    $result = $query
        ->selectRaw("
            COUNT(*) AS total,

            COUNT(*) FILTER (
                WHERE status = 'DRAFT'
            ) AS draft,

            COUNT(*) FILTER (
                WHERE status = 'PENDING_APPROVAL'
            ) AS pending_approval,

            COUNT(*) FILTER (
                WHERE status = 'RETURNED'
            ) AS returned,

            COUNT(*) FILTER (
                WHERE status = 'APPROVED'
            ) AS approved,

            COUNT(*) FILTER (
                WHERE status = 'CANCELLED'
            ) AS cancelled
        ")
        ->first();


    /*
    |--------------------------------------------------------------------------
    | RESPONSE
    |--------------------------------------------------------------------------
    */

    return [

        'total' =>
            (int) $result->total,

        'draft' =>
            (int) $result->draft,

        'pending_approval' =>
            (int) $result->pending_approval,

        'returned' =>
            (int) $result->returned,

        'approved' =>
            (int) $result->approved,

        'cancelled' =>
            (int) $result->cancelled,

    ];
}

    /*
    |--------------------------------------------------------------------------
    | CREATE
    |--------------------------------------------------------------------------
    */

    public function create(
        StoreAssessmentRequest $request
    ): Assessment {

        /*
        |--------------------------------------------------------------------------
        | Newly uploaded files
        |--------------------------------------------------------------------------
        */

        $createdFiles = [];

        try {

            /*
            |--------------------------------------------------------------------------
            | Create assessment and services
            |--------------------------------------------------------------------------
            |
            | IMPORTANT:
            |
            | Financial calculation is NOT performed inside this transaction.
            |
            | First we make sure the assessment structure and files are
            | successfully committed.
            |
            */

            $assessment = DB::transaction(
                function () use (
                    $request,
                    &$createdFiles
                ) {

                    $data = $request->validated();

                    /*
                    |--------------------------------------------------------------------------
                    | Status
                    |--------------------------------------------------------------------------
                    */

                    $status = strtoupper(
                        trim(
                            (string) (
                                $data['status']
                                ?? 'DRAFT'
                            )
                        )
                    );

                    $this->assertValidAssessmentStatus(
                        $status
                    );

                    if (!in_array(
                        $status,
                        [
                            'DRAFT',
                            'PENDING_APPROVAL',
                        ],
                        true
                    )) {

                        throw new RuntimeException(
                            'A new assessment can only be created as DRAFT or PENDING_APPROVAL.'
                        );
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Taxpayer
                    |--------------------------------------------------------------------------
                    */

                    if (
                        empty($data['taxpayerId'])
                    ) {

                        throw new RuntimeException(
                            'Taxpayer is required.'
                        );
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Assessment Number
                    |--------------------------------------------------------------------------
                    */

                    $assessmentNumber =
                        $this->generateAssessmentNumber();

                    /*
                    |--------------------------------------------------------------------------
                    | Create Assessment
                    |--------------------------------------------------------------------------
                    */

                    $assessment = Assessment::create([

                        'id' =>
                            (string) Str::uuid(),

                        'assessment_number' =>
                            $assessmentNumber,

                        'citizen_id' =>
                            $data['taxpayerId'],

                        'assessment_date' =>
                            now()->toDateString(),

                        'status' =>
                            $status,

                        'notes' =>
                            $data['notes'] ?? null,

                        'submitted_at' =>
                            $status === 'PENDING_APPROVAL'
                                ? now()
                                : null,

                        'created_by' =>
                            auth()->id(),
                    ]);

                    /*
                    |--------------------------------------------------------------------------
                    | Services
                    |--------------------------------------------------------------------------
                    */

                    $this->storeServices(
                        $assessment,
                        $data['services'] ?? [],
                        $request,
                        $createdFiles
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | Return
                    |--------------------------------------------------------------------------
                    */

                    return $assessment;
                }
            );

        } catch (Throwable $e) {

            /*
            |--------------------------------------------------------------------------
            | Rollback filesystem changes
            |--------------------------------------------------------------------------
            */

            $this->cleanupCreatedFiles(
                $createdFiles
            );

            throw $e;
        }

        /*
        |--------------------------------------------------------------------------
        | CALCULATE AFTER COMMIT
        |--------------------------------------------------------------------------
        |
        | Financial calculation happens only after the assessment structure
        | has been successfully committed.
        |
        */

        if (
            strtoupper(
                (string) $assessment->status
            ) === 'PENDING_APPROVAL'
        ) {

            $assessment =
                $this->calculationService
                    ->calculate(
                        $assessment
                    );
        }

        /*
        |--------------------------------------------------------------------------
        | Return
        |--------------------------------------------------------------------------
        */

        return $assessment->fresh(
            $this->assessmentRelations()
        );
    }

    /*
    |--------------------------------------------------------------------------
    | UPDATE
    |--------------------------------------------------------------------------
    */

    public function update(
        string $id,
        UpdateAssessmentRequest $request
    ): Assessment {

        /*
        |--------------------------------------------------------------------------
        | Files created during THIS update
        |--------------------------------------------------------------------------
        */

        $createdFiles = [];

        /*
        |--------------------------------------------------------------------------
        | Files that became obsolete
        |--------------------------------------------------------------------------
        */

        $obsoleteFiles = [];

        try {

            /*
            |--------------------------------------------------------------------------
            | Update assessment structure
            |--------------------------------------------------------------------------
            |
            | IMPORTANT:
            |
            | Calculation is intentionally NOT performed inside this
            | transaction.
            |
            */

            $assessment = DB::transaction(
                function () use (
                    $id,
                    $request,
                    &$createdFiles,
                    &$obsoleteFiles
                ) {

                    /*
                    |--------------------------------------------------------------------------
                    | Find + lock assessment
                    |--------------------------------------------------------------------------
                    */

                    $assessment =
                        Assessment::query()
                            ->lockForUpdate()
                            ->findOrFail($id);

                    /*
                    |--------------------------------------------------------------------------
                    | Only DRAFT can be edited
                    |--------------------------------------------------------------------------
                    */

                    if (
                        strtoupper(
                            (string) $assessment->status
                        ) !== 'DRAFT'
                    ) {

                        throw new RuntimeException(
                            'Only draft assessments can be updated.'
                        );
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Validate request
                    |--------------------------------------------------------------------------
                    */

                    $data =
                        $request->validated();

                    /*
                    |--------------------------------------------------------------------------
                    | Status
                    |--------------------------------------------------------------------------
                    */

                    $newStatus = strtoupper(
                        trim(
                            (string) (
                                $data['status']
                                ?? $assessment->status
                            )
                        )
                    );

                    $this->assertValidAssessmentStatus(
                        $newStatus
                    );

                    if (!in_array(
                        $newStatus,
                        [
                            'DRAFT',
                            'PENDING_APPROVAL',
                        ],
                        true
                    )) {

                        throw new RuntimeException(
                            'A draft assessment can only remain DRAFT or be submitted for PENDING_APPROVAL.'
                        );
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Update Assessment Header
                    |--------------------------------------------------------------------------
                    */

                    $assessment->update([

                        'citizen_id' =>
                            $data['taxpayerId']
                            ?? $assessment->citizen_id,

                        'notes' =>
                            array_key_exists(
                                'notes',
                                $data
                            )
                                ? $data['notes']
                                : $assessment->notes,

                        'status' =>
                            $newStatus,

                        'submitted_at' =>
                            $newStatus === 'PENDING_APPROVAL'
                                ? (
                                    $assessment->submitted_at
                                    ?? now()
                                )
                                : null,

                        'updated_by' =>
                            auth()->id(),
                    ]);

                    /*
                    |--------------------------------------------------------------------------
                    | Replace Services
                    |--------------------------------------------------------------------------
                    */

                    if (
                        array_key_exists(
                            'services',
                            $data
                        )
                    ) {

                        /*
                        |--------------------------------------------------------------------------
                        | Collect old files
                        |--------------------------------------------------------------------------
                        */

                        $oldFiles =
                            $this->collectAssessmentFiles(
                                $assessment
                            );

                        /*
                        |--------------------------------------------------------------------------
                        | Remove old services
                        |--------------------------------------------------------------------------
                        |
                        | Physical files are NOT deleted here.
                        |
                        */

                        $this->removeAssessmentServices(
                            $assessment
                        );

                        /*
                        |--------------------------------------------------------------------------
                        | Create new services
                        |--------------------------------------------------------------------------
                        */

                        $this->storeServices(
                            $assessment,
                            $data['services'],
                            $request,
                            $createdFiles
                        );

                        /*
                        |--------------------------------------------------------------------------
                        | Collect currently used files
                        |--------------------------------------------------------------------------
                        */

                        $currentFiles =
                            $this->collectAssessmentFiles(
                                $assessment->fresh([
                                    'services.values.files',
                                ])
                            );

                        /*
                        |--------------------------------------------------------------------------
                        | Determine obsolete files
                        |--------------------------------------------------------------------------
                        */

                        $obsoleteFiles =
                            $this->findObsoleteFiles(
                                $oldFiles,
                                $currentFiles
                            );
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Schedule obsolete file deletion
                    |--------------------------------------------------------------------------
                    */

                    if (!empty($obsoleteFiles)) {

                        DB::afterCommit(
                            function () use (
                                $obsoleteFiles
                            ) {

                                $this->cleanupObsoleteFiles(
                                    $obsoleteFiles
                                );
                            }
                        );
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Return
                    |--------------------------------------------------------------------------
                    */

                    return $assessment;
                }
            );

        } catch (Throwable $e) {

            /*
            |--------------------------------------------------------------------------
            | IMPORTANT
            |--------------------------------------------------------------------------
            |
            | Only files created during THIS update are deleted.
            |
            | Existing files remain untouched because the database
            | transaction failed.
            |
            */

            $this->cleanupCreatedFiles(
                $createdFiles
            );

            throw $e;
        }

        /*
        |--------------------------------------------------------------------------
        | CALCULATE AFTER COMMIT
        |--------------------------------------------------------------------------
        |
        | A draft that has now been submitted for approval must be
        | financially calculated before the decision maker sees it.
        |
        */

        if (
            strtoupper(
                (string) $assessment->status
            ) === 'PENDING_APPROVAL'
        ) {

            $assessment =
                $this->calculationService
                    ->calculate(
                        $assessment
                    );
        }

        /*
        |--------------------------------------------------------------------------
        | Return
        |--------------------------------------------------------------------------
        */

        return $assessment->fresh(
            $this->assessmentRelations()
        );
    }

    /*
    |--------------------------------------------------------------------------
    | FIND
    |--------------------------------------------------------------------------
    */

    public function findOrFail(
        string $id
    ): Assessment {

        return Assessment::query()
            ->with($this->assessmentRelations())
            ->findOrFail($id);
    }

    /*
    |--------------------------------------------------------------------------
    | STORE SERVICES
    |--------------------------------------------------------------------------
    */

    protected function storeServices(
        Assessment $assessment,
        array $services,
        Request $request,
        array &$createdFiles
    ): void {

        if (empty($services)) {

            throw new RuntimeException(
                'At least one assessment service is required.'
            );
        }

        foreach (
            array_values($services) as $index => $service
        ) {

            /*
            |--------------------------------------------------------------------------
            | Service ID
            |--------------------------------------------------------------------------
            */

            if (
                empty($service['serviceId'])
            ) {

                throw new RuntimeException(
                    'Assessment service is missing serviceId.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Create Assessment Service
            |--------------------------------------------------------------------------
            */

            $assessmentService =
                $assessment
                    ->services()
                    ->create([

                        'id' =>
                            (string) Str::uuid(),

                        'service_id' =>
                            $service['serviceId'],

                        'service_code' =>
                            $service['serviceCode']
                            ?? null,

                        'service_order' =>
                            $index + 1,

                        /*
                        |--------------------------------------------------------------------------
                        | Initial calculation state
                        |--------------------------------------------------------------------------
                        |
                        | DRAFT:
                        |     CAPTURED
                        |
                        | PENDING_APPROVAL:
                        |     CAPTURED
                        |
                        | AssessmentCalculationService will later
                        | change it to PROCESSING -> COMPLETED.
                        |
                        */

                        'status' =>
                            'CAPTURED',

                        'computed_amount' =>
                            null,

                        'currency_code' =>
                            null,

                        'calculation_metadata' =>
                            null,

                        'calculation_error' =>
                            null,

                        'calculated_at' =>
                            null,
                    ]);

            /*
            |--------------------------------------------------------------------------
            | Values
            |--------------------------------------------------------------------------
            */

            $this->storeServiceValues(
                $assessmentService,
                $service['fields'] ?? [],
                $request,
                $createdFiles
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | STORE SERVICE VALUES
    |--------------------------------------------------------------------------
    */

    protected function storeServiceValues(
        AssessmentServiceModel $assessmentService,
        array $fields,
        Request $request,
        array &$createdFiles
    ): void {

        /*
        |--------------------------------------------------------------------------
        | Revenue Service
        |--------------------------------------------------------------------------
        */

        $revenueService =
            $assessmentService
                ->service()
                ->with([
                    'fields.baseField',
                ])
                ->first();

        if (!$revenueService) {

            throw new RuntimeException(
                "Revenue service [{$assessmentService->service_id}] was not found."
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Configured Fields
        |--------------------------------------------------------------------------
        */

        $configuredFields =
            $revenueService
                ->fields
                ->filter(
                    function ($serviceField) {

                        return $serviceField->baseField !== null
                            && $serviceField->baseField->is_active;
                    }
                )
                ->keyBy(
                    function ($serviceField) {

                        return strtoupper(
                            trim(
                                (string)
                                $serviceField
                                    ->baseField
                                    ->code
                            )
                        );
                    }
                );

        /*
        |--------------------------------------------------------------------------
        | Values
        |--------------------------------------------------------------------------
        */

        $sortOrder = 0;

        foreach (
            $fields as $fieldCode => $value
        ) {

            /*
            |--------------------------------------------------------------------------
            | Normalize Code
            |--------------------------------------------------------------------------
            */

            $fieldCode =
                strtoupper(
                    trim(
                        (string) $fieldCode
                    )
                );

            if ($fieldCode === '') {

                throw new RuntimeException(
                    'Assessment field code cannot be empty.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Configuration
            |--------------------------------------------------------------------------
            */

            $configuredField =
                $configuredFields->get(
                    $fieldCode
                );

            if (!$configuredField) {

                throw new RuntimeException(
                    "Field [{$fieldCode}] is not configured for revenue service [{$assessmentService->service_id}]."
                );
            }

            $baseField =
                $configuredField->baseField;

            if (!$baseField) {

                throw new RuntimeException(
                    "Base field configuration is missing for field [{$fieldCode}]."
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Types
            |--------------------------------------------------------------------------
            */

            $rawDataType =
                strtoupper(
                    trim(
                        (string) (
                            $baseField->data_type
                            ?? 'TEXT'
                        )
                    )
                );

            $rawInputType = null;

            if (
                isset(
                    $configuredField->input_type
                )
            ) {

                $rawInputType =
                    strtoupper(
                        trim(
                            (string)
                            $configuredField->input_type
                        )
                    );
            }

            [
                $dataType,
                $inputType,
            ] = $this->resolveFieldTypes(
                $rawDataType,
                $rawInputType
            );

            /*
            |--------------------------------------------------------------------------
            | Label
            |--------------------------------------------------------------------------
            */

            $fieldLabel =
                $configuredField->label
                ?? $baseField->name
                ?? $fieldCode;

            /*
            |--------------------------------------------------------------------------
            | Measurement Unit
            |--------------------------------------------------------------------------
            */

            $measurementUnitId =
                $baseField->measurement_unit_id
                ?? null;

            /*
            |--------------------------------------------------------------------------
            | Store Files
            |--------------------------------------------------------------------------
            */

            $storedValue =
                $this->storeUploadedFiles(
                    $value,
                    $request,
                    $assessmentService,
                    $createdFiles
                );

            /*
            |--------------------------------------------------------------------------
            | Normalize
            |--------------------------------------------------------------------------
            */

            $normalizedValue =
                $this->normalizeFieldValue(
                    $storedValue
                );

            /*
            |--------------------------------------------------------------------------
            | Display
            |--------------------------------------------------------------------------
            */

            $displayValue =
                $this->makeDisplayValue(
                    $storedValue
                );

            /*
            |--------------------------------------------------------------------------
            | Create Value
            |--------------------------------------------------------------------------
            */

            $assessmentServiceValue =
                $assessmentService
                    ->values()
                    ->create([

                        'id' =>
                            (string) Str::uuid(),

                        'revenue_service_field_id' =>
                            $configuredField->id,

                        'field_code' =>
                            $baseField->code,

                        'field_label' =>
                            $fieldLabel,

                        'data_type' =>
                            $dataType,

                        'input_type' =>
                            $inputType,

                        'value' =>
                            $normalizedValue,

                        'display_value' =>
                            $displayValue,

                        'measurement_unit_id' =>
                            $measurementUnitId,

                        'sort_order' =>
                            $sortOrder++,
                    ]);

            /*
            |--------------------------------------------------------------------------
            | Attach Files
            |--------------------------------------------------------------------------
            */

            $this->attachValueFiles(
                $assessmentServiceValue,
                $storedValue
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | STORE UPLOADED FILES
    |--------------------------------------------------------------------------
    */

    protected function storeUploadedFiles(
        mixed $value,
        Request $request,
        AssessmentServiceModel $assessmentService,
        array &$createdFiles
    ): mixed {

        if (!is_array($value)) {
            return $value;
        }

        /*
        |--------------------------------------------------------------------------
        | Single File
        |--------------------------------------------------------------------------
        */

        if (
            array_key_exists(
                '__file',
                $value
            )
        ) {

            $reference =
                $value['__file'];

            if (
                $reference === null ||
                $reference === ''
            ) {
                return null;
            }

            if (!is_scalar($reference)) {

                throw new RuntimeException(
                    'The __file value must be a valid file reference.'
                );
            }

            $reference =
                trim(
                    (string) $reference
                );

            /*
            |--------------------------------------------------------------------------
            | New Upload
            |--------------------------------------------------------------------------
            */

            $uploadedFile =
                $request->file(
                    $reference
                );

            if (
                $uploadedFile instanceof UploadedFile
            ) {

                $file =
                    $this->uploadAssessmentFile(
                        $uploadedFile,
                        $assessmentService
                    );

                $createdFiles[] =
                    $file;

                return [
                    '__file' =>
                        $file->uuid,
                ];
            }

            /*
            |--------------------------------------------------------------------------
            | Existing File
            |--------------------------------------------------------------------------
            */

            $uuid =
                $this->resolveFileUuid(
                    $reference
                );

            $file =
                $this->storageService
                    ->findOrFail($uuid);

            $this->assertFileCanBeAttached(
                $file,
                $reference
            );

            return [
                '__file' =>
                    $file->uuid,
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Multiple Files
        |--------------------------------------------------------------------------
        */

        if (
            array_key_exists(
                '__files',
                $value
            )
        ) {

            if (
                !is_array(
                    $value['__files']
                )
            ) {

                throw new RuntimeException(
                    'The __files value must be an array.'
                );
            }

            $storedFiles = [];

            foreach (
                $value['__files'] as $reference
            ) {

                if (
                    $reference === null ||
                    $reference === ''
                ) {
                    continue;
                }

                if (!is_scalar($reference)) {

                    throw new RuntimeException(
                        'Each __files item must be a valid file reference.'
                    );
                }

                $reference =
                    trim(
                        (string) $reference
                    );

                /*
                |--------------------------------------------------------------------------
                | New Upload
                |--------------------------------------------------------------------------
                */

                $uploadedFile =
                    $request->file(
                        $reference
                    );

                if (
                    $uploadedFile instanceof UploadedFile
                ) {

                    $file =
                        $this->uploadAssessmentFile(
                            $uploadedFile,
                            $assessmentService
                        );

                    $createdFiles[] =
                        $file;

                    $storedFiles[] =
                        $file->uuid;

                    continue;
                }

                /*
                |--------------------------------------------------------------------------
                | Existing File
                |--------------------------------------------------------------------------
                */

                $uuid =
                    $this->resolveFileUuid(
                        $reference
                    );

                $file =
                    $this->storageService
                        ->findOrFail($uuid);

                $this->assertFileCanBeAttached(
                    $file,
                    $reference
                );

                $storedFiles[] =
                    $file->uuid;
            }

            return [
                '__files' =>
                    array_values(
                        array_unique(
                            $storedFiles
                        )
                    ),
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Normal Array
        |--------------------------------------------------------------------------
        */

        return $value;
    }

    /*
    |--------------------------------------------------------------------------
    | UPLOAD ASSESSMENT FILE
    |--------------------------------------------------------------------------
    */

    protected function uploadAssessmentFile(
        UploadedFile $uploadedFile,
        AssessmentServiceModel $assessmentService
    ): File {

        if (
            !$uploadedFile->isValid()
        ) {

            throw new RuntimeException(
                "Uploaded file [{$uploadedFile->getClientOriginalName()}] is invalid."
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Assessment-specific directory
        |--------------------------------------------------------------------------
        */

        $folder =
            'assessments/' .
            $assessmentService->assessment_id .
            '/services/' .
            $assessmentService->id;

        return $this->storageService->upload(
            uploadedFile: $uploadedFile,
            folder: $folder,
            uploadedBy: auth()->id(),
            category: 'ASSESSMENT',
            visibility: 'private'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | ATTACH VALUE FILES
    |--------------------------------------------------------------------------
    */

    protected function attachValueFiles(
        AssessmentServiceValue $valueModel,
        mixed $value
    ): void {

        if (!is_array($value)) {
            return;
        }

        $references = [];

        /*
        |--------------------------------------------------------------------------
        | Single
        |--------------------------------------------------------------------------
        */

        if (
            array_key_exists(
                '__file',
                $value
            )
        ) {

            $reference =
                $value['__file'];

            if (
                $reference !== null &&
                $reference !== ''
            ) {

                if (!is_scalar($reference)) {

                    throw new RuntimeException(
                        'The __file value must be a valid file reference.'
                    );
                }

                $references[] =
                    trim(
                        (string) $reference
                    );
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Multiple
        |--------------------------------------------------------------------------
        */

        if (
            array_key_exists(
                '__files',
                $value
            )
        ) {

            if (
                !is_array(
                    $value['__files']
                )
            ) {

                throw new RuntimeException(
                    'The __files value must be an array.'
                );
            }

            foreach (
                $value['__files'] as $reference
            ) {

                if (
                    $reference === null ||
                    $reference === ''
                ) {
                    continue;
                }

                if (!is_scalar($reference)) {

                    throw new RuntimeException(
                        'Each __files item must be a valid file reference.'
                    );
                }

                $references[] =
                    trim(
                        (string) $reference
                    );
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Clean
        |--------------------------------------------------------------------------
        */

        $references =
            array_values(
                array_unique(
                    array_filter(
                        $references,
                        static fn ($reference) =>
                            is_string($reference)
                            && $reference !== ''
                    )
                )
            );

        if (empty($references)) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Attach
        |--------------------------------------------------------------------------
        */

        foreach (
            $references as $reference
        ) {

            $uuid =
                $this->resolveFileUuid(
                    $reference
                );

            $file =
                $this->storageService
                    ->findOrFail($uuid);

            $this->assertFileCanBeAttached(
                $file,
                $reference
            );

            $this->storageService
                ->attachToModel(
                    $file,
                    $valueModel
                );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | COLLECT ASSESSMENT FILES
    |--------------------------------------------------------------------------
    */

    protected function collectAssessmentFiles(
        Assessment $assessment
    ): array {

        $assessment->loadMissing([
            'services.values.files',
        ]);

        $files = [];

        foreach (
            $assessment->services as $service
        ) {

            foreach (
                $service->values as $value
            ) {

                foreach (
                    $value->files as $file
                ) {

                    if (
                        !$file instanceof File
                    ) {
                        continue;
                    }

                    $files[$file->uuid] =
                        $file;
                }
            }
        }

        return array_values($files);
    }

    /*
    |--------------------------------------------------------------------------
    | FIND OBSOLETE FILES
    |--------------------------------------------------------------------------
    */

    protected function findObsoleteFiles(
        array $oldFiles,
        array $currentFiles
    ): array {

        $currentFileUuids = [];

        foreach (
            $currentFiles as $file
        ) {

            if (
                $file instanceof File
            ) {

                $currentFileUuids[
                    $file->uuid
                ] = true;
            }
        }

        $obsolete = [];

        foreach (
            $oldFiles as $file
        ) {

            if (
                !$file instanceof File
            ) {
                continue;
            }

            if (
                !isset(
                    $currentFileUuids[
                        $file->uuid
                    ]
                )
            ) {

                $obsolete[
                    $file->uuid
                ] = $file;
            }
        }

        return array_values($obsolete);
    }

    /*
    |--------------------------------------------------------------------------
    | REMOVE ASSESSMENT SERVICES
    |--------------------------------------------------------------------------
    */

    protected function removeAssessmentServices(
        Assessment $assessment
    ): void {

        $assessment->loadMissing([
            'services.values.files',
        ]);

        foreach (
            $assessment->services as $service
        ) {

            foreach (
                $service->values as $value
            ) {

                /*
                |--------------------------------------------------------------------------
                | Detach files
                |--------------------------------------------------------------------------
                */

                $this->storageService
                    ->detachFromModel(
                        $value
                    );

                /*
                |--------------------------------------------------------------------------
                | Delete value
                |--------------------------------------------------------------------------
                */

                $value->delete();
            }

            /*
            |--------------------------------------------------------------------------
            | Delete service
            |--------------------------------------------------------------------------
            */

            $service->delete();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | CLEANUP NEWLY CREATED FILES
    |--------------------------------------------------------------------------
    */

    protected function cleanupCreatedFiles(
        array $files
    ): void {

        foreach (
            $files as $file
        ) {

            if (
                !$file instanceof File
            ) {
                continue;
            }

            try {

                $this->storageService
                    ->delete($file);

            } catch (Throwable $cleanupException) {

                report(
                    $cleanupException
                );
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | CLEANUP OBSOLETE FILES
    |--------------------------------------------------------------------------
    */

    protected function cleanupObsoleteFiles(
        array $files
    ): void {

        foreach (
            $files as $file
        ) {

            if (
                !$file instanceof File
            ) {
                continue;
            }

            try {

                $this->storageService
                    ->delete($file);

            } catch (Throwable $cleanupException) {

                report(
                    $cleanupException
                );
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | RESOLVE FILE UUID
    |--------------------------------------------------------------------------
    */

    protected function resolveFileUuid(
        string $reference
    ): string {

        $reference =
            trim($reference);

        if ($reference === '') {

            throw new RuntimeException(
                'Uploaded file reference cannot be empty.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Direct UUID
        |--------------------------------------------------------------------------
        */

        if (
            Str::isUuid(
                $reference
            )
        ) {

            return $reference;
        }

        /*
        |--------------------------------------------------------------------------
        | file__UUID__FIELD
        |--------------------------------------------------------------------------
        */

        if (
            preg_match(
                '/^file__([0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})(?:__.*)?$/',
                $reference,
                $matches
            )
        ) {

            $uuid =
                $matches[1];

            if (
                !Str::isUuid(
                    $uuid
                )
            ) {

                throw new RuntimeException(
                    "Invalid file UUID in reference [{$reference}]."
                );
            }

            return $uuid;
        }

        throw new RuntimeException(
            "Invalid uploaded file reference [{$reference}]. " .
            "Expected UUID or file__{uuid}__{field}."
        );
    }

    /*
    |--------------------------------------------------------------------------
    | ASSERT FILE
    |--------------------------------------------------------------------------
    */

    protected function assertFileCanBeAttached(
        File $file,
        string $reference
    ): void {

        if (
            strtoupper(
                (string) $file->status
            ) !== 'READY'
        ) {

            throw new RuntimeException(
                "File [{$reference}] is not ready for use."
            );
        }

        if (
            empty($file->path)
        ) {

            throw new RuntimeException(
                "File [{$reference}] has no storage path."
            );
        }

        if (
            empty($file->disk)
        ) {

            throw new RuntimeException(
                "File [{$reference}] has no storage disk configured."
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | RESOLVE FIELD TYPES
    |--------------------------------------------------------------------------
    */

    protected function resolveFieldTypes(
        string $rawDataType,
        ?string $rawInputType = null
    ): array {

        $validDataTypes = [
            'NUMBER',
            'DECIMAL',
            'TEXT',
            'BOOLEAN',
            'DATE',
        ];

        $validInputTypes = [
            'TEXT',
            'NUMBER',
            'DECIMAL',
            'SELECT',
            'RADIO',
            'CHECKBOX',
            'DATE',
            'TEXTAREA',
            'FILE',
            'MULTI_FILE',
        ];

        /*
        |--------------------------------------------------------------------------
        | Input Type
        |--------------------------------------------------------------------------
        */

        if (
            $rawInputType === null ||
            !in_array(
                $rawInputType,
                $validInputTypes,
                true
            )
        ) {

            $rawInputType =
                match ($rawDataType) {

                    'NUMBER' =>
                        'NUMBER',

                    'DECIMAL' =>
                        'DECIMAL',

                    'BOOLEAN' =>
                        'CHECKBOX',

                    'DATE' =>
                        'DATE',

                    default =>
                        'TEXT',
                };
        }

        /*
        |--------------------------------------------------------------------------
        | Data Type
        |--------------------------------------------------------------------------
        */

        if (
            in_array(
                $rawDataType,
                $validDataTypes,
                true
            )
        ) {

            $dataType =
                $rawDataType;

        } else {

            $dataType =
                match ($rawDataType) {

                    'SELECT',
                    'RADIO',
                    'TEXTAREA',
                    'FILE',
                    'MULTI_FILE' =>
                        'TEXT',

                    'CHECKBOX' =>
                        'BOOLEAN',

                    default =>
                        'TEXT',
                };
        }

        /*
        |--------------------------------------------------------------------------
        | Text Controls
        |--------------------------------------------------------------------------
        */

        if (
            in_array(
                $rawInputType,
                [
                    'SELECT',
                    'RADIO',
                    'TEXTAREA',
                ],
                true
            )
        ) {

            $dataType = 'TEXT';
        }

        /*
        |--------------------------------------------------------------------------
        | Checkbox
        |--------------------------------------------------------------------------
        */

        if (
            $rawInputType === 'CHECKBOX'
        ) {

            $dataType = 'BOOLEAN';
        }

        /*
        |--------------------------------------------------------------------------
        | File
        |--------------------------------------------------------------------------
        */

        if (
            in_array(
                $rawInputType,
                [
                    'FILE',
                    'MULTI_FILE',
                ],
                true
            )
        ) {

            $dataType = 'TEXT';
        }

        /*
        |--------------------------------------------------------------------------
        | Safety
        |--------------------------------------------------------------------------
        */

        if (
            !in_array(
                $dataType,
                $validDataTypes,
                true
            )
        ) {

            $dataType = 'TEXT';
        }

        if (
            !in_array(
                $rawInputType,
                $validInputTypes,
                true
            )
        ) {

            $rawInputType = 'TEXT';
        }

        return [
            $dataType,
            $rawInputType,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | NORMALIZE FIELD VALUE
    |--------------------------------------------------------------------------
    */

    protected function normalizeFieldValue(
        mixed $value
    ): mixed {

        if ($value === null) {
            return null;
        }

        /*
        |--------------------------------------------------------------------------
        | Single File
        |--------------------------------------------------------------------------
        */

        if (
            is_array($value) &&
            array_key_exists(
                '__file',
                $value
            )
        ) {

            if (
                $value['__file'] === null ||
                $value['__file'] === ''
            ) {

                return null;
            }

            if (
                !is_scalar(
                    $value['__file']
                )
            ) {

                throw new RuntimeException(
                    'The __file value must be a valid file reference.'
                );
            }

            return [
                '__file' =>
                    trim(
                        (string)
                        $value['__file']
                    ),
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Multiple Files
        |--------------------------------------------------------------------------
        */

        if (
            is_array($value) &&
            array_key_exists(
                '__files',
                $value
            )
        ) {

            if (
                !is_array(
                    $value['__files']
                )
            ) {

                throw new RuntimeException(
                    'The __files value must be an array.'
                );
            }

            $files = [];

            foreach (
                $value['__files'] as $item
            ) {

                if (!is_scalar($item)) {

                    throw new RuntimeException(
                        'Each __files item must be a valid file reference.'
                    );
                }

                $item =
                    trim(
                        (string) $item
                    );

                if ($item !== '') {

                    $files[] =
                        $item;
                }
            }

            return [
                '__files' =>
                    array_values(
                        array_unique(
                            $files
                        )
                    ),
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Normal Array
        |--------------------------------------------------------------------------
        */

        if (is_array($value)) {

            return array_map(
                static fn ($item) => $item,
                $value
            );
        }

        return $value;
    }

    /*
    |--------------------------------------------------------------------------
    | DISPLAY VALUE
    |--------------------------------------------------------------------------
    */

    protected function makeDisplayValue(
        mixed $value
    ): ?string {

        if ($value === null) {
            return null;
        }

        /*
        |--------------------------------------------------------------------------
        | Boolean
        |--------------------------------------------------------------------------
        */

        if (is_bool($value)) {

            return $value
                ? 'Yes'
                : 'No';
        }

        /*
        |--------------------------------------------------------------------------
        | Array
        |--------------------------------------------------------------------------
        */

        if (is_array($value)) {

            /*
            |--------------------------------------------------------------------------
            | Single File
            |--------------------------------------------------------------------------
            */

            if (
                array_key_exists(
                    '__file',
                    $value
                )
            ) {

                $uuid =
                    $value['__file']
                    ?? null;

                if (!$uuid) {
                    return null;
                }

                $file =
                    $this->storageService
                        ->find(
                            (string) $uuid
                        );

                return $file
                    ? $file->original_name
                    : (string) $uuid;
            }

            /*
            |--------------------------------------------------------------------------
            | Multiple Files
            |--------------------------------------------------------------------------
            */

            if (
                array_key_exists(
                    '__files',
                    $value
                )
            ) {

                if (
                    !is_array(
                        $value['__files']
                    )
                ) {

                    return null;
                }

                $names = [];

                foreach (
                    $value['__files'] as $uuid
                ) {

                    $file =
                        $this->storageService
                            ->find(
                                (string) $uuid
                            );

                    $names[] =
                        $file
                            ? $file->original_name
                            : (string) $uuid;
                }

                return implode(
                    ', ',
                    $names
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Normal Array
            |--------------------------------------------------------------------------
            */

            return implode(
                ', ',
                array_map(
                    static function ($item) {

                        if (
                            is_scalar($item) ||
                            $item === null
                        ) {

                            return (string) $item;
                        }

                        return json_encode(
                            $item,
                            JSON_UNESCAPED_UNICODE |
                            JSON_UNESCAPED_SLASHES
                        );
                    },
                    $value
                )
            );
        }

        return (string) $value;
    }

    /*
    |--------------------------------------------------------------------------
    | VALIDATE STATUS
    |--------------------------------------------------------------------------
    */

    protected function assertValidAssessmentStatus(
        string $status
    ): void {

        $allowedStatuses = [
            'DRAFT',
            'PENDING_APPROVAL',
            'APPROVED',
            'REJECTED',
            'CANCELLED',
        ];

        if (
            !in_array(
                $status,
                $allowedStatuses,
                true
            )
        ) {

            throw new RuntimeException(
                "Invalid assessment status [{$status}]."
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | GENERATE ASSESSMENT NUMBER
    |--------------------------------------------------------------------------
    */

    protected function generateAssessmentNumber(): string
    {
        $year =
            now()->format('Y');

        $lastAssessment =
            Assessment::query()
                ->where(
                    'assessment_number',
                    'like',
                    "ASM-{$year}-%"
                )
                ->orderByDesc(
                    'assessment_number'
                )
                ->lockForUpdate()
                ->first();

        $sequence = 1;

        if ($lastAssessment) {

            $parts =
                explode(
                    '-',
                    $lastAssessment->assessment_number
                );

            $sequence =
                isset($parts[2])
                    ? ((int) $parts[2]) + 1
                    : 1;
        }

        return sprintf(
            'ASM-%s-%06d',
            $year,
            $sequence
        );
    }


/*
|--------------------------------------------------------------------------
| RETURN ASSESSMENT
|--------------------------------------------------------------------------
|
| Business transition:
|
| PENDING_APPROVAL
|        ↓
|     RETURNED
|
| The assessment is returned to the creator for correction.
|
| The return reason is stored in:
|
|     assessments.decision_notes
|
| No AssessmentHistoryService is required.
|
*/

public function returnAssessment(
    string $assessmentId,
    string $reason
): Assessment {

    return DB::transaction(function () use (
        $assessmentId,
        $reason
    ) {

        /*
        |--------------------------------------------------------------------------
        | 1. FIND + LOCK ASSESSMENT
        |--------------------------------------------------------------------------
        */

        $assessment = Assessment::query()
            ->lockForUpdate()
            ->findOrFail($assessmentId);


        /*
        |--------------------------------------------------------------------------
        | 2. STATUS VALIDATION
        |--------------------------------------------------------------------------
        */

        if ($assessment->status !== 'PENDING_APPROVAL') {

            throw ValidationException::withMessages([
                'status' => [
                    'Only assessments pending approval can be returned.',
                ],
            ]);
        }


        /*
        |--------------------------------------------------------------------------
        | 3. REASON VALIDATION
        |--------------------------------------------------------------------------
        */

        $reason = trim($reason);

        if ($reason === '') {

            throw ValidationException::withMessages([
                'reason' => [
                    'A return reason is required.',
                ],
            ]);
        }


        /*
        |--------------------------------------------------------------------------
        | 4. RETURN ASSESSMENT
        |--------------------------------------------------------------------------
        |
        | RETURNED is a workflow state.
        |
        | Do NOT set:
        |
        | decision = REJECTED
        |
        | because returning is not rejection.
        |
        */

        $assessment->update([
            'status' => 'RETURNED',

            'decision_notes' => $reason,

            'decided_by' => Auth::id(),

            'decided_at' => now(),

            // A return is not an approval or rejection.
            'approved_at' => null,
            'rejected_at' => null,

            // Important: do not set decision to REJECTED.
            'decision' => null,
        ]);


        /*
        |--------------------------------------------------------------------------
        | 5. RETURN FRESH MODEL
        |--------------------------------------------------------------------------
        */

        return $assessment->fresh([
            'services',
            'citizen',
        ]);
    });
}



    /*
    |--------------------------------------------------------------------------
    | CANCEL ASSESSMENT
    |--------------------------------------------------------------------------
    |
    | Cancel an assessment that is no longer supposed to continue.
    |
    | Allowed:
    |
    | DRAFT
    | RETURNED
    | PENDING_APPROVAL
    |
    | Not allowed:
    |
    | APPROVED
    | CANCELLED
    |
    | An APPROVED assessment has already entered the
    | official financial workflow and must not be casually cancelled.
    |
    */

    public function cancel(
        string $assessmentId,
        string $reason
    ): Assessment {

        return DB::transaction(function () use (
            $assessmentId,
            $reason
        ) {

            /*
            |--------------------------------------------------------------------------
            | FIND ASSESSMENT
            |--------------------------------------------------------------------------
            */

            $assessment =
                Assessment::query()
                    ->lockForUpdate()
                    ->findOrFail(
                        $assessmentId
                    );


            /*
            |--------------------------------------------------------------------------
            | AUTHORIZATION
            |--------------------------------------------------------------------------
            |
            | Authorization should normally also be handled by
            | Policy / Gate.
            |
            | This service still protects the domain operation
            | from invalid state transitions.
            |
            */


            /*
            |--------------------------------------------------------------------------
            | VALIDATE CURRENT STATUS
            |--------------------------------------------------------------------------
            */

            $allowedStatuses = [
                'DRAFT',
                'RETURNED',
                'PENDING_APPROVAL',
            ];


            if (
                ! in_array(
                    $assessment->status,
                    $allowedStatuses,
                    true
                )
            ) {

                throw ValidationException::withMessages([
                    'status' => [
                        sprintf(
                            'Assessment cannot be cancelled while it is in %s status.',
                            $assessment->status
                        ),
                    ],
                ]);

            }


            /*
            |--------------------------------------------------------------------------
            | VALIDATE REASON
            |--------------------------------------------------------------------------
            */

            $reason =
                trim(
                    $reason
                );


            if ($reason === '') {

                throw ValidationException::withMessages([
                    'reason' => [
                        'A cancellation reason is required.',
                    ],
                ]);

            }


            /*
            |--------------------------------------------------------------------------
            | CANCEL
            |--------------------------------------------------------------------------
            */

            $assessment->status =
                'CANCELLED';

            $assessment->cancellation_reason =
                $reason;

            $assessment->cancelled_at =
                now();

            $assessment->cancelled_by =
                Auth::id();


            $assessment->save();


            /*
            |--------------------------------------------------------------------------
            | HISTORY / AUDIT
            |--------------------------------------------------------------------------
            |
            | If your AssessmentHistory / audit service exists,
            | record the transition here.
            |
            | Example:
            |
            | $this->historyService->record(
            |     assessment: $assessment,
            |     action: 'CANCELLED',
            |     reason: $reason,
            | );
            |
            */


            return $assessment->fresh();

        });
    }
}



