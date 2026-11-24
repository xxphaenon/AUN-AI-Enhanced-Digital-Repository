<?php

namespace App\Http\Requests\Auth;

use App\Rules\AllowedEmailDomain;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * POST /api/auth/register
 *   { "name", "email", "password", "password_confirmation", "universityId"? }
 *
 * Any "role" sent by the client is ignored: new accounts are always students.
 */
class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => strtolower(trim($this->input('email')))]);
        }

        if (is_string($this->input('universityId'))) {
            $this->merge(['universityId' => strtoupper(trim($this->input('universityId'))) ?: null]);
        }

        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim(preg_replace('/\s+/', ' ', $this->input('name')))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'email' => [
                // Stop at the first failure, so a gmail address gets the
                // domain error rather than "already taken".
                'bail',
                'required',
                'string',
                'max:254',
                'email:strict',
                new AllowedEmailDomain,
                'unique:users,email',
            ],
            'password' => ['required', 'string', 'confirmed', 'max:128', Password::min(8)->letters()->numbers()],
            'universityId' => ['nullable', 'string', 'max:30', 'regex:/^[A-Z0-9\/\-]+$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'universityId.regex' => 'Use only letters, numbers, "/" and "-" in your ID.',
            'email.unique' => 'An account with this email already exists. Try logging in instead.',
        ];
    }
}
