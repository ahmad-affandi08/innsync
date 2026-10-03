<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Application;

use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Domain\Tenancy\PropertyId;

/** The privileges of the kitchen and the bar, and the property check every kitchen service starts with. */
final readonly class KitchenAccess
{
    /** Works the screen (starts, finishes and serves tickets) and marks items sold out. */
    public const BOARD_OPERATE = 'kitchen.board.operate';

    /** Sets how long a ticket may wait. */
    public const SETTINGS_MANAGE = 'kitchen.settings.manage';

    /** Writes the recipes of the dishes: the composition, the yield and the standard waste. */
    public const RECIPE_MANAGE = 'kitchen.recipe.manage';

    /** Records what was thrown away. */
    public const WASTE_RECORD = 'kitchen.waste.record';

    /** Reports a fault of the equipment to maintenance. */
    public const DAMAGE_REPORT = 'kitchen.damage.report';

    /** Reads the menu report: what sold, what it cost and how each dish does. */
    public const REPORT_VIEW = 'kitchen.report.view';

    /** Records production batches of semi-finished goods. */
    public const PRODUCTION_RECORD = 'kitchen.production.record';

    public function __construct(private PermissionChecker $permissions, private PropertyContext $property) {}

    public function assertProperty(PropertyId $property): void
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }
    }

    public function may(PropertyId $property, string $actorId, string $permission): bool
    {
        return $this->permissions->allowsInProperty($actorId, $permission, $property);
    }

    public function require(PropertyId $property, string $actorId, string $permission, string $message): void
    {
        $this->assertProperty($property);

        if (! $this->may($property, $actorId, $permission)) {
            throw Refusal::forbidden($message);
        }
    }
}
