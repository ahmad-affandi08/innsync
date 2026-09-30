# AGENTS.md — InnSYnc Non-Negotiable Agent Contract

This repository is governed by the InnSYnc documentation set in `docs/`. Every AI agent, human contributor, code generator, refactoring tool, or reviewer MUST treat these documents as executable constraints, not suggestions.

## Mandatory read order before making any code change

1. `docs/RULES/00-NON-NEGOTIABLES.md`
2. `docs/RULES/01-SOURCE-OF-TRUTH.md`
3. `docs/PRD/PRD.md` — read the section and FR/NFR IDs touched by the task.
4. `docs/ARCHITECTURE/README.md` plus the relevant architecture/ADR files.
5. `docs/DESIGN/README.md` plus relevant UI rules for frontend work.
6. `docs/TASK/README.md` and the exact task/module file being implemented.
7. Relevant `docs/SKILL/*.md` operating procedure.

## Stop conditions

STOP and ask for a decision or create a Change Request when any requested implementation would:
- contradict a PRD functional requirement, cross-module rule, state invariant, NFR, release gate, or signed scope;
- bypass the dependency direction defined by Clean Architecture / DDD;
- introduce a new framework, package, infrastructure service, daemon, database, or hosting assumption not approved in these docs;
- mutate historical financial/stock facts instead of posting a correction/reversal;
- weaken property scoping, authorization, maker-checker approval, auditability, idempotency, transaction safety, privacy, or recovery requirements;
- require a permanent background process on the shared-hosting deployment profile;
- implement an unresolved PRD open question by guessing a business policy.

## Source-of-truth precedence

`Approved Change Request > PRD > RULES > ARCHITECTURE + accepted ADRs > DESIGN > TASK > SKILL > existing code`.

Existing code NEVER overrides these documents. If code conflicts with docs, the code is technical debt and must be corrected or a Change Request must be approved.

## Required traceability

Every implementation PR/commit must name the relevant `FR-*`, `NFR-*`, business-rule (`BR-*` when present), task (`TASK-*`), and ADR IDs. A requirement is not DONE until its tests and acceptance evidence satisfy `docs/RULES/15-DEFINITION-OF-DONE.md`.

## Protected documents

Agents must not silently edit `docs/PRD`, `docs/RULES`, or accepted `docs/ARCHITECTURE/ADR`. Changes require `docs/RULES/CHANGE-REQUEST-TEMPLATE.md` and approval. Task status/evidence may be updated as work progresses.
