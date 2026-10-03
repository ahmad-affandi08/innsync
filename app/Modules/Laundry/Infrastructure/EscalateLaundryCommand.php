<?php

declare(strict_types=1);

namespace App\Modules\Laundry\Infrastructure;

use App\Modules\Laundry\Application\LaundryEscalationService;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Throwable;

/** From the scheduler, every ten minutes: tells the laundry staff of an order past its promised time, once (FR-LDY-011). One property failing must not stop the others. */
final class EscalateLaundryCommand extends Command
{
    protected $signature = 'laundry:escalate {--max=50 : Most orders escalated for a property in one run}';

    protected $description = 'Notify the laundry staff of orders past their promised time';

    public function handle(LaundryEscalationService $escalations, PropertyContext $context): int
    {
        $max = max(1, (int) $this->option('max'));
        $escalated = 0;
        $failed = false;

        foreach (DB::table('laundry_orders')->whereIn('status', ['sent', 'received', 'washing', 'drying', 'ironing'])->where('promised_at', '<', now()->utc())->whereNull('escalated_at')->distinct()->pluck('property_id') as $id) {
            try {
                Context::scope(function () use ($escalations, $context, $id, $max, &$escalated): void {
                    $property = PropertyId::fromString((string) $id);
                    $escalated += $context->run($property, static fn (): int => $escalations->run($property, $max));
                });
            } catch (Throwable $exception) {
                $failed = true;
                report($exception);
                $this->error("Property {$id}: laundry escalation failed.");
            }
        }

        $this->line(json_encode(['escalated' => $escalated, 'failed' => $failed], JSON_THROW_ON_ERROR));

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
