<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Infrastructure;

use App\Modules\Maintenance\Application\PreventiveService;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Every hour, from the scheduler. Makes the work orders that the preventive plans call for (FR-MTC-008, -014); one property failing must not stop the others. */
final class GenerateRoutineWorkCommand extends Command
{
    protected $signature = 'maintenance:preventive';

    protected $description = 'Make the work orders that preventive plans call for';

    public function handle(PreventiveService $preventive, PropertyContext $context): int
    {
        $made = 0;
        $failed = false;

        foreach (DB::table('maintenance_pm_plans')->where('is_active', true)->distinct()->pluck('property_id') as $id) {
            try {
                Context::scope(function () use ($preventive, $context, $id, &$made): void {
                    $property = PropertyId::fromString((string) $id);
                    $made += $context->run($property, static fn (): int => $preventive->generate($property));
                });
            } catch (Throwable $exception) {
                $failed = true;
                report($exception);
                $this->error("Property {$id}: preventive work failed.");
            }
        }

        $this->line(json_encode(['made' => $made, 'failed' => $failed], JSON_THROW_ON_ERROR));

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
