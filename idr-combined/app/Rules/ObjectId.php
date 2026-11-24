<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A MongoDB ObjectId in its 24-character hex string form.
 */
class ObjectId implements ValidationRule
{
    public static function isValid(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{24}$/i', $value) === 1;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::isValid($value)) {
            $fail('The :attribute must be a valid MongoDB id (24 hex characters).');
        }
    }
}
