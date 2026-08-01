<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Update the authenticated customer's profile.
 *
 * Email is deliberately absent. Changing an email address invalidates
 * verification and is a common account-takeover vector, so it needs its own
 * confirm-by-email flow rather than being folded into a profile form.
 * Likewise `is_active` — a customer must not be able to reactivate an account
 * that support deactivated.
 */
final class UpdateProfileRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:120'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^[\d\s()+-]+$/'],
            // `before:today` rejects a future birth date, which is almost
            // always a date-picker mistake rather than intent.
            'date_of_birth' => ['sometimes', 'nullable', 'date', 'before:today'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => 'The phone number contains invalid characters.',
            'date_of_birth.before' => 'The date of birth must be in the past.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
        }
    }
}
