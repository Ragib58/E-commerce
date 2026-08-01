<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Auth;

use App\Enums\RoleType;
use App\Enums\TokenAbility;
use App\Models\Admin;
use App\Models\User;
use App\Notifications\AdminPasswordResetNotification;
use App\Notifications\CustomerPasswordResetNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_PASSWORD = 'Brand-New-Pass-9!';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make('cache')->flush();
    }

    #[Test]
    public function a_customer_can_request_a_reset_link(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])
            ->assertOk()
            ->assertJsonPath('success', true);

        Notification::assertSentTo($user, CustomerPasswordResetNotification::class);
    }

    #[Test]
    public function requesting_a_link_for_an_unknown_email_returns_the_same_response(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email]);
        $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com']);

        // Identical status and message: any difference would turn this
        // endpoint into an account-enumeration oracle.
        $this->assertSame($known->status(), $unknown->status());
        $this->assertSame($known->json('message'), $unknown->json('message'));

        Notification::assertCount(1);
    }

    #[Test]
    public function a_customer_can_reset_their_password_with_a_valid_token(): void
    {
        $user = User::factory()->create();
        $token = Password::broker('users')->createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->refresh()->password));
    }

    #[Test]
    public function resetting_a_password_revokes_every_existing_session(): void
    {
        $user = User::factory()->create();
        $user->createToken('phone', [TokenAbility::CustomerAccess->value]);
        $user->createToken('laptop', [TokenAbility::CustomerAccess->value]);

        $token = Password::broker('users')->createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk();

        // A reset is the recovery path after a compromise; sparing any session
        // would leave an attacker signed in after the owner "fixed" it.
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    #[Test]
    public function an_invalid_token_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => 'not-a-real-token',
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['email']]);
    }

    #[Test]
    public function a_reset_token_cannot_be_reused(): void
    {
        $user = User::factory()->create();
        $token = Password::broker('users')->createToken($user);

        $payload = [
            'token' => $token,
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ];

        $this->postJson('/api/v1/auth/reset-password', $payload)->assertOk();

        // The token row is consumed on success, so a leaked link cannot be
        // replayed to seize the account later.
        $this->postJson('/api/v1/auth/reset-password', $payload)->assertStatus(422);
    }

    #[Test]
    public function a_weak_new_password_is_rejected(): void
    {
        $user = User::factory()->create();
        $token = Password::broker('users')->createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'password',
            'password_confirmation' => 'password',
        ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['password']]);
    }

    #[Test]
    public function a_customer_reset_token_cannot_reset_an_admin_account(): void
    {
        // The critical separation test. Both accounts share an email address;
        // a shared token table would let a customer reset a staff password.
        $email = 'shared@example.com';

        $user = User::factory()->create(['email' => $email]);
        $admin = Admin::factory()->withRole(RoleType::SuperAdmin)->create(['email' => $email]);

        $customerToken = Password::broker('users')->createToken($user);
        $originalAdminPassword = $admin->password;

        $this->postJson('/api/v1/admin/auth/reset-password', [
            'token' => $customerToken,
            'email' => $email,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertStatus(422);

        $this->assertSame($originalAdminPassword, $admin->refresh()->password);
    }

    #[Test]
    public function an_admin_can_request_and_complete_a_reset(): void
    {
        Notification::fake();

        $admin = Admin::factory()->withRole(RoleType::Admin)->create();

        $this->postJson('/api/v1/admin/auth/forgot-password', ['email' => $admin->email])
            ->assertOk();

        Notification::assertSentTo($admin, AdminPasswordResetNotification::class);

        $token = Password::broker('admins')->createToken($admin);

        $this->postJson('/api/v1/admin/auth/reset-password', [
            'token' => $token,
            'email' => $admin->email,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk();

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $admin->refresh()->password));
    }

    #[Test]
    public function an_admin_reset_clears_the_forced_password_change_flag(): void
    {
        $admin = Admin::factory()->withRole(RoleType::Admin)->mustChangePassword()->create();

        $token = Password::broker('admins')->createToken($admin);

        $this->postJson('/api/v1/admin/auth/reset-password', [
            'token' => $token,
            'email' => $admin->email,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk();

        // The rotation requirement is satisfied by the reset itself.
        $this->assertFalse($admin->refresh()->must_change_password);
    }

    #[Test]
    public function repeated_reset_requests_for_one_address_are_throttled(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();

        // The broker enforces a per-address cooldown (auth.passwords.users
        // .throttle, 60s). The response stays 200 regardless — surfacing the
        // throttle would reveal that this address has an account.
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();

        // The protection is real even though the response is unchanged: only
        // one email leaves, so this endpoint cannot flood a victim's mailbox.
        Notification::assertCount(1);
    }

    #[Test]
    public function a_throttled_reset_request_never_reveals_whether_the_account_exists(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        // Exhaust the cooldown for a real address...
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();
        $throttledKnown = $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email]);

        // ...and for one that does not exist.
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'ghost@example.com'])->assertOk();
        $throttledUnknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'ghost@example.com']);

        // Both must behave identically. A registered address that throttles
        // while an unregistered one does not would leak exactly the fact the
        // generic success message exists to hide.
        $this->assertSame($throttledKnown->status(), $throttledUnknown->status());
    }
}
