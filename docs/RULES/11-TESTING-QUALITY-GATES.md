# Testing and Quality Gates

A change cannot merge when it breaks lint/format, static analysis, automated tests, frontend typecheck/build, migrations, or architecture boundaries.

Minimum evidence by change type:

- Domain behavior: unit tests including edge cases.
- Use case: feature/application tests including unauthorized and conflict paths.
- Persistence/concurrency: integration test against MySQL-compatible behavior.
- UI: typecheck plus interaction/feature evidence for non-trivial workflows.
- Bug fix: regression test.
- Migration: migration test and rollback/forward-fix note.
- Security-sensitive: explicit negative tests.

Coverage percentage alone is not a release gate; invariant and risk coverage is.
