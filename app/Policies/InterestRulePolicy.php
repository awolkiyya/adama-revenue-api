<?php

namespace App\Policies;

use App\Models\InterestRule;
use App\Models\User;
use App\Traits\ChecksHierarchy;

class InterestRulePolicy
{
    use ChecksHierarchy;

    /*
    |--------------------------------------------------------------------------
    | View Any Interest Rules
    |--------------------------------------------------------------------------
    */

    public function viewAny(User $user): bool
    {
        return $this->hasPermission(
            $user,
            'interest_rules.view'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | View Interest Rule
    |--------------------------------------------------------------------------
    */

    public function view(User $user, InterestRule $interestRule): bool
    {
        return $this->hasPermission(
            $user,
            'interest_rules.view'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Create Interest Rule
    |--------------------------------------------------------------------------
    */

    public function create(User $user): bool
    {
        return $this->hasPermission(
            $user,
            'interest_rules.create'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Update Interest Rule
    |--------------------------------------------------------------------------
    */

    public function update(
        User $user,
        InterestRule $interestRule
    ): bool {
        return $this->hasPermission(
            $user,
            'interest_rules.update'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Activate Interest Rule
    |--------------------------------------------------------------------------
    */

    public function activate(
        User $user,
        InterestRule $interestRule
    ): bool {
        return $this->hasPermission(
            $user,
            'interest_rules.activate'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Deactivate Interest Rule
    |--------------------------------------------------------------------------
    */

    public function deactivate(
        User $user,
        InterestRule $interestRule
    ): bool {
        return $this->hasPermission(
            $user,
            'interest_rules.deactivate'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | View Interest Rule History
    |--------------------------------------------------------------------------
    */

    public function viewHistory(
        User $user,
        InterestRule $interestRule
    ): bool {
        return $this->hasPermission(
            $user,
            'interest_rules.view_history'
        );
    }
}