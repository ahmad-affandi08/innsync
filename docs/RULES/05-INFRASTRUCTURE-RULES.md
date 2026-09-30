# Infrastructure Rules

- Eloquent models are persistence records; map them to/from domain objects where aggregate behavior matters.
- Provider SDKs stay behind ports/adapters.
- File storage, mail, payment, printer, messaging, queue, clock, and UUID/ULID generators are replaceable dependencies when they affect business workflows.
- Retry logic is bounded and observable.
- Infrastructure failures are translated to application-level failure semantics; provider-specific exceptions do not leak to UI.
