<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_registration_creates_customer_and_issues_hashed_expiring_token(): void
    {
        $this->travelTo(now()->startOfSecond());
        $response = $this->postJson('/api/v1/auth/register', $this->registration(['email' => '  Customer@Example.COM  ']));

        $response->assertCreated()->assertJsonPath('data.user.role', 'customer')
            ->assertJsonPath('data.user.email', 'customer@example.com')
            ->assertJsonPath('data.token_type', 'Bearer');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $user = User::sole();
        $this->assertSame(UserRole::Customer, $user->role);
        $this->assertTrue(Hash::check('correct-password-123', $user->password));
        $token = PersonalAccessToken::findToken($response->json('data.access_token'));
        $this->assertTrue($token->tokenable->is($user));
        $this->assertTrue($token->expires_at->equalTo(now()->addDay()));
        $this->assertNotSame($response->json('data.access_token'), $token->token);
        $this->assertSame(['id', 'name', 'email', 'role'], array_keys($response->json('data.user')));
        $this->assertDatabaseCount('accounts', 0);

        $this->withToken($response->json('data.access_token'))->getJson('/api/v1/auth/me')
            ->assertOk()->assertExactJson(['data' => ['id' => $user->id, 'name' => 'Customer', 'email' => $user->email, 'role' => 'customer']]);
    }

    #[DataProvider('invalidRegistration')]
    public function test_invalid_registration_returns_422_without_creating_user_or_token(array $changes, string $field, string $message): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->registration($changes));

        $response->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertStringContainsString($message, $response->json('errors.'.$field.'.0'));
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    /** @return array<string, array{array<string, mixed>, string, string}> */
    public static function invalidRegistration(): array
    {
        return [
            'missing name' => [['name' => ''], 'name', 'required'],
            'long name' => [['name' => str_repeat('n', 256)], 'name', '255'],
            'invalid email' => [['email' => 'not-an-email'], 'email', 'valid email'],
            'array email' => [['email' => ['bad']], 'email', 'string'],
            'short password' => [['password' => 'short', 'password_confirmation' => 'short'], 'password', '12'],
            'unconfirmed password' => [['password_confirmation' => 'different'], 'password', 'confirmation'],
            'long password' => [['password' => str_repeat('a', 73)], 'password', '72 bytes'],
            'multibyte password over bcrypt limit' => [['password' => str_repeat('é', 37)], 'password', '72 bytes'],
            'null byte password' => [['password' => "valid-password\0"], 'password', 'null characters'],
            'admin role injection' => [['role' => 'admin'], 'role', 'prohibited'],
        ];
    }

    public function test_duplicate_email_returns_422_case_insensitively(): void
    {
        User::factory()->create(['email' => 'customer@example.com']);

        $this->postJson('/api/v1/auth/register', $this->registration(['email' => 'CUSTOMER@example.com']))
            ->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_registration_rolls_back_user_when_token_creation_fails(): void
    {
        $this->withoutExceptionHandling();
        Event::listen('eloquent.creating: '.PersonalAccessToken::class, function (): void {
            throw new RuntimeException('Token creation failed.');
        });

        try {
            $this->postJson('/api/v1/auth/register', $this->registration());
            $this->fail('Registration should fail when token creation fails.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Token creation failed.', $exception->getMessage());
        }

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_a_hash_shaped_password_is_treated_as_plain_input(): void
    {
        $password = Hash::make('short');

        $this->postJson('/api/v1/auth/register', $this->registration([
            'password' => $password,
            'password_confirmation' => $password,
        ]))->assertCreated();

        $this->assertTrue(Hash::check($password, User::sole()->password));
        $this->assertFalse(Hash::check('short', User::sole()->password));
    }

    public function test_login_accepts_normalized_email_and_preserves_admin_role(): void
    {
        $user = User::factory()->admin()->create(['email' => 'admin@example.com', 'password' => Hash::make('correct-password-123')]);

        $response = $this->postJson('/api/v1/auth/login', ['email' => ' Admin@Example.com ', 'password' => 'correct-password-123']);

        $response->assertOk()->assertJsonPath('data.user.role', 'admin');
        $this->assertTrue(PersonalAccessToken::findToken($response->json('data.access_token'))->tokenable->is($user));
    }

    #[DataProvider('invalidCredentials')]
    public function test_invalid_credentials_return_the_same_401_message_and_no_token(string $email, string $password): void
    {
        User::factory()->create(['email' => 'customer@example.com', 'password' => Hash::make('correct-password-123')]);

        $this->postJson('/api/v1/auth/login', compact('email', 'password'))
            ->assertUnauthorized()->assertExactJson(['message' => 'Invalid email or password.']);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    /** @return array<string, array{string, string}> */
    public static function invalidCredentials(): array
    {
        return [
            'unknown email' => ['missing@example.com', 'correct-password-123'],
            'wrong password' => ['customer@example.com', 'wrong-password'],
        ];
    }

    public function test_missing_login_fields_return_422(): void
    {
        $this->postJson('/api/v1/auth/login', [])->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_protected_endpoints_reject_missing_tokens_even_without_json_accept_header(): void
    {
        $this->get('/api/v1/auth/me')->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
        $this->post('/api/v1/auth/logout')->assertUnauthorized();
    }

    public function test_invalid_token_returns_401(): void
    {
        $this->withToken('invalid-token')->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_expired_token_returns_401(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('expired', ['*'], now()->subMinute());

        $this->withToken($token->plainTextToken)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_tokens_without_explicit_expiry_still_expire_after_24_hours(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test');
        $this->travel(25)->hours();

        $this->withToken($token->plainTextToken)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_login_rejects_passwords_over_the_bcrypt_byte_limit(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => 'customer@example.com', 'password' => str_repeat('é', 37)])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_logout_revokes_only_the_current_token_and_prevents_its_reuse(): void
    {
        $user = User::factory()->create();
        $current = $user->createToken('current');
        $other = $user->createToken('other');

        $this->withToken($current->plainTextToken)->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->assertModelMissing($current->accessToken);
        $this->assertModelExists($other->accessToken);
        $this->app['auth']->forgetGuards();
        $this->withToken($current->plainTextToken)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($other->plainTextToken)->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_login_is_limited_to_five_attempts_per_identity_and_ip_per_minute(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'missing@example.com', 'password' => 'wrong-password'])->assertUnauthorized();
        }

        $this->postJson('/api/v1/auth/login', ['email' => ' MISSING@example.com ', 'password' => 'wrong-password'])
            ->assertTooManyRequests()->assertHeader('Retry-After');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_registration_is_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/register', [])->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/register', [])->assertTooManyRequests();
    }

    /** @return array<string, mixed> */
    private function registration(array $changes = []): array
    {
        return array_replace([
            'name' => 'Customer',
            'email' => 'customer@example.com',
            'password' => 'correct-password-123',
            'password_confirmation' => 'correct-password-123',
        ], $changes);
    }
}
