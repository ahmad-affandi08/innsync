# Domain Layer Rules

- Pure PHP only.
- Aggregate root owns state-changing methods.
- Value objects validate themselves and are immutable.
- Domain service is allowed only when a business rule genuinely spans entities and does not belong naturally to one aggregate.
- Domain events are facts in past tense.
- Invariants are impossible to bypass through public setters.
- Historical price/tax/service-charge facts are snapshotted.
- Status transitions must use the PRD state machine; no arbitrary string update.
