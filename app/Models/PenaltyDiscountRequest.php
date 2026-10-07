<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PenaltyDiscountRequest extends Model
{
    use HasFactory;
    use HasUuids;

    /*
    |--------------------------------------------------------------------------
    | Table
    |--------------------------------------------------------------------------
    */

    protected $table = 'penalty_discount_requests';

    /*
    |--------------------------------------------------------------------------
    | Mass Assignment
    |--------------------------------------------------------------------------
    */

    protected $fillable = [
        /*
        |--------------------------------------------------------------------------
        | Target
        |--------------------------------------------------------------------------
        */

        'invoice_id',
        'citizen_id',

        /*
        |--------------------------------------------------------------------------
        | Request Details
        |--------------------------------------------------------------------------
        */

        'requested_amount',
        'reason',

        /*
        |--------------------------------------------------------------------------
        | Request Lifecycle
        |--------------------------------------------------------------------------
        |
        | DRAFT
        | SUBMITTED
        | APPROVED
        | REJECTED
        | APPLIED
        | CANCELLED
        |
        */

        'status',

        /*
        |--------------------------------------------------------------------------
        | Request Creator
        |--------------------------------------------------------------------------
        */

        'created_by',
        'submitted_at',

        /*
        |--------------------------------------------------------------------------
        | Administrative Decision
        |--------------------------------------------------------------------------
        */

        'decision',
        'approved_amount',
        'decision_reason',
        'decided_by',
        'decided_at',

        /*
        |--------------------------------------------------------------------------
        | Financial Application
        |--------------------------------------------------------------------------
        |
        | Approval and application are separate operations.
        |
        */

        'applied_to_invoice',
        'applied_at',
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
            | Financial Values
            |--------------------------------------------------------------------------
            */

            'requested_amount' => 'decimal:4',
            'approved_amount' => 'decimal:4',

            /*
            |--------------------------------------------------------------------------
            | Application State
            |--------------------------------------------------------------------------
            */

            'applied_to_invoice' => 'boolean',

            /*
            |--------------------------------------------------------------------------
            | Dates
            |--------------------------------------------------------------------------
            */

            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
            'applied_at' => 'datetime',

            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * Invoice for which the penalty discount was requested.
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    /**
     * Citizen associated with the invoice/request.
     */
    public function citizen(): BelongsTo
    {
        return $this->belongsTo(Citizen::class, 'citizen_id');
    }

    /**
     * User who created the penalty discount request.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * User who made the administrative decision.
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}