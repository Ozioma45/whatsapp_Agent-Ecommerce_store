<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * The only way to create a platform administrator: `php artisan admin:make
 * {email}`. There is deliberately no in-app way for a user to promote
 * themselves or anyone else — this console command is the sole bootstrap
 * mechanism, and it only ever touches the role column of an existing user.
 */
class MakeAdminCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'admin:make {email : The email address of the existing user to promote}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Promote an existing user to platform administrator';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = $this->argument('email');
        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error("No user found with email [{$email}].");

            return self::FAILURE;
        }

        if ($user->isAdmin()) {
            $this->info("{$user->email} is already an administrator.");

            return self::SUCCESS;
        }

        $user->role = User::ROLE_ADMIN;
        $user->save();

        $this->info("{$user->email} is now a platform administrator.");

        return self::SUCCESS;
    }
}
