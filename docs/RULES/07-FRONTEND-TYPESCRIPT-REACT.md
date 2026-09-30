# React + TypeScript Rules

- `strict: true`; avoid `any`. `unknown` must be narrowed.
- Business calculations remain authoritative on the server. UI may display previews but cannot become the source of truth.
- Components are presentational or feature orchestration; do not embed API/data-fetch complexity in generic UI primitives.
- Feature code lives under its module.
- Do not maintain the same server entity in multiple independent local/global stores.
- All async screens implement loading, empty, error, retry, unauthorized, and stale/conflict states as applicable.
- Use stable semantic IDs from server data; never array indexes for mutable row keys.
- Destructive/sensitive actions show consequence, require reason/approval when PRD requires it, and display server result.
