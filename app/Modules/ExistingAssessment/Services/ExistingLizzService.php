<?php

namespace App\Modules\ExistingAssessment\Services;

use App\Models\Assessment;
use App\Models\AssessmentService;
use App\Modules\Assessment\Services\AssessmentServiceValueService;
use App\Services\DocumentSequenceService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class ExistingLizzService
{
    /*
    |--------------------------------------------------------------------------
    | Constructor
    |--------------------------------------------------------------------------
    */

    public function __construct(
        protected DocumentSequenceService $documentSequenceService,
        protected AssessmentServiceValueService $assessmentServiceValueService
    ) {
    }


    /*
    |--------------------------------------------------------------------------
    | CREATE
    |--------------------------------------------------------------------------
    |
    | Register an existing historical LIZZ agreement.
    |
    | Existing LIZZ is submitted for approval immediately.
    |
    */

    public function create(array $data): Assessment
    {
        Log::info('Existing LIZZ creation started.', [
            'revenue_service_id' => $data['revenue_service_id'] ?? null,
            'has_service_fields' => ! empty($data['service_fields']),
            'service_field_count' => is_array($data['service_fields'] ?? null)
                ? count($data['service_fields'])
                : 0,
        ]);

        try {
            return DB::transaction(function () use ($data) {

                /*
                |--------------------------------------------------------------------------
                | Authenticated Officer
                |--------------------------------------------------------------------------
                */

                $user = auth()->user();

                if (! $user) {
                    Log::warning(
                        'Existing LIZZ creation failed: unauthenticated request.'
                    );

                    throw ValidationException::withMessages([
                        'authentication' => [
                            'An authenticated user is required to register an assessment.',
                        ],
                    ]);
                }

                $administrativeUnitId =
                    $user->administrative_unit_id;

                if (! $administrativeUnitId) {
                    Log::warning(
                        'Existing LIZZ creation failed: officer has no administrative unit.',
                        [
                            'user_id' => $user->id,
                        ]
                    );

                    throw ValidationException::withMessages([
                        'administrative_unit_id' => [
                            'The authenticated officer is not assigned to an administrative unit.',
                        ],
                    ]);
                }


                /*
                |--------------------------------------------------------------------------
                | Financial Values
                |--------------------------------------------------------------------------
                */

                $originalObligation =
                    (float) $data['original_obligation'];

                $amountAlreadyPaid =
                    (float) $data['amount_already_paid'];


                /*
                |--------------------------------------------------------------------------
                | Validate Financial Values
                |--------------------------------------------------------------------------
                */

                $this->validateFinancialAmounts(
                    $originalObligation,
                    $amountAlreadyPaid
                );


                /*
                |--------------------------------------------------------------------------
                | Generate Assessment Number
                |--------------------------------------------------------------------------
                */

                $assessmentNumber =
                    $this->documentSequenceService->generate(
                        'assessment',
                        'ASM'
                    );


                /*
                |--------------------------------------------------------------------------
                | Create Assessment
                |--------------------------------------------------------------------------
                */

                $assessment = Assessment::create([

                    'assessment_number' =>
                        $assessmentNumber,

                    'citizen_id' =>
                        $data['taxpayer_id'],

                    'administrative_unit_id' =>
                        $administrativeUnitId,

                    'source_type' =>
                        'EXISTING_LIZZ',

                    'status' =>
                        'PENDING_APPROVAL',

                    'submitted_at' =>
                        now(),

                    'notes' =>
                        $data['notes'] ?? null,

                    'created_by' =>
                        $user->id,

                    'updated_by' =>
                        $user->id,
                ]);


                Log::info(
                    'Existing LIZZ assessment created.',
                    [
                        'assessment_id' =>
                            $assessment->id,

                        'assessment_number' =>
                            $assessment->assessment_number,

                        'source_type' =>
                            $assessment->source_type,

                        'status' =>
                            $assessment->status,

                        'created_by' =>
                            $user->id,

                        'administrative_unit_id' =>
                            $administrativeUnitId,
                    ]
                );


                /*
                |--------------------------------------------------------------------------
                | Create Primary Assessment Service
                |--------------------------------------------------------------------------
                |
                | Existing LIZZ contains exactly ONE revenue service.
                |
                */

                $assessmentService = AssessmentService::create([

                    'assessment_id' =>
                        $assessment->id,

                    'service_id' =>
                        $data['revenue_service_id'],

                    /*
                     * Existing historical obligation.
                     */
                    'computed_amount' =>
                        $originalObligation,

                    /*
                     * Historical amount already paid.
                     */
                    'paid_amount' =>
                        $amountAlreadyPaid,

                    /*
                     * Historical outstanding balance.
                     */
                    'remaining_amount' =>
                        max(
                            0,
                            $originalObligation -
                            $amountAlreadyPaid
                        ),
                ]);


                Log::info(
                    'Existing LIZZ assessment service created.',
                    [
                        'assessment_id' =>
                            $assessment->id,

                        'assessment_service_id' =>
                            $assessmentService->id,

                        'revenue_service_id' =>
                            $assessmentService->service_id,

                        'computed_amount' =>
                            $assessmentService->computed_amount,

                        'paid_amount' =>
                            $assessmentService->paid_amount,

                        'remaining_amount' =>
                            $assessmentService->remaining_amount,
                    ]
                );


                /*
                |--------------------------------------------------------------------------
                | Store Dynamic Service Fields
                |--------------------------------------------------------------------------
                |
                | Existing LIZZ sends fields using RevenueServiceField UUIDs.
                |
                | The AssessmentServiceValueService is responsible for:
                |
                | - resolving field configuration
                | - validating field types
                | - normalizing values
                | - validating options
                | - handling files
                | - creating AssessmentServiceValue records
                |
                */

                $this->storeServiceFields(
                    $assessmentService,
                    $data['service_fields'] ?? []
                );


                /*
                |--------------------------------------------------------------------------
                | Return Complete Assessment
                |--------------------------------------------------------------------------
                */

                $assessment = $assessment->fresh([
                    'citizen',
                    'administrativeUnit',
                    'services.service',
                    'services.values',
                    'services.paymentSchedules',
                ]);


                Log::info(
                    'Existing LIZZ creation completed successfully.',
                    [
                        'assessment_id' =>
                            $assessment->id,

                        'assessment_number' =>
                            $assessment->assessment_number,

                        'service_count' =>
                            $assessment->services->count(),

                        'service_value_count' =>
                            $assessment->services
                                ->sum(
                                    fn ($service) =>
                                        $service->values->count()
                                ),
                    ]
                );


                return $assessment;
            });
        } catch (ValidationException $exception) {

            Log::warning(
                'Existing LIZZ creation validation failed.',
                [
                    'errors' =>
                        $exception->errors(),

                    'user_id' =>
                        auth()->id(),
                ]
            );

            throw $exception;

        } catch (Throwable $exception) {

            Log::error(
                'Existing LIZZ creation failed.',
                [
                    'user_id' =>
                        auth()->id(),

                    'revenue_service_id' =>
                        $data['revenue_service_id'] ?? null,

                    'exception' =>
                        $exception::class,

                    'message' =>
                        $exception->getMessage(),

                    'file' =>
                        $exception->getFile(),

                    'line' =>
                        $exception->getLine(),
                ]
            );

            throw $exception;
        }
    }


    /*
    |--------------------------------------------------------------------------
    | UPDATE
    |--------------------------------------------------------------------------
    |
    | Update an Existing LIZZ assessment.
    |
    | The assessment number is NEVER regenerated.
    |
    */

    public function update(
        Assessment $assessment,
        array $data
    ): Assessment {

        /*
         * Make sure this is actually an Existing LIZZ assessment.
         */
        $this->ensureExistingLizz(
            $assessment
        );


        /*
         * Ensure authenticated officer exists.
         */
        $user = auth()->user();

        if (! $user) {

            Log::warning(
                'Existing LIZZ update failed: unauthenticated request.',
                [
                    'assessment_id' =>
                        $assessment->id,
                ]
            );

            throw ValidationException::withMessages([
                'authentication' => [
                    'An authenticated user is required to update an assessment.',
                ],
            ]);
        }


        /*
         * Ensure officer has administrative unit.
         */
        if (! $user->administrative_unit_id) {

            Log::warning(
                'Existing LIZZ update failed: officer has no administrative unit.',
                [
                    'assessment_id' =>
                        $assessment->id,

                    'user_id' =>
                        $user->id,
                ]
            );

            throw ValidationException::withMessages([
                'administrative_unit_id' => [
                    'The authenticated officer is not assigned to an administrative unit.',
                ],
            ]);
        }


        Log::info(
            'Existing LIZZ update started.',
            [
                'assessment_id' =>
                    $assessment->id,

                'assessment_number' =>
                    $assessment->assessment_number,

                'user_id' =>
                    $user->id,

                'has_service_fields' =>
                    array_key_exists(
                        'service_fields',
                        $data
                    ),

                'service_field_count' =>
                    is_array($data['service_fields'] ?? null)
                        ? count($data['service_fields'])
                        : 0,
            ]
        );


        try {

            return DB::transaction(function () use (
                $assessment,
                $data,
                $user
            ) {

                /*
                |--------------------------------------------------------------------------
                | Assessment Fields
                |--------------------------------------------------------------------------
                */

                $assessmentData = [];


                /*
                 * Taxpayer.
                 */
                if (
                    array_key_exists(
                        'taxpayer_id',
                        $data
                    )
                ) {
                    $assessmentData['citizen_id'] =
                        $data['taxpayer_id'];
                }


                /*
                 * Notes.
                 */
                if (
                    array_key_exists(
                        'notes',
                        $data
                    )
                ) {
                    $assessmentData['notes'] =
                        $data['notes'];
                }


                /*
                 * Always synchronize the administrative
                 * unit with the authenticated officer.
                 */
                $assessmentData['administrative_unit_id'] =
                    $user->administrative_unit_id;


                /*
                 * Audit field.
                 */
                $assessmentData['updated_by'] =
                    $user->id;


                /*
                 |--------------------------------------------------------------------------
                 | Persist assessment changes
                 |--------------------------------------------------------------------------
                 */

                $assessment->update(
                    $assessmentData
                );


                /*
                |--------------------------------------------------------------------------
                | Get Primary Assessment Service
                |--------------------------------------------------------------------------
                */

                $assessmentService =
                    $this->getPrimaryAssessmentService(
                        $assessment
                    );


                /*
                |--------------------------------------------------------------------------
                | Revenue Service
                |--------------------------------------------------------------------------
                */

                if (
                    array_key_exists(
                        'revenue_service_id',
                        $data
                    )
                ) {

                    $oldRevenueServiceId =
                        $assessmentService->service_id;

                    $newRevenueServiceId =
                        $data['revenue_service_id'];

                    if (
                        $oldRevenueServiceId !==
                        $newRevenueServiceId
                    ) {

                        Log::info(
                            'Existing LIZZ revenue service changed.',
                            [
                                'assessment_id' =>
                                    $assessment->id,

                                'assessment_service_id' =>
                                    $assessmentService->id,

                                'old_revenue_service_id' =>
                                    $oldRevenueServiceId,

                                'new_revenue_service_id' =>
                                    $newRevenueServiceId,
                            ]
                        );

                        $assessmentService->update([
                            'service_id' =>
                                $newRevenueServiceId,
                        ]);
                    }
                }


                /*
                |--------------------------------------------------------------------------
                | Financial Position
                |--------------------------------------------------------------------------
                */

                if (
                    array_key_exists(
                        'original_obligation',
                        $data
                    ) ||
                    array_key_exists(
                        'amount_already_paid',
                        $data
                    )
                ) {

                    /*
                     * Use submitted original obligation if supplied.
                     *
                     * Otherwise keep the existing value.
                     */
                    $originalObligation =
                        array_key_exists(
                            'original_obligation',
                            $data
                        )
                            ? (float) $data['original_obligation']
                            : (float) $assessmentService->computed_amount;


                    /*
                     * Use submitted paid amount if supplied.
                     *
                     * Otherwise keep the existing value.
                     */
                    $amountAlreadyPaid =
                        array_key_exists(
                            'amount_already_paid',
                            $data
                        )
                            ? (float) $data['amount_already_paid']
                            : (float) $assessmentService->paid_amount;


                    /*
                     * Validate financial relationship.
                     */
                    $this->validateFinancialAmounts(
                        $originalObligation,
                        $amountAlreadyPaid
                    );


                    /*
                     * Persist historical financial position.
                     */
                    $assessmentService->update([

                        'computed_amount' =>
                            $originalObligation,

                        'paid_amount' =>
                            $amountAlreadyPaid,

                        'remaining_amount' =>
                            max(
                                0,
                                $originalObligation -
                                $amountAlreadyPaid
                            ),
                    ]);


                    Log::info(
                        'Existing LIZZ financial position updated.',
                        [
                            'assessment_id' =>
                                $assessment->id,

                            'assessment_service_id' =>
                                $assessmentService->id,

                            'computed_amount' =>
                                $originalObligation,

                            'paid_amount' =>
                                $amountAlreadyPaid,

                            'remaining_amount' =>
                                max(
                                    0,
                                    $originalObligation -
                                    $amountAlreadyPaid
                                ),
                        ]
                    );
                }


                /*
                |--------------------------------------------------------------------------
                | Dynamic Service Fields
                |--------------------------------------------------------------------------
                */

                if (
                    array_key_exists(
                        'service_fields',
                        $data
                    )
                ) {

                    $this->storeServiceFields(
                        $assessmentService,
                        $data['service_fields'] ?? []
                    );
                }


                /*
                |--------------------------------------------------------------------------
                | Update Audit Field
                |--------------------------------------------------------------------------
                */

                $assessment->update([
                    'updated_by' =>
                        $user->id,
                ]);


                /*
                |--------------------------------------------------------------------------
                | Return Fresh Assessment
                |--------------------------------------------------------------------------
                */

                $assessment = $assessment->fresh([
                    'citizen',
                    'administrativeUnit',
                    'services.service',
                    'services.values',
                    'services.paymentSchedules',
                ]);


                Log::info(
                    'Existing LIZZ update completed successfully.',
                    [
                        'assessment_id' =>
                            $assessment->id,

                        'assessment_number' =>
                            $assessment->assessment_number,

                        'service_count' =>
                            $assessment->services->count(),

                        'service_value_count' =>
                            $assessment->services
                                ->sum(
                                    fn ($service) =>
                                        $service->values->count()
                                ),
                    ]
                );


                return $assessment;
            });

        } catch (ValidationException $exception) {

            Log::warning(
                'Existing LIZZ update validation failed.',
                [
                    'assessment_id' =>
                        $assessment->id,

                    'assessment_number' =>
                        $assessment->assessment_number,

                    'user_id' =>
                        $user->id,

                    'errors' =>
                        $exception->errors(),
                ]
            );

            throw $exception;

        } catch (Throwable $exception) {

            Log::error(
                'Existing LIZZ update failed.',
                [
                    'assessment_id' =>
                        $assessment->id,

                    'assessment_number' =>
                        $assessment->assessment_number,

                    'user_id' =>
                        $user->id,

                    'exception' =>
                        $exception::class,

                    'message' =>
                        $exception->getMessage(),

                    'file' =>
                        $exception->getFile(),

                    'line' =>
                        $exception->getLine(),
                ]
            );

            throw $exception;
        }
    }


    /*
    |--------------------------------------------------------------------------
    | FIND
    |--------------------------------------------------------------------------
    */

    public function find(
        Assessment $assessment
    ): Assessment {

        $this->ensureExistingLizz(
            $assessment
        );

        Log::debug(
            'Existing LIZZ assessment loaded.',
            [
                'assessment_id' =>
                    $assessment->id,

                'assessment_number' =>
                    $assessment->assessment_number,
            ]
        );

        return $assessment->load([
            'citizen',
            'administrativeUnit',
            'services.service',
            'services.values',
            'services.paymentSchedules',
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | LIST
    |--------------------------------------------------------------------------
    */

    public function list(
        array $filters = []
    ): LengthAwarePaginator {

        $query = Assessment::query()
            ->where(
                'source_type',
                'EXISTING_LIZZ'
            )
            ->with([
                'citizen',
                'administrativeUnit',
                'services.service',
                'services.values',
                'services.paymentSchedules',
            ]);


        /*
        |--------------------------------------------------------------------------
        | Taxpayer Filter
        |--------------------------------------------------------------------------
        */

        if (
            ! empty($filters['taxpayer_id'])
        ) {
            $query->where(
                'citizen_id',
                $filters['taxpayer_id']
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Administrative Unit Filter
        |--------------------------------------------------------------------------
        */

        if (
            ! empty($filters['administrative_unit_id'])
        ) {
            $query->where(
                'administrative_unit_id',
                $filters['administrative_unit_id']
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Status Filter
        |--------------------------------------------------------------------------
        */

        if (
            ! empty($filters['status'])
        ) {
            $query->where(
                'status',
                $filters['status']
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Search
        |--------------------------------------------------------------------------
        */

        if (
            ! empty($filters['search'])
        ) {

            $search = trim(
                $filters['search']
            );

            $query->where(
                function ($q) use ($search) {

                    /*
                     * Assessment number.
                     */
                    $q->where(
                        'assessment_number',
                        'like',
                        "%{$search}%"
                    );


                    /*
                     * Taxpayer information.
                     */
                    $q->orWhereHas(
                        'citizen',
                        function (
                            $citizenQuery
                        ) use ($search) {

                            $citizenQuery
                                ->where(
                                    'name',
                                    'like',
                                    "%{$search}%"
                                )
                                ->orWhere(
                                    'tin',
                                    'like',
                                    "%{$search}%"
                                );
                        }
                    );
                }
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Pagination
        |--------------------------------------------------------------------------
        */

        $perPage = (int) (
            $filters['per_page'] ?? 15
        );

        $perPage = min(
            max($perPage, 1),
            100
        );


        Log::debug(
            'Existing LIZZ list requested.',
            [
                'filters' => [
                    'taxpayer_id' =>
                        $filters['taxpayer_id'] ?? null,

                    'administrative_unit_id' =>
                        $filters['administrative_unit_id'] ?? null,

                    'status' =>
                        $filters['status'] ?? null,

                    'has_search' =>
                        ! empty($filters['search']),

                    'per_page' =>
                        $perPage,
                ],
            ]
        );


        return $query
            ->latest('assessment_date')
            ->paginate($perPage);
    }


    /*
    |--------------------------------------------------------------------------
    | PRIMARY ASSESSMENT SERVICE
    |--------------------------------------------------------------------------
    */

    protected function getPrimaryAssessmentService(
        Assessment $assessment
    ): AssessmentService {

        $assessmentService =
            $assessment->services()
                ->first();


        if (! $assessmentService) {

            Log::error(
                'Existing LIZZ assessment has no assessment service.',
                [
                    'assessment_id' =>
                        $assessment->id,

                    'assessment_number' =>
                        $assessment->assessment_number,
                ]
            );

            throw ValidationException::withMessages([
                'revenue_service_id' => [
                    'The existing LIZZ assessment does not have an assessment service.',
                ],
            ]);
        }


        return $assessmentService;
    }


    /*
    |--------------------------------------------------------------------------
    | SERVICE FIELDS
    |--------------------------------------------------------------------------
    |
    | Existing LIZZ receives service fields using
    | RevenueServiceField UUIDs.
    |
    | The actual persistence/normalization is delegated
    | to AssessmentServiceValueService.
    |
    */

    protected function storeServiceFields(
        AssessmentService $assessmentService,
        array $serviceFields
    ): void {

        if (empty($serviceFields)) {

            Log::debug(
                'Existing LIZZ contains no service fields to persist.',
                [
                    'assessment_service_id' =>
                        $assessmentService->id,
                ]
            );

            return;
        }


        Log::info(
            'Existing LIZZ service field persistence started.',
            [
                'assessment_service_id' =>
                    $assessmentService->id,

                'service_id' =>
                    $assessmentService->service_id,

                'field_count' =>
                    count($serviceFields),
            ]
        );


        $this->assessmentServiceValueService
            ->storeServiceValuesByFieldId(
                $assessmentService,
                $serviceFields
            );


        Log::info(
            'Existing LIZZ service field persistence completed.',
            [
                'assessment_service_id' =>
                    $assessmentService->id,

                'field_count' =>
                    count($serviceFields),
            ]
        );
    }


    /*
    |--------------------------------------------------------------------------
    | EXISTING LIZZ CHECK
    |--------------------------------------------------------------------------
    */

    protected function ensureExistingLizz(
        Assessment $assessment
    ): void {

        if (
            $assessment->source_type !==
            'EXISTING_LIZZ'
        ) {

            Log::warning(
                'Existing LIZZ operation attempted on a non-existing-LIZZ assessment.',
                [
                    'assessment_id' =>
                        $assessment->id,

                    'assessment_number' =>
                        $assessment->assessment_number,

                    'source_type' =>
                        $assessment->source_type,
                ]
            );

            throw ValidationException::withMessages([
                'assessment' => [
                    'The selected assessment is not an existing LIZZ assessment.',
                ],
            ]);
        }
    }


    /*
    |--------------------------------------------------------------------------
    | FINANCIAL VALIDATION
    |--------------------------------------------------------------------------
    */

    protected function validateFinancialAmounts(
        float $originalObligation,
        float $amountAlreadyPaid
    ): void {

        /*
        |--------------------------------------------------------------------------
        | Original Obligation
        |--------------------------------------------------------------------------
        */

        if (
            $originalObligation < 0
        ) {

            Log::warning(
                'Existing LIZZ financial validation failed: negative original obligation.',
                [
                    'original_obligation' =>
                        $originalObligation,
                ]
            );

            throw ValidationException::withMessages([
                'original_obligation' => [
                    'Original obligation cannot be negative.',
                ],
            ]);
        }


        /*
        |--------------------------------------------------------------------------
        | Amount Already Paid
        |--------------------------------------------------------------------------
        */

        if (
            $amountAlreadyPaid < 0
        ) {

            Log::warning(
                'Existing LIZZ financial validation failed: negative paid amount.',
                [
                    'amount_already_paid' =>
                        $amountAlreadyPaid,
                ]
            );

            throw ValidationException::withMessages([
                'amount_already_paid' => [
                    'Amount already paid cannot be negative.',
                ],
            ]);
        }


        /*
        |--------------------------------------------------------------------------
        | Paid Cannot Exceed Original Obligation
        |--------------------------------------------------------------------------
        */

        if (
            $amountAlreadyPaid >
            $originalObligation
        ) {

            Log::warning(
                'Existing LIZZ financial validation failed: paid amount exceeds obligation.',
                [
                    'original_obligation' =>
                        $originalObligation,

                    'amount_already_paid' =>
                        $amountAlreadyPaid,
                ]
            );

            throw ValidationException::withMessages([
                'amount_already_paid' => [
                    'Amount already paid cannot be greater than the original obligation.',
                ],
            ]);
        }
    }
}
