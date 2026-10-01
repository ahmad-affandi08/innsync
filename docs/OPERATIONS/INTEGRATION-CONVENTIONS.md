# Integration Conventions

How InnSYnc talks to the outside world (`TASK-FND-020`, `NFR-25`, `NFR-28`, `NFR-18`, `NFR-23`; `docs/ARCHITECTURE/11-INTEGRATIONS.md`). No provider is chosen yet (payment gateway `Q-04`/`Q-16`, accounting `Q-07`, door locks `Q-08`); these rules apply to every adapter written later, so none of them needs to be redesigned per provider.

## Outgoing calls

1. **Port first.** The owning module defines an application port (for example `PaymentGateway`) in its own words. The adapter implements it in Infrastructure. Provider DTOs, error codes and SDK exceptions never leave the adapter.
2. **Always through `ExternalCallExecutor`.** It makes exactly one attempt and gives the adapter a `CallContext`: provider, operation, the **idempotency key** (generated once per business intent and sent to the provider so a repeat cannot act twice), the correlation ID, and bounded connect and read timeouts (defaults 3 and 10 seconds, at most 60; the shared-hosting request limit is the ceiling).
3. **Never inside a database transaction, and from a queued job.** The business change and an outbox event commit together (`NFR-17`); an outbox consumer job calls the provider. A web request never waits on a provider.
4. **Honest outcomes.** The adapter returns `ProviderCallResult`:
   - `succeeded`, with only the fields the module needs (never card data or secrets; the shared guard refuses them);
   - `rejected(code)`: the provider answered and refused. Definite; not retried; does not trip the circuit;
   - `retryable(code)`: nothing was applied (cannot connect, provider busy, circuit open);
   - `unknown(code)`: the request may have reached the provider but no answer came.
   An adapter throws `ProviderUnreachable` when nothing could be sent and `ProviderTimedOut` when it was sent and nothing came back; the executor turns these into the outcomes above. Any other exception is a defect: it is reported and treated as `unknown`, never as a clean failure.
5. **Retries are the caller's job and bounded.** The outbox job already retries with exponential backoff and moves a message to the dead-letter list after the configured attempts (`ADR-0007`, `retry-dead-letter` command). Only `retryable` is retried automatically. `unknown` is never retried blindly.
6. **Circuit breaker.** After `failure_threshold` consecutive `retryable` or `unknown` results (default 5) the circuit for that provider and property opens for `open_seconds` (default 60) and calls return `retryable('circuit_open')` without touching the provider. After the period exactly one trial goes out; success closes the circuit, failure reopens it. The state is in the database so every request sees the same circuit. An open circuit makes the `integrations` health check `degraded`.
7. **Unknown outcomes are reconciled by a person.** Each `unknown` is stored once per (provider, idempotency key) and listed for someone with `integration.reconcile`, who checks what the provider actually did (its dashboard or statement) and records applied or not applied **with the evidence used**. The module then posts the correction (a reversal or a repeat with a new key); nothing here edits a business fact. An outcome open for more than 4 hours (`INTEGRATION_UNKNOWN_DOWN_HOURS`) turns the health check `down`, which raises the alert for "payment unknown" (`NFR-20`). Until it is reconciled the UI must show the state as unknown, never as paid or failed.
8. **Provider state is evidence, not authority.** A payment, refund or channel callback never skips the module's own state machine, approval or posting rules.

## Incoming webhooks

