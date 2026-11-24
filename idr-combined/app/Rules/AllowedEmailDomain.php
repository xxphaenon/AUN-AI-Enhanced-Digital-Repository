<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Accepts only addresses at exactly config('idr.auth.allowed_email_domain')
 * (aun.edu.ng). Rejects look-alikes such as:
 *   someone@gmail.com
 *   someone@aun.edu.ng.evil.com   (domain must END the address)
 *   someone@mail.aun.edu.ng       (no subdomains)
 *   someone@evil.com@aun.edu.ng   (exactly one "@")
 * Comparison is case-insensitive; surrounding spaces are ignored.
 * Used together with Laravel's own `email` rule, which checks the format.
 */
class AllowedEmailDomain implements ValidationRule
{
    public static function domain(): string
    {
        return strtolower(config('idr.auth.allowed_email_domain', 'aun.edu.ng'));
    }

    public static function isAllowed(mixed $email): bool
    {
        if (! is_string($email)) {
            return false;
        }

        $email = strtolower(trim($email));

        if (substr_count($email, '@') !== 1) {
            return false;
        }

        [$local, $host] = explode('@', $email);

        return $local !== '' && $host === self::domain();
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::isAllowed($value)) {
            $fail('Only @'.self::domain().' email addresses can be used to create an account.');
        }
    }
}
