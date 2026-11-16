<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Observability\Health;

use App\Shared\Application\Notifications\PushNotifier;
use App\Shared\Application\Observability\Health\AlertNotifier;
use App\Shared\Application\Observability\Health\HealthResult;
use App\Shared\Application\Security\StaffDirectory;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * An alert that is raised or gets worse is still logged, and is also put on the devices of the people who may change the property settings, in every property, when web push is set up. The words say what stopped,
 * never data. It cannot tell people the scheduler itself died (nothing then runs this); an uptime check on `/health` is for that, and so is the warning on the kitchen screen.
 */
final readonly class PushAlertNotifier implements AlertNotifier
{
    /** Who is told: the people who keep the system running. */
    public const AUDIENCE_PERMISSION = 'property.settings.manage';

    public function __construct(private LogAlertNotifier $log, private PushNotifier $push, private StaffDirectory $staff, private PropertyContext $context) {}

    public function notify(string $transition, string $key, HealthResult $result): void
    {
        $this->log->notify($transition, $key, $result);

        if ($transition === 'resolved' || ! $this->push->enabled()) {
            return;
        }

        try {
            foreach (DB::table('properties')->orderBy('id')->pluck('id') as $id) {
                $property = PropertyId::fromString((string) $id);
                $this->context->run($property, function () use ($property, $result): void {
                    $ids = array_map(static fn (array $p): string => strtolower($p['id']), $this->staff->withPermission($property, self::AUDIENCE_PERMISSION));

                    if ($ids !== []) {
                        $this->push->notify($property, $ids, 'Peringatan sistem / System alert', $result->summary, '/', 'system');
                    }
                });
            }
        } catch (Throwable $e) {
            // A notice that cannot be sent must not stop the other checks; the log line above already carries the alert.
            Log::warning('Operational alert could not be pushed', ['exception' => $e::class]);
        }
    }
}
