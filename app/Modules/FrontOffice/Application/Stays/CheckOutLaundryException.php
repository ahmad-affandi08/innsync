<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Stays;

use App\Modules\FrontOffice\Application\Folios\ApprovalRequired;
use App\Modules\Laundry\Application\LaundryLiability;
use App\Shared\Application\Approval\ApprovalGate;
use App\Shared\Application\Approval\ApprovalRequestInput;
use App\Shared\Application\Approval\ApprovalView;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Application\Time\Clock;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * Closing a stay while the guest's laundry is still in the laundry's hands (FR-LDY-012). The stay cannot be closed, unless the person who checks the guest out turns
 * that laundry into a late charge or into a claim, and a second person approves it: the request names the stay, the way out, the reason and exactly the orders
 * concerned, so an approval cannot be used for other laundry. The approval is mandatory (BR-004): with no policy configured the exception is refused, never allowed.
 *
 * A late charge lets each order go on and be charged to the late folio of the stay when it is ready (FR-FO-038); a claim takes each order out of the laundry's work to
 * the claim procedure (FR-LDY-006), and nothing is charged. What was agreed is kept as an immutable record of the stay.
 */
final readonly class CheckOutLaundryException
{
    public const SUBJECT = 'front-office.laundry-exception';

    public const MODES = ['late_charge', 'claim'];

    public function __construct(
        private ApprovalGate $approvals,
        private LaundryLiability $laundry,
        private LaundryExceptionStore $store,
        private PermissionChecker $permissions,
        private AuditTrail $audit,
        private IdentifierGenerator $ids,
        private Clock $clock,
        private PropertyContext $property,
    ) {}

    /**
     * What the screen of a stay needs: the orders in hand, what was agreed if the guest has left, and the guest's own requests for approval.
     *
     * @return array{orders: int, exception: array<string, mixed>|null, approvals: list<array<string, mixed>>}
     */
    public function overview(PropertyId $property, string $actorId, string $stayId): array
    {
        $this->assertProperty($property);
        $stayId = strtolower($stayId);
        $approvals = [];

        foreach ($this->approvals->requestedBy($property, strtolower($actorId), 200) as $view) {
            if ($view->subjectType === self::SUBJECT && $view->subjectRef === $stayId) {
                $approvals[] = ['id' => $view->id, 'status' => $view->status, 'consumed' => $view->consumed, 'mode' => $view->payload['mode'] ?? null, 'reason' => $view->payload['reason'] ?? null];
            }
        }

        $exception = $this->store->ofStay($property, $stayId);

        return [
            'orders' => $this->laundry->activeOrdersOfStay($property, $stayId),
            'exception' => $exception === null ? null : ['mode' => $exception['mode'], 'reason' => $exception['reason'], 'orders' => count($exception['order_ids']), 'at' => $exception['created_at']],
            'approvals' => $approvals,
        ];
    }

    /** Opens the approval of turning the laundry in hand into a late charge or a claim, bound to exactly these orders. */
    public function requestApproval(PropertyId $property, string $actorId, string $stayId, string $mode, string $reason, IdempotencyKey $key): ApprovalView
    {
        $this->assertProperty($property);
        $this->authorize($property, $actorId);
        [$mode, $reason] = $this->clean($mode, $reason);
        $stayId = strtolower($stayId);
        $orders = $this->laundry->activeOrderIdsOfStay($property, $stayId);

        if ($orders === []) {
            throw Refusal::stateConflict('This guest has no laundry in hand, so there is nothing to approve.');
        }

        $this->approvals->requirementFor($property, self::SUBJECT);

        return $this->approvals->request(new ApprovalRequestInput(
            $property, self::SUBJECT, $stayId, strtolower($actorId), $reason, $this->payload($stayId, $mode, $reason, $orders), ['orders' => count($orders)],
        ), $key);
    }

    /**
     * Settles the laundry in hand as the approval says, as part of the check-out; the caller runs it in the check-out's transaction, so that a check-out that fails
     * leaves the approval unused and the orders as they were.
     *
     * @param  array{mode: string, reason: string, approval_id: ?string}  $input
     * @return int how many orders were settled
     */
    public function apply(PropertyId $property, string $actorId, string $stayId, string $reservationId, array $input): int
    {
        $this->assertProperty($property);
        [$mode, $reason] = $this->clean($input['mode'], $input['reason']);
        $actor = strtolower($actorId);
        $stayId = strtolower($stayId);
        $orders = $this->laundry->activeOrderIdsOfStay($property, $stayId);

        if ($orders === []) {
            return 0;
        }

        // Mandatory: with no policy this fails closed (BR-004).
        $this->approvals->requirementFor($property, self::SUBJECT);
        $approvalId = $input['approval_id'] ?? null;

        if ($approvalId === null || $approvalId === '') {
            throw new ApprovalRequired;
        }

        $approvalId = strtolower($approvalId);
        $this->approvals->consume($property, $approvalId, self::SUBJECT, $stayId, $this->payload($stayId, $mode, $reason, $orders), $actor);
        $now = $this->clock->nowUtc();

        if (! $this->store->add($property, $this->ids->next(), $stayId, strtolower($reservationId), $mode, $reason, $approvalId, $orders, $actor, $now)) {
            throw Refusal::stateConflict('This stay has its laundry settled already.');
        }

        $settled = $this->laundry->settleAfterCheckOut($property, $actor, $stayId, $mode, $approvalId, $reason);
        $this->audit->record(new AuditEntry(
            $property->toString(), $actor, 'stay.laundry_exception', 'stay', $stayId, null,
            ['mode' => $mode, 'orders' => $settled, 'approval_id' => $approvalId], $reason,
        ));

        return $settled;
    }

    /** @return array{0: string, 1: string} */
    private function clean(string $mode, string $reason): array
    {
        $reason = trim($reason);

        if (! in_array($mode, self::MODES, true)) {
            throw Refusal::invalid('Choose a late charge or a claim.', ['mode']);
        }

        if ($reason === '' || mb_strlen($reason) > 300) {
            throw Refusal::invalid('A reason of at most 300 characters is required.', ['reason']);
        }

        return [$mode, $reason];
    }

    /**
     * @param  list<string>  $orders
     * @return array<string, mixed>
     */
    private function payload(string $stayId, string $mode, string $reason, array $orders): array
    {
        sort($orders);

        return ['stay_id' => $stayId, 'mode' => $mode, 'reason' => $reason, 'order_ids' => $orders];
    }

    private function authorize(PropertyId $property, string $actorId): void
    {
        if (! $this->permissions->allowsInProperty($actorId, StayService::MANAGE_PERMISSION, $property)) {
            throw Refusal::forbidden('This person may not check guests out.');
        }
    }

    private function assertProperty(PropertyId $property): void
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }
    }
}
