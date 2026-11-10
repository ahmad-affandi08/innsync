# CR-0002 — Create a staff account from an HR employee record

- CR ID: CR-0002
- Requested by: Ahmad Affandi (product owner), from the 2026-11-10 module scan
- Date: 2026-11-10
- Problem / reason: An employee is entered in HR, then someone creates a user in Access and picks the role by hand, then links the two (`hr/employees.tsx` picks `user_id` from a dropdown). `AccessAdmin::createAccounts` does not read HR data. The same person is typed twice and the role depends on who remembers the right one.
- Current PRD/RULE/ADR affected: RULES `10-SECURITY-RULES.md` and "weaken authorization" stop condition in `AGENTS.md`; PRD NFR-06 (access control to feature and scope); BR-009 (minimum necessary PII). `docs/OPERATIONS/ACCESS-ADMINISTRATION.md` describes today's flow.
- Proposed behavior:
  1. A per-property **position → role** map, edited by someone holding the access-administration permission. No default map ships with a broader role than the position needs.
  2. On an employee record, a button **Create account** (never on save, never automatic). It creates the user from the employee's name and work e-mail, assigns the mapped role at the employee's department scope, links `user_id`, and sends the normal set-password invitation. The action needs the same permission and password confirmation as creating an account today.
  3. An employee whose position has no mapping gets no account and is told why.
  4. Leaving the company (employment end) is **not** part of this CR: deactivating the account stays a separate, manual step until the owner decides otherwise.
- FR/NFR impacted: NFR-06, BR-009; the identity requirements of the PRD (Access) and FR-HR employee record. Task ids to be read from `docs/TASK/MASTER-BACKLOG.md` before the work starts.
- Data migration / historical impact: new map table; existing users and links untouched.
- Security/privacy impact: this is permission-granting behavior, so it needs a second-pass security review (`13-AI-AGENT-GUARDRAILS.md`). Only roles the actor already may grant can be mapped (no privilege escalation by editing the map). Every creation is audited with the actor, the employee and the role. Work e-mail only; no national ID or other HR data is copied into the account.
- Finance/stock/payment impact: none.
- Hosting/infrastructure impact: none (uses the existing mail path).
- Backward compatibility: the manual flow stays; the button appears only when a map entry exists.
- Test/UAT plan: an actor without the permission is refused; a position mapped to a role the actor cannot grant is refused; duplicate e-mail is refused; property scope enforced; audit row written; invitation sent once on retry (idempotent).
- Rollback plan: remove the map entries; accounts already made are ordinary accounts and are managed in Access.
- Target phase/release: Tahap 6 of `docs/OPERATIONS/AUTOMATION-PLAN.md`.
- Approvers: Owner / GM, IT (security reviewer).
- Decision: Pending
