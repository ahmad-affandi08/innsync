# TASK — Executable Backlog

## Status

`TODO -> READY -> IN_PROGRESS -> REVIEW -> DONE`, with `BLOCKED` when a product/technical dependency prevents correct implementation.

The status of a task is the one in the table of its row in `MASTER-BACKLOG.md` (the module files and `docs/PRD/TRACEABILITY-MATRIX.md` repeat it and must agree). The paragraphs headed "Status" or "Evidence" under the tables are a dated log of what each slice did: they say what was true on that day ("stays `IN_PROGRESS`") and are not corrected afterwards, so a later status in the table wins.

A task is not READY until its relevant PRD requirement, dependencies, acceptance behavior, and architecture owner are known. Wajib requirements cannot be waived by the implementer.

## Task format

Each task must declare: Task ID, FR/NFR references, bounded context, dependencies, implementation notes, tests, security/scope impact, migration impact, acceptance evidence, and status.

Module files below enumerate all 270 functional requirements. `PHASE-0-FOUNDATION.md` carries cross-cutting architecture/NFR work.
