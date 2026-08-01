<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Request a password reset link.
 *
 * Note there is deliberately no `exists:users,email` rule. That rule would
 * turn this endpoint into an account-enumeration oracle: a 422 would mean "no
 * such account" and a 200 would mean "account exists". The controller returns
 * an identical response either way.
 */
final class ForgotPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => strtolower(trim((string) $this->input('email')))]);
        }
    }

    public function email(): string
    {
        return (string) $this->validated('email');
    }
}
