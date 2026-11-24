<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An academic session such as "2024/2025": two consecutive years, from 2000
 * up to next year's session.
 */
class AcademicYear implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match('/^\s*(\d{4})\s*\/\s*(\d{4})\s*$/', $value, $m) !== 1) {
            $fail('The :attribute must look like "2024/2025".');

            return;
        }

        [$start, $end] = [(int) $m[1], (int) $m[2]];

        if ($end !== $start + 1) {
            $fail('The :attribute must be two consecutive years, e.g. "2024/2025".');

            return;
        }

        if ($start < 2000 || $start > (int) date('Y') + 1) {
            $fail('The :attribute is out of range.');
        }
    }
}
