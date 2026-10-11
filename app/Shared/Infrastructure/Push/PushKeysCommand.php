<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Push;

use Illuminate\Console\Command;

final class PushKeysCommand extends Command
{
    protected $signature = 'innsync:push-keys';

    protected $description = 'Make the key pair for notices on devices (web push) and print the lines for the environment';

    public function handle(): int
    {
        $keys = Vapid::generate();
        $this->line('Put these in the environment (.env) of the server, keep the private key secret, and keep the pair: devices that allowed notices stop receiving them if the key is changed.');
        $this->newLine();
        $this->line('VAPID_PRIVATE_KEY='.$keys['private']);
        $this->line('VAPID_SUBJECT=mailto:you@your-hotel.example');
        $this->newLine();
        $this->line('Public key (for information; the application derives it): '.$keys['public']);

        return self::SUCCESS;
    }
}
