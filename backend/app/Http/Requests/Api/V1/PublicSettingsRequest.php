<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\SettingGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the optional group filter on the public settings endpoint.
 *
 * The allowed set is restricted to publicly-exposable groups, so a request for
 * `?group=payment` is rejected at validation rather than silently returning an
 * empty result — a clearer contract for the client and one less way to probe
 * which private groups exist.
 */
final class PublicSettingsRequest extends FormRequest
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
            'group' => [
                'sometimes',
                'string',
                Rule::in(array_map(
                    static fn (SettingGroup $group): string => $group->value,
                    SettingGroup::publiclyExposable()
                )),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'group.in' => 'The selected group is not available publicly.',
        ];
    }

    /**
     * The validated group, or null when the client wants every group.
     */
    public function group(): ?SettingGroup
    {
        $group = $this->validated('group');

        return is_string($group) ? SettingGroup::tryFrom($group) : null;
    }
}
