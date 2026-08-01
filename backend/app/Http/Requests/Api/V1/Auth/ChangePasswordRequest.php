<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Change a password while authenticated.
 *
 * `current_password` is required even though the caller is already
 * authenticated: a stolen token would otherwise be enough to lock the real
 * owner out of their own account by changing the password.
 *
 * The "differs from current" check is not expressible as a validation rule
 * without hashing here, so it lives in the service, which already holds the
 * account.
 */
final class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', 'different:current_password', Password::defaults()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.different' => 'The new password must differ from your current password.',
            'password.confirmed' => 'The password confirmation does not match.',
        ];
    }

    public function currentPassword(): string
    {
        return (string) $this->validated('current_password');
    }

    public function newPassword(): string
    {
        return (string) $this->validated('password');
    }
}
