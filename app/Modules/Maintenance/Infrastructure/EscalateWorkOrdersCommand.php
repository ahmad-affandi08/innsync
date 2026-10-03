<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Infrastructure;

use App\Modules\Maintenance\Application\EscalationService;
use App\Modules\Maintenance\Application\WorkOrderStore;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Context;
use Throwable;

/** Every few minutes, from the scheduler. Escalates the work orders that near or pass their deadline (FR-MTC-013); one property failing must not stop the others. */
final class EscalateWorkOrdersCommand extends Command
{
    protected $signature = 'maintenance:escalate';

    protected $description = 'Escalate the work orders that are near or past their deadline';

    public function handle(EscalationService $escalations, WorkOrderStore $store, PropertyContext $context): int
    {
        $raised = 0;
        $failed = false;

        foreach ($store->propertiesWithOpenWork() as $id) {
            try {
                Context::scope(function () use ($escalations, $context, $id, &$raised): void {
                    $property = PropertyId::fromString($id);
                    $raised += $context->run($property, static fn (): int => $escalations->run($property));
                });
            } catch (Throwable $exception) {
                $failed = true;
                report($exception);
                $this->error("Property {$id}: escalation failed.");
            }
        }

        $this->line(json_encode(['raised' => $raised, 'failed' => $failed], JSON_THROW_ON_ERROR));

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
