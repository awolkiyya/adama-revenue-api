<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;
use Illuminate\Support\Facades\Cache;

class UniqueInImportBatch implements Rule
{
    public function __construct(
        protected string $batchId,
        protected string $field
    ) {}

    public function passes($attribute, $value): bool
    {
        $value = trim((string) $value);

        if ($value === '') {
            return true; // let 'required' handle empty values
        }

        $key = "import:{$this->batchId}:{$this->field}:" . strtolower($value);

        // Cache::add is atomic — returns false if the key already exists,
        // so it's safe even when chunks run as parallel queue jobs.
        return Cache::add($key, true, now()->addHours(2));
    }

    public function message(): string
    {
        return 'The :attribute is duplicated within the uploaded file.';
    }
}