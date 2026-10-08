<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Deployment;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** For the owner at the server when the only administrator is locked out: opens the account without changing its password. */
final class UnlockAccountCommand extends Command
{
    protected $signature = 'innsync:unlock-account {email : Email address of the account}';

    protected $description = 'Clear the failed sign-in count and the temporary lock of an account';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $changed = DB::table('users')->where('email', $email)->update(['failed_login_attempts' => 0, 'locked_until' => null, 'updated_at' => now()]);

        if ($changed === 0) {
            $this->error('No account has this email address.');

            return self::FAILURE;
        }

        $this->info('The account is open. Its password was not changed.');

        return self::SUCCESS;
    }
}
