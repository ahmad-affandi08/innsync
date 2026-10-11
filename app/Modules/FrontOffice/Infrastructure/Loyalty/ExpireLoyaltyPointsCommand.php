<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Infrastructure\Loyalty;

use App\Modules\FrontOffice\Application\Loyalty\LoyaltyService;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Context;
use Throwable;

/** Every night, from the scheduler: takes the points of accounts that have been idle for the months the programme says. One property failing must not stop the others. */
final class ExpireLoyaltyPointsCommand extends Command
{
    protected $signature = 'loyalty:expire';

    protected $description = 'Expire the loyalty points of accounts with no stay and no voucher for a long time';

    public function handle(LoyaltyService $service, PropertyContext $context): int
    {
        $expired = 0;
        $failed = false;

        if (! LoyaltyService::rules()['enabled']) {
            $this->line(json_encode(['expired' => 0, 'failed' => false], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        foreach ($service->propertiesWithAccounts() as $id) {
            try {
                Context::scope(function () use ($service, $context, $id, &$expired): void {
                    $property = PropertyId::fromString($id);
                    $expired += $context->run($property, static fn (): int => $service->expireIdle($property));
                });
            } catch (Throwable $exception) {
                $failed = true;
                report($exception);
                $this->error("Property {$id}: loyalty expiry failed.");
            }
        }

        $this->line(json_encode(['expired' => $expired, 'failed' => $failed], JSON_THROW_ON_ERROR));

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
