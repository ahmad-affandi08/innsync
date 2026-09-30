# Shared source boundary

Shared PHP source is limited to `Domain`, `Application`, and `Infrastructure`. Shared code must be genuinely cross-cutting and may not become a generic dumping ground for module behavior.

Dependency direction remains inward: Infrastructure may depend on Application and Domain; Application may depend on Domain; Domain remains framework-independent.
