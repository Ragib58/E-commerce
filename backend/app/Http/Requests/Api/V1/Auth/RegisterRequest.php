<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class RegisterRequest extends FormRequest
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
            'name' => ['required', 'string', 'min:2', 'max:120'],

            'email' => [
                'required',
                'string',
                // `rfc,strict` rejects addresses that are technically parseable
                // but practically undeliverable, catching typos at signup
                // rather than at first send.
                'email:rfc,strict',
                'max:255',
                // Soft-deleted rows still occupy the unique index, so the
                // uniqueness check must not exclude them — otherwise
                // registration would fail at the database with a 500.
                Rule::unique('users', 'email'),
            ],

            // Password::defaults() is configured in AppServiceProvider: min 12
            // with mixed character classes, plus a HaveIBeenPwned check in
            // production. Referencing the default keeps the policy in one place.
            'password' => ['required', 'confirmed', Password::defaults()],

            'phone' => ['nullable', 'string', 'max:32', 'regex:/^[\d\s()+-]+$/'],

            // Explicit consent, recorded as a deliberate action rather than
            // inferred from the act of registering.
            'accepts_terms' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'An account with this email address already exists.',
            'password.confirmed' => 'The password confirmation does not match.',
            'accepts_terms.accepted' => 'You must accept the terms and conditions to register.',
            'phone.regex' => 'The phone number contains invalid characters.',
        ];
    }

    protected function prepareForValidation(): void
    {
        // Normalise before validating so the uniqueness check is
        // case-insensitive in practice — otherwise "User@x.com" and
        // "user@x.com" would both be accepted as distinct accounts.
        if ($this->has('email')) {
            $this->merge(['email' => strtolower(trim((string) $this->input('email')))]);
        }

        if ($this->has('name')) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
        }
    }

    /**
     * Validated fields, shaped for CustomerAuthService::register().
     *
     * Named `payload()` rather than `data()`: Symfony's HttpFoundation Request
     * already defines `data()` with a different signature, and overriding it
     * is a fatal incompatibility.
     *
     * @return array{name: string, email: string, password: string, phone: string|null}
     */
    public function payload(): array
    {
        return [
            'name' => (string) $this->validated('name'),
            'email' => (string) $this->validated('email'),
            'password' => (string) $this->validated('password'),
            'phone' => $this->validated('phone'),
        ];
    }
}
