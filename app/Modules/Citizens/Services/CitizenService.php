<?php

declare(strict_types=1);

namespace App\Modules\Citizens\Services;

use App\Models\AdministrativeUnit;
use App\Models\Citizen;
use App\Services\DocumentSequenceService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CitizenService
{
    public function __construct(
        private readonly DocumentSequenceService $documentSequenceService,
    ) {}

   /**
 * Get paginated citizens with search and filters.
 *
 * Supported filters:
 * - search
 * - status: ACTIVE | INACTIVE
 * - is_active: true | false | 1 | 0
 * - gender: MALE | FEMALE
 * - source: MANUAL | other supported sources
 * - per_page
 */
public function paginate(array $filters = []): LengthAwarePaginator
{
    return Citizen::query()
        ->with([
            'administrativeUnit',
            'createdBy',
            'updatedBy',
        ])

        /*
         * Search
         */
        ->when(
            isset($filters['search']) && trim($filters['search']) !== '',
            function ($query) use ($filters) {
                $search = trim($filters['search']);

                $query->where(function ($q) use ($search) {
                    $q->where('full_name', 'ILIKE', "%{$search}%")
                        ->orWhere('national_id', 'ILIKE', "%{$search}%")
                        ->orWhere('phone', 'ILIKE', "%{$search}%")
                        ->orWhere('email', 'ILIKE', "%{$search}%")
                        ->orWhere('citizen_uid', 'ILIKE', "%{$search}%");
                });
            }
        )

        /*
         * Status
         *
         * Supports ACTIVE / INACTIVE and the legacy is_active filter.
         * The status filter takes precedence if both are supplied.
         */
        ->when(
            isset($filters['status']) && $filters['status'] !== '',
            function ($query) use ($filters) {
                $status = strtoupper(trim((string) $filters['status']));

                if ($status === 'ACTIVE') {
                    $query->where('is_active', true);
                } elseif ($status === 'INACTIVE') {
                    $query->where('is_active', false);
                }
            },
            function ($query) use ($filters) {
                if (array_key_exists('is_active', $filters)) {
                    $isActive = filter_var(
                        $filters['is_active'],
                        FILTER_VALIDATE_BOOLEAN,
                        FILTER_NULL_ON_FAILURE
                    );

                    if ($isActive !== null) {
                        $query->where('is_active', $isActive);
                    }
                }
            }
        )

        /*
         * Gender
         */
        ->when(
            isset($filters['gender']) && $filters['gender'] !== '',
            fn ($query) => $query->where(
                'gender',
                strtoupper(trim((string) $filters['gender']))
            )
        )

        /*
         * Registration source
         */
        ->when(
            isset($filters['source']) && $filters['source'] !== '',
            fn ($query) => $query->where(
                'source',
                strtoupper(trim((string) $filters['source']))
            )
        )

        /*
         * Latest records first
         */
        ->latest()

        /*
         * Pagination
         */
        ->paginate(
            max(1, min((int) ($filters['per_page'] ?? 15), 100))
        );
}

    /**
     * Create a citizen.
     */
    public function store(array $data): Citizen
    {
        return DB::transaction(function () use ($data) {
            $administrativeUnit = AdministrativeUnit::findOrFail(
                $data['administrative_unit_id']
            );

            /*
             * Generate the citizen UUID.
             */
            $data['id'] = (string) Str::uuid();

            /*
             * Generate the citizen reference number using
             * the centralized document sequence service.
             *
             * Example: CIT-2019-000001
             * The year uses the Ethiopian calendar by default.
             */
            $data['citizen_uid'] = $this->documentSequenceService->generate(
                'citizen'
            );

            /*
             * Generate the readable administrative address.
             */
            $data['address'] = $this->buildAddress(
                $administrativeUnit
            );

            /*
             * Audit information.
             */
            $data['created_by'] = Auth::id();
            $data['registered_at'] = now();

            /*
             * Registration source.
             */
            $data['source'] = 'MANUAL';

            /*
             * Newly registered citizens are active by default.
             */
            $data['is_active'] = $data['is_active'] ?? true;

            return Citizen::create($data);
        });
    }

    /**
     * Update a citizen.
     */
    public function update(
        Citizen $citizen,
        array $data
    ): Citizen {
        return DB::transaction(function () use ($citizen, $data) {
            /*
             * Rebuild the address when the administrative unit changes.
             */
            if (isset($data['administrative_unit_id'])) {
                $unit = AdministrativeUnit::findOrFail(
                    $data['administrative_unit_id']
                );

                $data['address'] = $this->buildAddress($unit);
            }

            /*
             * Audit information.
             */
            $data['updated_by'] = Auth::id();

            $citizen->update($data);

            return $citizen->fresh([
                'administrativeUnit',
                'createdBy',
                'updatedBy',
            ]);
        });
    }

    /**
     * Toggle citizen status.
     *
     * Active citizens become inactive, and inactive citizens
     * become active.
     */
    public function toggleStatus(Citizen $citizen): Citizen
    {
        return DB::transaction(function () use ($citizen) {
            $citizen->update([
                'is_active' => ! $citizen->is_active,
                'updated_by' => Auth::id(),
            ]);

            return $citizen->fresh([
                'administrativeUnit',
                'createdBy',
                'updatedBy',
            ]);
        });
    }

    /**
     * Delete a citizen.
     */
    public function delete(Citizen $citizen): bool
    {
        return $citizen->delete();
    }

    /**
     * Build a readable address from the administrative hierarchy.
     *
     * Example:
     * Adama, Kutaa Magaalaa Abbaa Gadaa, Aanaa Badhaatuu
     */
    private function buildAddress(
        AdministrativeUnit $unit
    ): string {
        $path = [];

        while ($unit !== null) {
            $path[] = $unit->name;
            $unit = $unit->parent;
        }

        return implode(', ', array_reverse($path));
    }
}