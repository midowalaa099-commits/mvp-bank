<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;

class PreviewUserSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            ['key' => 'admin', 'email' => 'preview-admin@example.test', 'name' => 'Preview Admin', 'role' => UserRole::Admin],
            ['key' => 'customer', 'email' => 'frontend-check@example.test', 'name' => 'Preview Customer', 'role' => UserRole::Customer],
            ['key' => 'recipient', 'email' => 'preview-recipient@example.test', 'name' => 'Preview Recipient', 'role' => UserRole::Customer],
        ];

        foreach ($accounts as $account) {
            $password = config('preview.passwords.'.$account['key']);

            if (! is_string($password) || $password === '' || strlen($password) > 72 || str_contains($password, "\0")) {
                throw new InvalidArgumentException('Provide all three preview passwords, each between 1 and 72 bytes with no null characters.');
            }
        }

        DB::transaction(function () use ($accounts): void {
            foreach ($accounts as $account) {
                $user = User::query()->firstOrNew(['email' => $account['email']]);
                $password = config('preview.passwords.'.$account['key']);

                if (! $user->exists) {
                    $user->name = $account['name'];
                }

                if (! $user->exists || Hash::needsRehash($user->password) || ! Hash::check($password, $user->password)) {
                    $user->password = Hash::make($password);
                }

                $user->role = $account['role'];

                if ($user->isDirty()) {
                    $user->save();
                }
            }
        });
    }
}
