<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class CreateSuperAdmin extends Command
{
    /**
     * @var string
     */
    protected $signature = 'admin:create
        {username : Login username for the Super Admin}
        {email : Email address for the Super Admin}
        {--name= : Display name (defaults to the username)}';

    /**
     * @var string
     */
    protected $description = 'Create a Super Admin account. The password is entered interactively and never accepted as an argument.';

    public function handle(): int
    {
        $username = User::normalizeUsername((string) $this->argument('username'));
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $name = trim((string) ($this->option('name') ?: $username));

        $identityValidator = Validator::make(
            ['username' => $username, 'email' => $email, 'name' => $name],
            [
                'username' => ['required', 'string', 'max:120', Rule::unique('users', 'username')],
                'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
                'name' => ['required', 'string', 'max:255'],
            ],
        );

        if ($identityValidator->fails()) {
            foreach ($identityValidator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $password = (string) $this->secret('Password (at least 12 characters, with letters and numbers)');
        $passwordConfirmation = (string) $this->secret('Confirm password');

        $passwordValidator = Validator::make(
            ['password' => $password, 'password_confirmation' => $passwordConfirmation],
            ['password' => ['required', 'confirmed', Password::min(12)->letters()->numbers()]],
        );

        if ($passwordValidator->fails()) {
            foreach ($passwordValidator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $superAdmin = new User([
            'name' => $name,
            'username' => $username,
            'email' => $email,
            'password' => $password,
        ]);
        $superAdmin->forceFill([
            'role' => User::ROLE_SUPER_ADMIN,
            'status' => User::STATUS_ACTIVE,
        ])->save();

        $this->info("Super Admin \"{$superAdmin->username}\" created.");

        return self::SUCCESS;
    }
}
