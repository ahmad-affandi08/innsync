<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Infrastructure\Reminders;

use App\Modules\FrontOffice\Application\Reminders\AutoReminderService;
use App\Modules\FrontOffice\Application\Reminders\AutoReminderSource;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Context;
use Throwable;

/** Every morning, from the scheduler. Makes the front desk reminders the records call for; one property failing must not stop the others. */
final class MakeAutoRemindersCommand extends Command
{
    protected $signature = 'frontdesk:auto-reminders';

    protected $description = 'Make the front desk reminders that tentative bookings and lapsing holds call for';

    public function handle(AutoReminderService $service, AutoReminderSource $source, PropertyContext $context): int
    {
        if (! (bool) config('frontdesk.auto_reminders.enabled')) {
            $this->line('{"made":0,"disabled":true}');

            return self::SUCCESS;
        }

        $made = 0;
        $failed = false;

        foreach ($source->propertyIds() as $id) {
            try {
                Context::scope(function () use ($service, $context, $id, &$made): void {
                    $property = PropertyId::fromString($id);
                    $made += $context->run($property, static fn (): int => $service->run($property, (int) config('frontdesk.auto_reminders.days_ahead')));
                });
            } catch (Throwable $exception) {
                $failed = true;
                report($exception);
                $this->error("Property {$id}: reminders failed.");
            }
        }

        $this->line(json_encode(['made' => $made, 'failed' => $failed], JSON_THROW_ON_ERROR));

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
