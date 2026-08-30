<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Permission\Traits\HasRoles;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasUuids, HasApiTokens, HasRoles, HasFactory, Notifiable;

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    */

    protected $guard_name = 'api';

    protected $keyType = 'string';

    public $incrementing = false;


    /*
    |--------------------------------------------------------------------------
    | Mass Assignment
    |--------------------------------------------------------------------------
    */

    protected $fillable = [

        /*
        |--------------------------------------------------------------------------
        | Basic Information
        |--------------------------------------------------------------------------
        */

        'name',
        'label',

        /*
        |--------------------------------------------------------------------------
        | Authentication
        |--------------------------------------------------------------------------
        */

        'username',
        'email',
        'phone',
        'password',

        /*
        |--------------------------------------------------------------------------
        | User Type
        |--------------------------------------------------------------------------
        */

        'user_type',

        /*
        |--------------------------------------------------------------------------
        | Verification
        |--------------------------------------------------------------------------
        */

        'is_phone_verified',

        /*
        |--------------------------------------------------------------------------
        | Account Status
        |--------------------------------------------------------------------------
        */

        'is_active',

        /*
        |--------------------------------------------------------------------------
        | Administrative Scope
        |--------------------------------------------------------------------------
        */

        'administrative_unit_id',

        /*
        |--------------------------------------------------------------------------
        | Sector Assignment
        |--------------------------------------------------------------------------
        */

        'sector_id',

        /*
        |--------------------------------------------------------------------------
        | Login Tracking
        |--------------------------------------------------------------------------
        */

        'last_login_at',
        'last_failed_login_at',

        /*
        |--------------------------------------------------------------------------
        | Account Lockout Security
        |--------------------------------------------------------------------------
        */

        'failed_login_attempts',
        'locked_until',
        'lockout_count',

        /*
        |--------------------------------------------------------------------------
        | Password Security
        |--------------------------------------------------------------------------
        */

        'password_changed_at',
        'must_change_password',
    ];


    /*
    |--------------------------------------------------------------------------
    | Hidden Attributes
    |--------------------------------------------------------------------------
    */

    protected $hidden = [
        'password',
        'remember_token',

        /*
        | Security fields should never be exposed through
        | default model serialization.
        */
        'failed_login_attempts',
        'last_failed_login_at',
        'locked_until',
        'lockout_count',
        'password_changed_at',
    ];


    /*
    |--------------------------------------------------------------------------
    | Attribute Casting
    |--------------------------------------------------------------------------
    */

    protected function casts(): array
    {
        return [

            /*
            |--------------------------------------------------------------------------
            | Authentication / Login
            |--------------------------------------------------------------------------
            */

            'last_login_at' => 'datetime',

            'last_failed_login_at' => 'datetime',

            /*
            |--------------------------------------------------------------------------
            | Verification
            |--------------------------------------------------------------------------
            */

            'email_verified_at' => 'datetime',

            /*
            |--------------------------------------------------------------------------
            | Password
            |--------------------------------------------------------------------------
            */

            'password' => 'hashed',

            'password_changed_at' => 'datetime',

            /*
            |--------------------------------------------------------------------------
            | Boolean Fields
            |--------------------------------------------------------------------------
            */

            'is_phone_verified' => 'boolean',

            'is_active' => 'boolean',

            'must_change_password' => 'boolean',

            /*
            |--------------------------------------------------------------------------
            | Account Lockout
            |--------------------------------------------------------------------------
            */

            'locked_until' => 'datetime',

            'failed_login_attempts' => 'integer',

            'lockout_count' => 'integer',
        ];
    }


    /*
    |--------------------------------------------------------------------------
    | Administrative Unit
    |--------------------------------------------------------------------------
    |
    | City / Subcity / Wereda
    |--------------------------------------------------------------------------
    */

    public function administrativeUnit(): BelongsTo
    {
        return $this->belongsTo(
            AdministrativeUnit::class,
            'administrative_unit_id'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Sector Assignment
    |--------------------------------------------------------------------------
    |
    | Used by:
    | - SECTOR_OFFICER
    | - REVENUE_DECISION_OFFICER
    | - REVENUE_COLLECTOR
    |--------------------------------------------------------------------------
    */

    public function sector(): BelongsTo
    {
        return $this->belongsTo(
            Sector::class,
            'sector_id'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Citizen Login Connection
    |--------------------------------------------------------------------------
    */

    public function citizenAccount()
    {
        return $this->hasOne(
            CitizenAccount::class,
            'user_id'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | User Type Helpers
    |--------------------------------------------------------------------------
    */

    public function isCitizen(): bool
    {
        return $this->user_type === 'citizen';
    }


    public function isEmployee(): bool
    {
        return $this->user_type === 'employee';
    }


    /*
    |--------------------------------------------------------------------------
    | Account Lockout
    |--------------------------------------------------------------------------
    */

    /**
     * Determine whether the account is currently locked.
     */
    public function isLocked(): bool
    {
        if (!$this->locked_until) {
            return false;
        }

        return $this->locked_until->isFuture();
    }


    /**
     * Determine whether the account lock has expired.
     */
    public function lockHasExpired(): bool
    {
        if (!$this->locked_until) {
            return false;
        }

        return $this->locked_until->isPast();
    }


    /**
     * Clear the current account lock.
     */
    public function clearLockout(): void
    {
        $this->forceFill([
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'last_failed_login_at' => null,
        ])->save();
    }


    /*
    |--------------------------------------------------------------------------
    | Password Security
    |--------------------------------------------------------------------------
    */

    /**
     * Determine whether the user must change their password.
     */
    public function mustChangePassword(): bool
    {
        return (bool) $this->must_change_password;
    }


    /*
    |--------------------------------------------------------------------------
    | Audit Logs
    |--------------------------------------------------------------------------
    */

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }


    /*
    |--------------------------------------------------------------------------
    | Avatar
    |--------------------------------------------------------------------------
    */

    public function avatar()
    {
        return $this->morphOne(File::class, 'fileable')
            ->where('collection', 'AVATAR');
    }
}
