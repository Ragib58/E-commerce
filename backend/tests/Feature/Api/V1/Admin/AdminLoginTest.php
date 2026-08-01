<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\RoleType;
use App\Enums\TokenAbility;
use App\Models\Admin;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AdminLoginTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Correct-Horse-9!';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->app->make('cache')->flush();
    }

    private function admin(RoleType $role = RoleType::Admin, array $attributes = []): Admin
    {
        return Admin::factory()
            ->withRole($role)
            ->withPassword(self::PASSWORD)
            ->create($attributes);
    }

    #[Test]
    public function an_admin_can_log_in(): void
    {
        $admin = $this->admin(RoleType::SuperAdmin);

        $this->postJson('/api/v1/admin/auth/login', [
            'email' => $admin->email,
            'password' => self::PASSWORD,
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.admin.id', $admin->uuid)
            ->assertJsonStructure([
                'data' => [
                    'admin' => ['id', 'name', 'email', 'roles', 'permissions'],
                    'token',
                    'token_type',
                    'must_change_password',
                ],
            ]);
    }

    #[Test]
    public function the_login_response_carries_the_effective_permissions(): void
    {
        $admin = $this->admin(RoleType::ProductManager);

        $response = $this->postJson('/api/v1/admin/auth/login', [
            'email' => $admin->email,
            'password' => self::PASSWORD,
        ]);

        // The panel uses this to hide navigation it cannot use, without a
        // second round-trip on load.
        $permissions = $response->json('data.admin.permissions');

        $this->assertIsArray($permissions);
        $this->assertContains('create_products', $permissions);
        $this->assertNotContains('manage_admins', $permissions);
    }

    #[Test]
    public function the_token_carries_the_admin_ability(): void
    {
        $admin = $this->admin();

        $this->postJson('/api/v1/admin/auth/login', [
            'email' => $admin->email,
            'password' => self::PASSWORD,
        ])->assertOk();

        $this->assertContains(TokenAbility::AdminAccess->value, $admin->tokens()->sole()->abilities);
    }

    #[Test]
    public function an_incorrect_password_is_rejected(): void
    {
        $admin = $this->admin();

        $this->postJson('/api/v1/admin/auth/login', [
            'email' => $admin->email,
            'password' => 'Wrong-Password-1!',
        ])->assertStatus(422);

        $this->assertSame(0, $admin->tokens()->count());
    }

    #[Test]
    public function a_deactivated_admin_cannot_log_in(): void
    {
        $admin = $this->admin(RoleType::Admin, ['is_active' => false]);

        $this->postJson('/api/v1/admin/auth/login', [
            'email' => $admin->email,
            'password' => self::PASSWORD,
        ])->assertStatus(422);
    }

    #[Test]
    public function a_customer_cannot_log_in_through_the_admin_endpoint(): void
    {
        // Same address in both tables; the admin endpoint must only ever
        // consult the `admins` table.
        $email = 'shared@example.com';

        \App\Models\User::factory()->withPassword(self::PASSWORD)->create(['email' => $email]);

        $this->postJson('/api/v1/admin/auth/login', [
            'email' => $email,
            'password' => self::PASSWORD,
        ])->assertStatus(422);
    }

    #[Test]
    public function login_flags_an_account_that_must_rotate_its_password(): void
    {
        $admin = $this->admin(RoleType::Admin, ['must_change_password' => true]);

        $this->postJson('/api/v1/admin/auth/login', [
            'email' => $admin->email,
            'password' => self::PASSWORD,
        ])
            ->assertOk()
            ->assertJsonPath('data.must_change_password', true);
    }

    #[Test]
    public function an_admin_can_change_their_password(): void
    {
        $admin = $this->admin();
        $token = $admin->createToken('t', [TokenAbility::AdminAccess->value])->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/admin/auth/change-password', [
                'current_password' => self::PASSWORD,
                'password' => 'Brand-New-Pass-9!',
                'password_confirmation' => 'Brand-New-Pass-9!',
            ])
            ->assertOk();

        $this->assertTrue(Hash::check('Brand-New-Pass-9!', $admin->refresh()->password));
    }

    #[Test]
    public function changing_a_password_requires_the_current_one(): void
    {
        $admin = $this->admin();
        $token = $admin->createToken('t', [TokenAbility::AdminAccess->value])->plainTextToken;

        // A stolen token alone must not be enough to lock the owner out.
        $this->withToken($token)
            ->postJson('/api/v1/admin/auth/change-password', [
                'current_password' => 'Wrong-Password-1!',
                'password' => 'Brand-New-Pass-9!',
                'password_confirmation' => 'Brand-New-Pass-9!',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['current_password']]);
    }

    #[Test]
    public function changing_a_password_revokes_other_sessions_but_not_the_current_one(): void
    {
        $admin = $this->admin();

        $current = $admin->createToken('current', [TokenAbility::AdminAccess->value]);
        $other = $admin->createToken('other', [TokenAbility::AdminAccess->value]);

        $this->withToken($current->plainTextToken)
            ->postJson('/api/v1/admin/auth/change-password', [
                'current_password' => self::PASSWORD,
                'password' => 'Brand-New-Pass-9!',
                'password_confirmation' => 'Brand-New-Pass-9!',
            ])
            ->assertOk();

        // Changing a password after a compromise must actually evict the
        // attacker, while not signing the user out of the device they used.
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $other->accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $current->accessToken->id]);
    }

    #[Test]
    public function changing_a_password_clears_the_forced_rotation_flag(): void
    {
        $admin = $this->admin(RoleType::Admin, ['must_change_password' => true]);
        $token = $admin->createToken('t', [TokenAbility::AdminAccess->value])->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/admin/auth/change-password', [
                'current_password' => self::PASSWORD,
                'password' => 'Brand-New-Pass-9!',
                'password_confirmation' => 'Brand-New-Pass-9!',
            ])
            ->assertOk();

        $this->assertFalse($admin->refresh()->must_change_password);
    }

    #[Test]
    public function the_new_password_must_differ_from_the_current_one(): void
    {
        $admin = $this->admin();
        $token = $admin->createToken('t', [TokenAbility::AdminAccess->value])->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/admin/auth/change-password', [
                'current_password' => self::PASSWORD,
                'password' => self::PASSWORD,
                'password_confirmation' => self::PASSWORD,
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function admin_login_is_rate_limited(): void
    {
        $admin = $this->admin();
        $limit = (int) config('api.rate_limits.admin_auth');

        for ($attempt = 0; $attempt < $limit; $attempt++) {
            $this->postJson('/api/v1/admin/auth/login', [
                'email' => $admin->email,
                'password' => 'Wrong-Password-1!',
            ])->assertStatus(422);
        }

        $this->postJson('/api/v1/admin/auth/login', [
            'email' => $admin->email,
            'password' => 'Wrong-Password-1!',
        ])->assertStatus(429);
    }
}
