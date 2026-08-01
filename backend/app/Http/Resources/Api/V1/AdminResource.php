<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * A staff account.
 *
 * The `permissions` array is what lets the admin frontend hide navigation and
 * actions the current account cannot use. That is a usability measure, not a
 * security one — the API enforces every permission independently, so a client
 * that ignores this list gains nothing but 403s.
 *
 * @mixin Admin
 */
final class AdminResource extends JsonResource
{
    /**
     * Include the effective permission list.
     *
     * Sent for the authenticated account's own record (`/admin/auth/me`), and
     * omitted when listing other administrators — a list of fifty admins would
     * otherwise carry fifty resolved permission sets for no purpose.
     */
    private bool $withPermissions = false;

    public function withPermissions(bool $include = true): self
    {
        $this->withPermissions = $include;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payload = [
            'id' => $this->uuid,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'avatar_url' => $this->avatarUrl(),

            'is_active' => $this->is_active,
            'must_change_password' => $this->must_change_password,

            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),

            'roles' => RoleResource::collection($this->whenLoaded('roles')),

            // Surfaced so the UI can disable actions against higher-ranked
            // accounts rather than letting the user discover the rule via a
            // rejected request.
            'role_level' => $this->whenLoaded('roles', fn (): int => $this->roleLevel()),
            'is_super_admin' => $this->whenLoaded('roles', fn (): bool => $this->isSuperAdmin()),
        ];

        if ($this->withPermissions) {
            $payload['permissions'] = $this->effectivePermissions();
        }

        return $payload;
    }

    private function avatarUrl(): ?string
    {
        if ($this->avatar_path === null || $this->avatar_path === '') {
            return null;
        }

        if (str_starts_with($this->avatar_path, 'http://') || str_starts_with($this->avatar_path, 'https://')) {
            return $this->avatar_path;
        }

        return Storage::disk(config('filesystems.default'))->url($this->avatar_path);
    }
}
