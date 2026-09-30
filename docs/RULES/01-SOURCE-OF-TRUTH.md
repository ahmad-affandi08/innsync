# Source of Truth and Conflict Resolution

Precedence: `Approved CR > PRD > RULES > ARCHITECTURE/ADR > DESIGN > TASK > SKILL > code`.

A lower layer may clarify implementation but may not weaken a higher layer. When two same-level rules conflict, stop, document both, and seek owner decision. Do not choose whichever is easiest.

Canonical business facts follow the PRD System-of-Record table. Reporting and UI caches are projections only.
