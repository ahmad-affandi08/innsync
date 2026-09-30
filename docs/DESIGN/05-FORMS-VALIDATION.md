# Forms and Validation UX

- Labels remain visible; placeholder is not a label.
- Mark required fields explicitly where ambiguity exists.
- Show server validation next to the field and preserve non-sensitive user input.
- Domain conflicts (room no longer available, folio changed, stock version changed) use a conflict message with refresh/retry/review action, not generic "Something went wrong".
- Money fields show currency and server-returned calculated totals.
- Approval/void/refund/price override forms require reason when configured by PRD.
- Disable duplicate submission visually, but backend idempotency remains mandatory.
