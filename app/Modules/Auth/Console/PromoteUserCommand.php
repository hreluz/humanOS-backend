<?php

namespace App\Modules\Auth\Console;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use Illuminate\Console\Command;

class PromoteUserCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'users:promote
                            {email : The email of the user}
                            {--demote : Set the role back to user}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Give a user the admin role, or remove it with --demote';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $user = User::firstWhere('email', $this->argument('email'));

        if (! $user) {
            $this->error("No user found with email [{$this->argument('email')}].");

            return self::FAILURE;
        }

        $role = $this->option('demote') ? UserRole::User : UserRole::Admin;

        $user->forceFill(['role' => $role])->save();

        $this->info("{$user->email} is now {$role->value}.");

        return self::SUCCESS;
    }
}
