<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Auth;

use App\Models\User;
use App\Notifications\CustomerVerifyEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'Correct-Horse-9!',
            'password_confirmation' => 'Correct-Horse-9!',
            'accepts_terms' => true,
        ], $overrides);
    }

    #[Test]
    public function a_customer_can_register_and_receives_a_token(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/v1/auth/register', $this->payload());

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'user' => ['id', 'name', 'email', 'email_verified'],
                    'token',
                    'token_type',
                ],
            ]);

        $this->assertDatabaseHas('users', [
            'email' => 'ada@example.com',
            'is_active' => true,
        ]);

        // The public identifier must be the uuid, never the auto-increment id.
        $user = User::query()->where('email', 'ada@example.com')->sole();
        $response->assertJsonPath('data.user.id', $user->uuid);
    }

    #[Test]
    public function registration_sends_a_verification_email(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/register', $this->payload())->assertCreated();

        $user = User::query()->where('email', 'ada@example.com')->sole();

        Notification::assertSentTo($user, CustomerVerifyEmailNotification::class);
    }

    #[Test]
    public function the_password_is_hashed_and_never_returned(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/v1/auth/register', $this->payload());

        $user = User::query()->where('email', 'ada@example.com')->sole();

        $this->assertNotSame('Correct-Horse-9!', $user->password);
        $this->assertTrue(password_verify('Correct-Horse-9!', $user->password));

        // The plaintext must not appear anywhere in the response body.
        $this->assertStringNotContainsString('Correct-Horse-9!', $response->getContent() ?: '');
        $response->assertJsonMissingPath('data.user.password');
    }

    #[Test]
    public function a_new_account_starts_unverified(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/register', $this->payload())
            ->assertJsonPath('data.user.email_verified', false);

        $this->assertNull(User::query()->where('email', 'ada@example.com')->sole()->email_verified_at);
    }

    #[Test]
    public function a_duplicate_email_is_rejected(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $this->postJson('/api/v1/auth/register', $this->payload())
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['errors' => ['email']]);
    }

    #[Test]
    public function email_comparison_for_uniqueness_is_case_insensitive(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        // Without normalisation this would create a second, distinct account
        // that the user could never log into predictably.
        $this->postJson('/api/v1/auth/register', $this->payload(['email' => 'ADA@Example.com']))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['email']]);
    }

    #[Test]
    public function a_weak_password_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload([
            'password' => 'password',
            'password_confirmation' => 'password',
        ]))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['password']]);
    }

    #[Test]
    public function the_password_confirmation_must_match(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload([
            'password_confirmation' => 'Different-Horse-9!',
        ]))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['password']]);
    }

    #[Test]
    public function the_terms_must_be_accepted(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload(['accepts_terms' => false]))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['accepts_terms']]);
    }

    #[Test]
    public function registration_cannot_create_an_administrator(): void
    {
        Notification::fake();

        // Mass-assigning fields that once granted admin access, or that exist
        // on the Admin model, must have no effect: staff live in a different
        // table entirely.
        $this->postJson('/api/v1/auth/register', $this->payload([
            'is_admin' => true,
            'is_active' => true,
            'must_change_password' => false,
        ]))->assertCreated();

        $this->assertDatabaseCount('admins', 0);
        $this->assertArrayNotHasKey('is_admin', User::query()->sole()->getAttributes());
    }
}
