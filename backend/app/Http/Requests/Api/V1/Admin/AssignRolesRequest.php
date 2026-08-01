<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Admin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AssignRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Admin|null $target */
        $target = $this->route('admin');

        return $target !== null && ($this->user()?->can('assignRoles', $target) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // `present` rather than `required`: an empty array is a valid
            // instruction meaning "remove all roles", whereas a missing key
            // is a malformed request.
            'roles' => ['present', 'array'],
            'roles.*' => ['string', Rule::exists('roles', 'name')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'roles.*.exists' => 'One or more of the selected roles does not exist.',
        ];
    }

    /**
     * @return array<int, string>
     */
    public function roles(): array
    {
        return array_values(array_unique((array) $this->validated('roles')));
    }
}
