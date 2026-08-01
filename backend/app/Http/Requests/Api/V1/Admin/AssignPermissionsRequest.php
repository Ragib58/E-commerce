<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Admin;
use App\Models\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Set per-admin permission overrides.
 *
 * Payload shape:
 *   { "permissions": { "refund_orders": true, "delete_products": false } }
 *
 * A value of false is an explicit *revoke* that overrides any role granting
 * that permission — not merely an omission. Omitting a key removes the
 * override entirely, restoring whatever the roles say.
 */
final class AssignPermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Admin|null $target */
        $target = $this->route('admin');

        return $target !== null && ($this->user()?->can('assignPermissions', $target) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'permissions' => ['present', 'array'],
            // Validates the *keys* of the map against known permission names,
            // so an unknown permission is rejected rather than silently
            // ignored by the sync.
            'permissions.*' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $permissions = (array) $this->input('permissions', []);

            $known = Permission::query()
                ->whereIn('name', array_keys($permissions))
                ->pluck('name')
                ->all();

            $unknown = array_diff(array_keys($permissions), $known);

            foreach ($unknown as $name) {
                $validator->errors()->add(
                    "permissions.{$name}",
                    "Unknown permission: {$name}.",
                );
            }
        });
    }

    /**
     * @return array<string, bool>
     */
    public function permissions(): array
    {
        /** @var array<string, mixed> $raw */
        $raw = (array) $this->validated('permissions');

        return array_map(static fn (mixed $value): bool => (bool) $value, $raw);
    }
}
