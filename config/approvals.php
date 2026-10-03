<?php

// Approval subjects (TASK-FND-018, NFR-06, BR-004). A module declares each action that can need maker-checker
// approval. The thresholds and approver chains are configured per property (BR-004), not here.
//
// `mandatory: true` means the action may NEVER proceed without a policy: if none is configured, the request
// fails closed instead of silently skipping approval. Declare an action mandatory when the PRD requires approval
// for it (for example FR-FBS-005 void/cancel, FR-FIN-018 payments above threshold).
//
// Which actions need approval, and at what amount, is business policy: owners configure the chain per property.
return [
    'subjects' => [
        // FR-FO-029: a refund, a payment reversal, or any correction of a settled folio needs authorization.
        'front-office.folio.reversal' => ['mandatory' => true],
        'front-office.folio.refund' => ['mandatory' => true],
        // FR-FO-013: a discount on a booked room price above the threshold needs the Manager on Duty. The threshold is the amount band of
        // the property's policy, so with no policy no discount needs approval (the owner decides the threshold).
        'front-office.rate.change' => ['mandatory' => false],
        // FR-FO-039: a correction of a guest's identity after check-in may need approval. The owner decides whether it does, by configuring a policy.
        'front-office.guest.correction' => ['mandatory' => false],
        // FR-PUR-002, FR-PUR-011: a purchase request, a purchase order and a revision of one that moves its value or quantities beyond the tolerance. The owner configures
        // the chain by amount band; with no policy for an amount, the document needs no approval.
        'inventory.purchase-request' => ['mandatory' => false],
        'inventory.purchase-order' => ['mandatory' => false],
        // FR-FIN-018: a payment to a supplier above the threshold the owner sets needs a second person; with no policy for the amount it is paid when recorded.
        'finance.supplier-payment' => ['mandatory' => false],
        // FR-FBS-005: voiding an item that was already sent to the kitchen or the bar, and cancelling a bill that has such items, need a supervisor. Mandatory: with
        // no policy configured the void is refused, never allowed. The owner configures who approves, and from what amount, per property.
        'fnb.item.void' => ['mandatory' => true],
        'fnb.bill.cancel' => ['mandatory' => true],
        // FR-FBS-006: a discount on a line needs approval above the threshold the owner sets (the amount band of the policy; with no policy no discount needs approval). A
        // complimentary item is mandatory: with no policy it is refused, never given.
        'fnb.discount' => ['mandatory' => false],
        'fnb.comp' => ['mandatory' => true],
        // FR-FBS-014: giving a settled bill back needs a supervisor. Mandatory: with no policy configured the refund is refused, never made.
        'fnb.bill.refund' => ['mandatory' => true],
    ],
];
