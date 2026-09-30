# Identity, RBAC, Scope, and Approval

Permissions are feature/action oriented, not only module-level. Every privileged action is evaluated against:

`user + role/permission + property scope + optional outlet/department scope + action context`.

## Requirements

- Least privilege by default.
- Managerial/sensitive roles support MFA as required by NFR.
- Maker-checker approval for configured sensitive actions; the maker cannot be the sole approver when dual control is required.
- Approval records include request, actor, approver, decision, reason, timestamps, and before/after evidence.
- Revoked/offboarded users lose active sessions promptly.
- Never trust UI visibility as authorization; policy/guard checks live server-side.
- Public guest links are capability/session scoped and must never expose guessable internal IDs.
