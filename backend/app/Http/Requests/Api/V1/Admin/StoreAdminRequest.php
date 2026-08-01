<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Admin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Create a staff account.
 *
 * Authorization is the policy's job; this validates shape only. The rank rules
 * governing *which* roles may be assigned live in AdminManagementService,
 * because they depend on the actor's own level rather than on the input alone.
 */
final class StoreAdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Admin::class) ?? false;
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
                'email:rfc,strict',
                'max:255',
                // Scoped to `admins` only. A staff address may legitimately
                // coincide with a customer address — they are different
                // accounts in different tables.
                Rule::unique('admins', 'email'),
            ],

            // Optional: when omitted, the service generates a strong password
            // and flags the account for forced rotation at first login.
            'password' => ['nullable', 'confirmed', Password::defaults()],

            'phone' => ['nullable', 'string', 'max:32', 'regex:/^[\d\s()+-]+$/'],

            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', Rule::exists('roles', 'name')],

            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'An administrator with this email address already exists.',
            'roles.required' => 'At least one role must be assigned.',
            'roles.*.exists' => 'One or more of the selected roles does not exist.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => strtolower(trim((string) $this->input('email')))]);
        }
    }

    /**
     * Validated fields, shaped for AdminManagementService::create().
     *
     * Named `payload()` rather than `data()`: Symfony's HttpFoundation Request
     * already defines `data()` with a different signature, and overriding it
     * is a fatal incompatibility.
     *
     * @return array{name: string, email: string, password: string|null, phone: string|null, roles: array<int, string>, is_active: bool}
     */
    public function payload(): array
    {
        return [
            'name' => (string) $this->validated('name'),
            'email' => (string) $this->validated('email'),
            'password' => $this->validated('password'),
            'phone' => $this->validated('phone'),
            'roles' => (array) $this->validated('roles'),
            'is_active' => (bool) ($this->validated('is_active') ?? true),
        ];
    }
}