- One endpoint: `POST /api/webhooks/{provider}` (stateless: no session, cookie or CSRF; rate limited per provider and address). Only configured providers exist; everything else, and every verification failure, gets the same `401` so a sender learns nothing.
- Authenticity is a signature over the **raw body**. Default scheme (and the one InnSYnc's own outgoing webhooks use): `X-InnSYnc-Signature: t=<unix seconds>,v1=<hex HMAC-SHA256 of "<t>.<body>">` plus `X-InnSYnc-Event-Id`. The timestamp is signed and must be within 5 minutes, so a captured request cannot be replayed later. A provider with its own scheme supplies a `WebhookProtocol` class in `config/integrations.php`.
- **Secret rotation without downtime:** list the new secret first and keep the previous one until the provider has switched (`NFR-23`). Secrets live only in the environment.
- A verified delivery is stored once per (provider, event id), encrypted with the application key and append-only, then handed to the module by an outbox event that carries only the receipt reference (`integration.webhook.received`). The endpoint answers `202` (`accepted` or `duplicate`) and does no business work. The module's consumer reads the body, applies its own rules idempotently, and may query the provider to confirm.
- Bodies over 256 KB get `413`. Refusals for a known provider are security events (`integration.webhook.refused`) with a reason code and no secret or body.
- Stored callbacks can be payment evidence, so they are retained like `financial_record` (10 years) and are not purged by `retention:purge`.

## Contract versioning and compatibility (`NFR-28`) — baseline policy

This is a baseline set by the engineering team under the owner's instruction to follow standard practice; the owner confirms it before the first partner integration.

- **Version in the path** for any API offered to partners: `/api/v1/...`. A new major version is added beside the old one, never in place of it.
- **Backward compatible changes** (allowed inside a version): new endpoints, new optional request fields, new response fields, new enum values only where the contract told clients to expect unknown values. **Breaking changes** (need a new major version): removing or renaming a field, changing a type, meaning or unit (money stays integer minor units plus ISO currency, `ADR-0006`), making an optional field required, tightening validation, or changing error codes.
- **Clients must ignore unknown fields.** The contract says so, and tests for incoming data accept extra fields.
- **Deprecation:** announce a breaking change at least 6 months ahead, mark deprecated endpoints with `Deprecation` and `Sunset` response headers, keep the old version working until the sunset date, and record the dates in `CHANGELOG.md`.
- **Event and webhook payloads carry a `payload_version`** (outbox events already do; offline operations carry one too); consumers must handle the previous version for one release.
- **Idempotency:** every mutating endpoint offered to a partner accepts `Idempotency-Key` (`NFR-18`) and the contract states how long keys are remembered.
- **Errors:** the standard error envelope (`TASK-FND-009`) is part of the contract.

## Browser and device support matrix (`NFR-13`, `NFR-28`) — baseline

The PRD asks for current browsers on Android and iOS; the minimum devices and offline duration stay open (`Q-14`) until the property provides them.

| Platform | Minimum | Why |
| --- | --- | --- |
| iOS / iPadOS Safari | 16.4 | `<dialog>`, IndexedDB with WebCrypto AES-GCM, `crypto.randomUUID` (used by the offline queue and dialogs) |
| Android Chrome | current and previous major version | evergreen |
| Desktop Chrome and Edge | current and previous major version | evergreen |
| Desktop Firefox | current and previous major version | evergreen |
| Desktop Safari | 16.4 | same as iOS |

Not supported: Internet Explorer, browsers that block IndexedDB (the app says so on `/offline-check`), and any browser older than the minimums. The production build targets Vite's default "baseline widely available" set.

**What is tested:** every release is exercised in Chromium (Playwright) in development. Safari, Firefox and real Android and iOS devices have **not** been tested; that evidence comes from the field test page `/offline-check` on the property's actual devices and must be recorded in `docs/TASK/TESTING-UAT.md` before go-live. Until then this matrix is a statement of intent, not a verified result.

## Adding a provider (checklist)

1. Decide the provider with Finance/IT; record the decision and open PRD questions.
2. Define the module port; write the adapter behind it; translate transport errors to `ProviderUnreachable` / `ProviderTimedOut`.
3. Add the provider to `config/integrations.php` (property, timeouts, secrets from the environment, protocol if not the default).
4. Call it only through `ExternalCallExecutor` from an outbox consumer job.
5. Test: success, rejection, unreachable, timeout (unknown), circuit open, duplicate callback, bad signature, and the reconciliation screen's wording.
6. Add the sandbox/production credentials to the host `.env`; never to Git.
