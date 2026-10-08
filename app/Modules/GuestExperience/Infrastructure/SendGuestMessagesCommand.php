<?php

declare(strict_types=1);

namespace App\Modules\GuestExperience\Infrastructure;

use App\Modules\GuestExperience\Application\GuestMessageService;
use App\Modules\GuestExperience\Application\OnlineBookingStore;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Context;
use Throwable;

/** Every morning, from the scheduler. Sends the guest messages a hotel switched on; one property failing must not stop the others. */
final class SendGuestMessagesCommand extends Command
{
    protected $signature = 'guestmessages:send';

    protected $description = 'Send the reminder before arrival and the thank-you after the stay, for hotels that switched them on';

    public function handle(GuestMessageService $service, OnlineBookingStore $store, PropertyContext $context): int
    {
        $sent = 0;
        $failed = false;

        foreach ($store->propertiesWithMessages() as $id) {
            try {
                Context::scope(function () use ($service, $context, $id, &$sent): void {
                    $property = PropertyId::fromString($id);
                    $result = $context->run($property, static fn (): array => $service->run($property, (string) config('app.locale')));
                    $sent += $result['pre_arrival'] + $result['thank_you'];
                });
            } catch (Throwable $exception) {
                $failed = true;
                report($exception);
                $this->error("Property {$id}: guest messages failed.");
            }
        }

        $this->line(json_encode(['sent' => $sent, 'failed' => $failed], JSON_THROW_ON_ERROR));

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
