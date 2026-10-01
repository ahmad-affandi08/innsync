<?php

/*
 * Messages of the standard error envelope (TASK-FND-009). The client branches
 * on `code`/`conflict.*`; these texts are only the human explanation and are
 * resolved in the request locale (TASK-FND-013). Keep them free of record
 * identifiers and internals.
 */
return [
    'validation_failed' => 'The submitted data is invalid.',
    'conflict_optimistic_lock' => 'This record was changed by someone else. Refresh and review before retrying.',
    'conflict_idempotency_mismatch' => 'This request key was already used for a different request.',
    'conflict_idempotency_in_progress' => 'The original request is still being processed. Retry shortly.',
    'conflict_unspecified' => 'The request conflicts with the current state.',
    'file_rejected' => 'The file was rejected.',
    'not_found' => 'The resource was not found.',
    'forbidden' => 'You are not allowed to perform this action.',
    'property_context_required' => 'Select a property to continue.',
    'unauthenticated' => 'Authentication is required.',
    'session_expired' => 'The session expired. Reload and try again.',
    'bad_request' => 'The request is malformed.',
    'method_not_allowed' => 'This method is not allowed.',
    'payload_too_large' => 'The request is too large.',
    'unprocessable' => 'The request could not be processed.',
    'too_many_requests' => 'Too many requests. Retry later.',
    'unavailable' => 'The service is temporarily unavailable.',
    'server_error' => 'An unexpected error occurred.',
    'request_failed' => 'The request failed.',
];
