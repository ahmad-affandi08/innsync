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
    ],
];
