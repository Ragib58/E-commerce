<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Login credentials, for both customers and staff.
 *
 * Validation here is deliberately shallow — presence and type only. Applying
 * the password *policy* to a login attempt would leak information: an account
 * created before a policy change would fail validation rather than
 * authentication, revealing that the address exists.
 */
final class LoginRequest extends FormRequest
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
            'password' => ['required', 'string', 'max:255'],
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

    public function password(): string
    {
        return (string) $this->validated('password');
    }

    /**
     * Throttle key: email plus IP.
     *
     * Keying on the pair rather than either alone means a distributed attack
     * on one account is still limited, while a shared NAT does not lock out
     * every user behind it.
     */
    public function throttleKey(): string
    {
        return strtolower($this->input('email', '')) . '|' . $this->ip();
    }
}
