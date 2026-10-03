<?php

declare(strict_types=1);

namespace App\Modules\FnbSales\Application;

use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Domain\Tenancy\PropertyId;

/** The privileges of the F&B outlets in one place, and the property check every F&B service starts with. */
final readonly class FnbAccess
{
    /** Sets up outlets, tables, the menu and its modifiers. */
    public const SETUP_MANAGE = 'fnb.setup.manage';

    /** Sees the menu and the tables; held by everybody who takes orders. */
    public const POS_OPERATE = 'fnb.pos.operate';

    /** Opens and closes a cashier shift and takes payments for bills. */
    public const CASHIER_OPERATE = 'fnb.cashier.operate';

    /** Gives a discount or a complimentary item on a line of a bill. */
    public const DISCOUNT_APPLY = 'fnb.discount.apply';

    /** Gives a settled bill back to the guest. */
    public const REFUND_APPLY = 'fnb.refund.apply';

    /** Prints another copy of the receipt of a bill that was settled. */
    public const RECEIPT_REPRINT = 'fnb.receipt.reprint';

    /** Checks the mini bars of the rooms, charges what was consumed to the guest and sees the refill list and the history. */
    public const MINIBAR_OPERATE = 'fnb.minibar.operate';

    /** Sets the items of the mini bar, their prices and how many a room holds. */
    public const MINIBAR_MANAGE = 'fnb.minibar.manage';

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

    /** Whoever sets up or operates may see the setup. */
    public function requireView(PropertyId $property, string $actorId): void
    {
        $this->assertProperty($property);

        if (! $this->may($property, $actorId, self::SETUP_MANAGE) && ! $this->may($property, $actorId, self::POS_OPERATE)) {
            throw Refusal::forbidden('This person may not see the outlets and the menu.');
        }
    }
}
