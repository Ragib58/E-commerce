<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Admin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateAdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Admin|null $target */
        $target = $this->route('admin');

        return $target !== null && ($this->user()?->can('update', $target) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Admin|null $target */
        $target = $this->route('admin');

        return [
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:120'],

            'email' => [
                'sometimes',
                'required',
                'string',
                'email:rfc,strict',
                'max:255',
                // Ignore this record, or updating any other field would fail
                // uniqueness against the account's own address.
                Rule::unique('admins', 'email')->ignore($target?->getKey()),
            ],

            'phone' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^[\d\s()+-]+$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'Another administrator already uses this email address.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => strtolower(trim((string) $this->input('email')))]);
        }
    }
}
