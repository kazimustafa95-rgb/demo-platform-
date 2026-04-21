<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidJurisdictionFocus implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $focus = trim((string) $value);

        if (
            in_array(strtolower($focus), ['federal', 'state'], true) ||
            preg_match('/^[A-Z]{2}$/', strtoupper($focus))
        ) {
            return;
        }

        $fail('Jurisdiction focus must be federal, state, or a valid two-letter state code.');
    }
}
