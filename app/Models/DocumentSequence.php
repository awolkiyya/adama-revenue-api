<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DocumentSequence extends Model
{
    use HasFactory;

    /**
     * Database table.
     */
    protected $table = 'document_sequences';

    /**
     * Mass assignable attributes.
     */
    protected $fillable = [
        'sequence_type',
        'year',
        'current_value',
    ];

    /**
     * Attribute casts.
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'current_value' => 'integer',
        ];
    }

    /**
     * Scope a sequence by document type and year.
     */
    public function scopeFor(
        $query,
        string $sequenceType,
        int $year
    ) {
        return $query
            ->where('sequence_type', $sequenceType)
            ->where('year', $year);
    }
}

