<?php

// Approval subjects (TASK-FND-018, NFR-06, BR-004). A module declares each action that can need maker-checker
// approval. The thresholds and approver chains are configured per property (BR-004), not here.
//
// `mandatory: true` means the action may NEVER proceed without a policy: if none is configured, the request
// fails closed instead of silently skipping approval. Declare an action mandatory when the PRD requires approval
// for it (for example FR-FBS-005 void/cancel, FR-FIN-018 payments above threshold).
//
// The foundation declares no business subjects: which actions need approval, and at what amount, is business
// policy that owners confirm per module task.
return [
    'subjects' => [
        // 'fnb.bill.void' => ['mandatory' => true],
    ],
];
