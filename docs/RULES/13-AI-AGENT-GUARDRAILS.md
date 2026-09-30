# AI Agent Guardrails

Before coding, the agent must produce internally/explicitly:

- requirement IDs being implemented;
- bounded context and aggregate owner;
- files/layers expected to change;
- security/property-scope impact;
- transaction/idempotency/concurrency impact;
- test plan;
- unresolved PRD questions.

The agent MUST NOT "improve" product behavior beyond the requirement, invent hotel policy, add a package to save time, or bypass an invariant because existing code is inconvenient.

If existing code and docs disagree, prefer docs. If changing docs is justified, draft a Change Request first. If a task touches money, room availability, stock, payroll/service charge, payment, guest identity, or permissions, perform a second-pass invariant/security review before declaring DONE.
