<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use App\Rules\BcryptPassword;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CreateAdmin extends Command
{
    protected $signature = 'bank:create-admin {name : Administrator name} {email : A new administrator email address}';

    protected $description = 'Create a new admin using a privately entered password';

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Run this command interactively to enter the password privately.');

            return self::FAILURE;
        }

        $data = [
            'name' => trim($this->argument('name')),
            'email' => mb_strtolower(trim($this->argument('email'))),
            'password' => $this->secret('Password', false),
            'password_confirmation' => $this->secret('Confirm password', false),
        ];
        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['bail', 'required', 'string', 'min:12', new BcryptPassword, 'confirmed'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        try {
            $user = new User;
            $user->name = $data['name'];
            $user->email = $data['email'];
            $user->password = Hash::make($data['password']);
            $user->role = UserRole::Admin;
            $user->save();
        } catch (UniqueConstraintViolationException $exception) {
            $this->error('The email has already been taken.');

            return self::FAILURE;
        }

        $this->info('Admin created. Sign in through the normal login endpoint.');

        return self::SUCCESS;
    }
}
