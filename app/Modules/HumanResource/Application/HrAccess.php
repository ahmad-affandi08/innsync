<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Application;

use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Domain\Tenancy\PropertyId;

/** The privileges of human resource, and the property check every service of it starts with. */
final readonly class HrAccess
{
    /** Sees the employees and where they work. */
    public const VIEW = 'hr.employee.view';

    /** Writes the employee records, offboards people and sets the warning period and the papers every employee must have. */
    public const MANAGE = 'hr.employee.manage';

    /** Sees, adds and downloads the personnel papers: contracts, identity, certificates and medical checks. Sensitive: held by few. */
    public const DOCUMENTS = 'hr.document.manage';

    /** Configures the shifts, plans the roster and sets how many people each department needs on a shift. */
    public const ROSTER = 'hr.roster.manage';

    /** Sees the attendance of everyone, records a clock-in or clock-out for a person with a reason, and sets how attendance is taken. */
    public const ATTENDANCE = 'hr.attendance.manage';

    /** Sees how people are doing: the performance board and the share of their routines they did. */
    public const PERFORMANCE = 'hr.performance.view';

    /** Configures the kinds of leave, asks for leave for other people, sees everyone's requests and balances, and adjusts a balance. */
    public const LEAVE = 'hr.leave.manage';

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
