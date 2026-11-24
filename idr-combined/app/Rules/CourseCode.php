<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A course code such as "CSC 401", "SEN301" or "csc 499a": 2–5 letters,
 * optional space, 3 digits, optional letter. Stored normalised as
 * "CSC 401" (see Artifact::normalizeCourseCode()).
 */
class CourseCode implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match('/^\s*[A-Za-z]{2,5}\s*\d{3}[A-Za-z]?\s*$/', $value) !== 1) {
            $fail('The :attribute must look like "CSC 401" (2–5 letters followed by 3 digits).');
        }
    }
}
