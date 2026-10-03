<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Domain\Tenancy\PropertyId;

/** The privileges of finance in one place, and the property check every finance service starts with. */
final readonly class FinanceAccess
{
    public const PAYABLE_VIEW = 'finance.payable.view';

    public const PAYABLE_MANAGE = 'finance.payable.manage';

    public const PAYMENT_RECORD = 'finance.payment.record';

    public const ACCOUNT_MANAGE = 'finance.account.manage';

    public const REVENUE_VIEW = 'finance.revenue.view';

    public const RECONCILE = 'finance.reconcile.manage';

    public const RECEIVABLE_VIEW = 'finance.receivable.view';

    public const RECEIVABLE_MANAGE = 'finance.receivable.manage';

    public const RECEIPT_RECORD = 'finance.receipt.record';

    public const PETTY_VIEW = 'finance.petty.view';

    public const PETTY_OPERATE = 'finance.petty.operate';

    public const PETTY_MANAGE = 'finance.petty.manage';

    public const REPORT_VIEW = 'finance.report.view';

    public const EXPORT = 'finance.export';

    public const RECURRING_VIEW = 'finance.recurring.view';

    public const RECURRING_MANAGE = 'finance.recurring.manage';

    public const BUDGET_MANAGE = 'finance.budget.manage';

    public const CORRECTION_APPROVE = 'finance.correction.approve';

    public const AUDIT_VIEW = 'finance.audit.view';

    private const VIEWERS = [self::PAYABLE_VIEW, self::PAYABLE_MANAGE, self::PAYMENT_RECORD, self::ACCOUNT_MANAGE];

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
            throw Refusal::forbidden('This person may not see accounts payable.');
        }
    }

    public function mayViewRevenue(PropertyId $property, string $actorId): bool
    {
        return $this->may($property, $actorId, self::REVENUE_VIEW) || $this->may($property, $actorId, self::RECONCILE);
    }

    public function requireRevenueView(PropertyId $property, string $actorId): void
    {
        $this->assertProperty($property);

        if (! $this->mayViewRevenue($property, $actorId)) {
            throw Refusal::forbidden('This person may not see the revenue of the property.');
        }
    }

    public function mayViewReceivables(PropertyId $property, string $actorId): bool
    {
        foreach ([self::RECEIVABLE_VIEW, self::RECEIVABLE_MANAGE, self::RECEIPT_RECORD] as $permission) {
            if ($this->may($property, $actorId, $permission)) {
                return true;
            }
        }

        return false;
    }

    public function requireReceivableView(PropertyId $property, string $actorId): void
    {
        $this->assertProperty($property);

        if (! $this->mayViewReceivables($property, $actorId)) {
            throw Refusal::forbidden('This person may not see accounts receivable.');
        }
    }

    public function mayViewPetty(PropertyId $property, string $actorId): bool
    {
        foreach ([self::PETTY_VIEW, self::PETTY_OPERATE, self::PETTY_MANAGE] as $permission) {
            if ($this->may($property, $actorId, $permission)) {
                return true;
            }
        }

        return false;
    }

    public function requirePettyView(PropertyId $property, string $actorId): void
    {
        $this->assertProperty($property);

        if (! $this->mayViewPetty($property, $actorId)) {
            throw Refusal::forbidden('This person may not see petty cash.');
        }
    }

    public function mayViewReports(PropertyId $property, string $actorId): bool
    {
        return $this->may($property, $actorId, self::REPORT_VIEW) || $this->may($property, $actorId, self::ACCOUNT_MANAGE);
    }

    public function requireReportView(PropertyId $property, string $actorId): void
    {
        $this->assertProperty($property);

        if (! $this->mayViewReports($property, $actorId)) {
            throw Refusal::forbidden('This person may not see the management reports.');
        }
    }

    public function requireRecurringView(PropertyId $property, string $actorId): void
    {
        $this->assertProperty($property);

        if (! $this->may($property, $actorId, self::RECURRING_VIEW) && ! $this->may($property, $actorId, self::RECURRING_MANAGE)) {
            throw Refusal::forbidden('This person may not see recurring expenses.');
        }
    }

    public function requireCorrectionView(PropertyId $property, string $actorId): void
    {
        $this->assertProperty($property);

        if (! $this->mayViewRevenue($property, $actorId) && ! $this->may($property, $actorId, self::CORRECTION_APPROVE)) {
            throw Refusal::forbidden('This person may not see corrections and exceptions.');
        }
    }
}
