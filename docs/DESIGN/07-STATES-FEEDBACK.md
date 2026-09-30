# States and Feedback

Standard states: loading, skeleton, empty, filtered-empty, stale, offline, syncing, sync-failed, conflict, success, warning, provider-unknown, forbidden, session-expired, server-error.

Payment `Unknown` has a dedicated visual state and cannot be styled as success. Offline pending transactions are visibly pending until acknowledged by server. Approved/Rejected/Pending approvals are explicit.
