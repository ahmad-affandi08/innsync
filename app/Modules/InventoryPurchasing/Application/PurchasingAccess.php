<?php

declare(strict_types=1);

namespace App\Modules\InventoryPurchasing\Application;

use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Domain\Tenancy\PropertyId;

/** The privileges of purchasing in one place, and the property check every purchasing service starts with. */
final readonly class PurchasingAccess
{
    public const REQUEST_CREATE = 'purchasing.request.create';

    public const REQUEST_VIEW = 'purchasing.request.view';

    public const ORDER_MANAGE = 'purchasing.order.manage';

    public const ORDER_VIEW = 'purchasing.order.view';

    public const BUDGET_MANAGE = 'purchasing.budget.manage';

    public const RECEIPT_POST = 'purchasing.receipt.post';

    public const INVOICE_MANAGE = 'purchasing.invoice.manage';

    public const INVOICE_RESOLVE = 'purchasing.invoice.resolve';

    public const RETURN_POST = 'purchasing.return.post';

    public const REPORT_VIEW = 'purchasing.report.view';

    public const QUOTE_MANAGE = 'purchasing.quote.manage';

    /** Anyone holding one of these may look at purchasing documents. */
    private const VIEWERS = [
        self::REQUEST_CREATE, self::REQUEST_VIEW, self::ORDER_MANAGE, self::ORDER_VIEW, self::BUDGET_MANAGE, self::RECEIPT_POST, self::INVOICE_MANAGE, self::INVOICE_RESOLVE, self::RETURN_POST, self::REPORT_VIEW, self::QUOTE_MANAGE,
        SupplierService::MANAGE_PERMISSION, SupplierService::VIEW_PERMISSION,
    ];

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

    /** Whether the person may see purchasing documents at all. */
    public function mayView(PropertyId $property, string $actorId): bool
    {
        foreach (self::VIEWERS as $permission) {
            if ($this->may($property, $actorId, $permission)) {
                return true;
            }
        }

        return false;
    }

    public function requireView(PropertyId $property, string $actorId): void
    {
        $this->assertProperty($property);

        if (! $this->mayView($property, $actorId)) {
            throw Refusal::forbidden('This person may not see purchasing documents.');
        }
    }
}
