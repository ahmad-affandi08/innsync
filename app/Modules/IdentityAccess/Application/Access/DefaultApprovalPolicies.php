<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application\Access;

/**
 * The approver of each action that cannot go ahead without approval (`mandatory` in config/approvals.php). With no policy those actions are refused,
 * so a new property would meet "not allowed" until someone configured them (owner instruction 2026-10-07: less set-up at the start).
 *
 * Each action gets one level, one approval, from any person holding the permission named here (the maker never approves their own request, BR-004). The permissions
 * are the managers' own (folio correction and refund, F&B refund, leave, payroll verification), which the starting roles give to the managers only. The amount band is
 * 0: the same approver from any amount. The owner changes any of it on the Approval policies screen; a property that already has a policy for an action keeps it.
 */
final class DefaultApprovalPolicies
{
    /** @return array<string, string> approval subject => the permission of the person who approves */
    public static function all(): array
    {
        return [
            'front-office.folio.reversal' => 'front-office.folio.correct',
            'front-office.folio.refund' => 'front-office.folio.refund',
            'front-office.laundry-exception' => 'front-office.folio.correct',
            'fnb.item.void' => 'fnb.refund.apply',
            'fnb.bill.cancel' => 'fnb.refund.apply',
            'fnb.comp' => 'fnb.refund.apply',
            'fnb.bill.refund' => 'fnb.refund.apply',
            'hr.attendance-correction' => 'hr.attendance.manage',
            'hr.leave' => 'hr.leave.manage',
            'hr.payroll-run' => 'finance.payroll.verify',
            'hr.service-charge' => 'hr.service-charge.manage',
        ];
    }
}
