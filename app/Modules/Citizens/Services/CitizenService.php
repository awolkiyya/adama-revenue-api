<?php

namespace App\Modules\Citizens\Services;

use App\Models\Citizen;
use App\Models\AdministrativeUnit;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CitizenService
{
    /**
     * ============================================================
     * GET PAGINATED CITIZENS
     * ============================================================
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        return Citizen::query()

            ->with([
                'administrativeUnit',
                'createdBy',
                'updatedBy',
            ])

            ->when(
                isset($filters['search']),
                function ($query) use ($filters) {

                    $search = $filters['search'];

                    $query->where(function ($q) use ($search) {

                        $q->where(
                            'full_name',
                            'ILIKE',
                            "%{$search}%"
                        )

                        ->orWhere(
                            'national_id',
                            'ILIKE',
                            "%{$search}%"
                        )

                        ->orWhere(
                            'phone',
                            'ILIKE',
                            "%{$search}%"
                        )

                        ->orWhere(
                            'email',
                            'ILIKE',
                            "%{$search}%"
                        );

                    });
                }
            )

            ->when(
                isset($filters['source']),
                fn ($query) =>
                    $query->where(
                        'source',
                        $filters['source']
                    )
            )

            ->when(
                isset($filters['is_active']),
                fn ($query) =>
                    $query->where(
                        'is_active',
                        $filters['is_active']
                    )
            )

            ->latest()

            ->paginate(
                $filters['per_page'] ?? 15
            );
    }

    /**
     * ============================================================
     * CREATE CITIZEN
     * ============================================================
     */
    public function store(
        array $data
    ): Citizen {

        return DB::transaction(function () use ($data) {

            $administrativeUnit =
                AdministrativeUnit::findOrFail(
                    $data['administrative_unit_id']
                );

            /*
            |--------------------------------------------------------------------------
            | Generate UUID
            |--------------------------------------------------------------------------
            */

            $data['id'] =
                (string) Str::uuid();

            /*
            |--------------------------------------------------------------------------
            | Generate citizen reference number
            |--------------------------------------------------------------------------
            */

            $data['citizen_uid'] =
                $this->generateCitizenUid();

            /*
            |--------------------------------------------------------------------------
            | Generate readable address
            |--------------------------------------------------------------------------
            */

            $data['address'] =
                $this->buildAddress(
                    $administrativeUnit
                );

            /*
            |--------------------------------------------------------------------------
            | Audit
            |--------------------------------------------------------------------------
            */

            $data['created_by'] =
                Auth::id();

            $data['registered_at'] =
                now();

            /*
            |--------------------------------------------------------------------------
            | Source
            |--------------------------------------------------------------------------
            */

            $data['source'] =
                'MANUAL';

            /*
            |--------------------------------------------------------------------------
            | Default Status
            |--------------------------------------------------------------------------
            |
            | A newly registered citizen is active by default.
            |
            */

            $data['is_active'] =
                $data['is_active'] ?? true;

            return Citizen::create($data);
        });
    }

    /**
     * ============================================================
     * UPDATE CITIZEN
     * ============================================================
     */
    public function update(
        Citizen $citizen,
        array $data
    ): Citizen {

        return DB::transaction(function () use (
            $citizen,
            $data
        ) {

            /*
            |--------------------------------------------------------------------------
            | Update address if administrative unit changed
            |--------------------------------------------------------------------------
            */

            if (
                isset(
                    $data['administrative_unit_id']
                )
            ) {

                $unit =
                    AdministrativeUnit::findOrFail(
                        $data['administrative_unit_id']
                    );

                $data['address'] =
                    $this->buildAddress(
                        $unit
                    );
            }

            /*
            |--------------------------------------------------------------------------
            | Audit
            |--------------------------------------------------------------------------
            */

            $data['updated_by'] =
                Auth::id();

            $citizen->update(
                $data
            );

            return $citizen->fresh();
        });
    }

    /**
     * ============================================================
     * TOGGLE CITIZEN STATUS
     * ============================================================
     *
     * Changes:
     *
     *      active   -> inactive
     *      inactive -> active
     *
     * Also records the authenticated user as updated_by.
     */
    public function toggleStatus(
        Citizen $citizen
    ): Citizen {

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
     * ============================================================
     * DELETE CITIZEN
     * ============================================================
     */
    public function delete(
        Citizen $citizen
    ): bool {

        return $citizen->delete();
    }

    /**
     * ============================================================
     * GENERATE CITIZEN REFERENCE NUMBER
     * ============================================================
     *
     * Example:
     *
     *      CIT-2026-000001
     */
    private function generateCitizenUid(): string
    {
        $year = now()->year;

        $count =
            Citizen::whereYear(
                'created_at',
                $year
            )->count();

        return sprintf(
            'CIT-%s-%06d',
            $year,
            $count + 1
        );
    }

    /**
     * ============================================================
     * BUILD ADDRESS
     * ============================================================
     *
     * Example:
     *
     *      Adama,
     *      Kutaa Magaalaa Abbaa Gadaa,
     *      Aanaa Badhaatuu
     */
    private function buildAddress(
        AdministrativeUnit $unit
    ): string {

        $path = [];

        while ($unit) {

            $path[] =
                $unit->name;

            $unit =
                $unit->parent;
        }

        return implode(
            ', ',
            array_reverse($path)
        );
    }
}
