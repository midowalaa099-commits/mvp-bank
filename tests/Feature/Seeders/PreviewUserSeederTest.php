<?php

namespace Tests\Feature\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\PreviewUserSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PreviewUserSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['preview.passwords' => [
            'admin' => 'test-admin-secret',
            'customer' => 'test-customer-secret',
            'recipient' => 'test-recipient-secret',
        ]]);
    }

    #[DataProvider('previewAccounts')]
    public function test_seeded_account_can_log_in_and_identify_itself_with_correct_role(string $email, string $password, string $role): void
    {
        $this->seed(PreviewUserSeeder::class);

        $response = $this->postJson('/api/v1/auth/login', compact('email', 'password'));

        $response->assertOk()->assertJsonPath('data.user.email', $email)->assertJsonPath('data.user.role', $role);
        $user = User::query()->where('email', $email)->sole();
        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertDatabaseCount('users', 3);
        $this->assertDatabaseCount('accounts', 0);
        $this->withToken($response->json('data.access_token'))->getJson('/api/v1/auth/me')
            ->assertOk()->assertJsonPath('data.id', $user->id)->assertJsonPath('data.email', $email)->assertJsonPath('data.role', $role);
    }

    /** @return array<string, array{string, string, string}> */
    public static function previewAccounts(): array
    {
        return [
            'admin' => ['preview-admin@example.test', 'test-admin-secret', 'admin'],
            'customer' => ['frontend-check@example.test', 'test-customer-secret', 'customer'],
            'recipient' => ['preview-recipient@example.test', 'test-recipient-secret', 'customer'],
        ];
    }

    public function test_repeated_seeding_preserves_hashes_and_timestamps(): void
    {
        $this->freezeTime();
        $this->seed(PreviewUserSeeder::class);
        $before = User::query()->orderBy('id')->get()->map->getRawOriginal()->all();
        $this->travel(1)->hour();

        $this->seed(PreviewUserSeeder::class);

        $this->assertSame($before, User::query()->orderBy('id')->get()->map->getRawOriginal()->all());
    }

    #[DataProvider('incorrectPasswords')]
    public function test_repairs_only_target_credentials_and_role_preserving_existing_profile(bool $malformedHash): void
    {
        $unrelated = User::factory()->admin()->create();
        $unrelatedBefore = $unrelated->fresh()->getRawOriginal();
        $target = User::factory()->unverified()->create(['email' => 'preview-admin@example.test']);
        if ($malformedHash) {
            DB::table('users')->where('id', $target->id)->update(['password' => 'invalid-hash']);
        }

        $this->seed(PreviewUserSeeder::class);

        $repaired = $target->fresh();
        $this->assertSame(UserRole::Admin, $repaired->role);
        $this->assertTrue(Hash::check('test-admin-secret', $repaired->password));
        $this->assertSame($target->name, $repaired->name);
        $this->assertNull($repaired->email_verified_at);
        $this->assertSame($unrelatedBefore, $unrelated->fresh()->getRawOriginal());
        $this->assertDatabaseCount('users', 4);
    }

    /** @return array<string, array{bool}> */
    public static function incorrectPasswords(): array
    {
        return ['wrong password' => [false], 'malformed hash' => [true]];
    }

    #[DataProvider('invalidPasswords')]
    public function test_invalid_configuration_prevents_all_user_changes(?string $password): void
    {
        config(['preview.passwords.recipient' => $password]);
        $before = User::query()->count();

        try {
            $this->seed(PreviewUserSeeder::class);
            $this->fail('Invalid preview configuration must fail before writing users.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Provide all three preview passwords, each between 1 and 72 bytes with no null characters.', $exception->getMessage());
        }

        $this->assertDatabaseCount('users', $before);
    }

    /** @return array<string, array{?string}> */
    public static function invalidPasswords(): array
    {
        return [
            'missing' => [null],
            'empty' => [''],
            'too long' => [str_repeat('a', 73)],
            'null character' => ["invalid\0secret"],
        ];
    }
}
