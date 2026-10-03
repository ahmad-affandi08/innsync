<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Infrastructure;

use App\Modules\Maintenance\Application\DutyRunService;
use App\Modules\Maintenance\Application\DutyStore;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Context;
use Throwable;

/** Every hour, from the scheduler. Makes the runs of the routine duties that fall due and marks the ones not done in time as missed (FR-MTC-011); one property failing must not stop the others. */
final class GenerateDutyRunsCommand extends Command
{
    protected $signature = 'maintenance:duties';

    protected $description = 'Make the runs of the routine duties that fall due';

    public function handle(DutyRunService $runs, DutyStore $duties, PropertyContext $context): int
    {
        $made = 0;
        $failed = false;

        foreach ($duties->propertiesWithDuties() as $id) {
            try {
                Context::scope(function () use ($runs, $context, $id, &$made): void {
                    $property = PropertyId::fromString($id);
                    $made += $context->run($property, static fn (): int => $runs->generate($property));
                });
            } catch (Throwable $exception) {
                $failed = true;
                report($exception);
                $this->error("Property {$id}: duty runs failed.");
            }
        }

        $this->line(json_encode(['made' => $made, 'failed' => $failed], JSON_THROW_ON_ERROR));

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
