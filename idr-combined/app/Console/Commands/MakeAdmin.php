<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Rules\AllowedEmailDomain;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Create the first admin (or promote an existing account), since nobody
 * can register as an admin through the API.
 *
 *   php artisan idr:make-admin someone@aun.edu.ng
 *   php artisan idr:make-admin someone@aun.edu.ng --name="Dr. Bello"
 */
class MakeAdmin extends Command
{
    protected $signature = 'idr:make-admin
                            {email : An @aun.edu.ng address}
                            {--name= : Display name (new accounts only)}
                            {--password= : Password for a new account (asked for if omitted)}';

    protected $description = 'Create an admin account, or promote an existing user to admin';

    public function handle(): int
    {
        $email = strtolower(trim($this->argument('email')));

        if (! AllowedEmailDomain::isAllowed($email)) {
            $this->error('Only @'.AllowedEmailDomain::domain().' addresses are allowed.');

            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();

        if ($user !== null) {
            if ($user->isAdmin()) {
                $this->info("{$email} is already an admin.");

                return self::SUCCESS;
            }

            $user->forceFill(['role' => User::ROLE_ADMIN])->save();
            $this->info("Promoted {$email} to admin.");

            return self::SUCCESS;
        }

        $name = $this->option('name') ?: $this->ask('Name', 'Repository Admin');
        $password = $this->option('password') ?: $this->secret('Password (min 8 characters, letters and numbers)');

        $validator = Validator::make(
            ['name' => $name, 'password' => $password],
            ['name' => ['required', 'string', 'min:2', 'max:100'], 'password' => ['required', Password::min(8)->letters()->numbers()]],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $user = new User(['name' => $name, 'email' => $email, 'password' => $password]);
        $user->forceFill(['role' => User::ROLE_ADMIN])->save();

        $this->info("Created admin {$email} (id {$user->id}).");

        return self::SUCCESS;
    }
}
