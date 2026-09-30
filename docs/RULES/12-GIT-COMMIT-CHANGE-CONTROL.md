# Git and Change Control

- One cohesive change per PR/commit series.
- Commit/PR description lists FR/NFR/TASK/ADR IDs.
- Never mix unrequested architecture refactor into a feature fix.
- Database migration, code rollout, and rollback compatibility are reviewed together.
- New runtime dependency requires rationale and architecture review.
- Protected docs change only through Change Request.
- Do not rewrite already-deployed migrations; add a new migration.
