# Application Layer Rules

Each command/query maps to an explicit use case. Commands mutate; queries read. A use case:

1. resolves authorization/scope at the application boundary;
2. loads required aggregate(s) through contracts;
3. executes domain behavior;
4. commits one coherent transaction;
5. records outbox/audit as required;
6. returns a transport-neutral result DTO.

Do not call external providers while holding a long DB transaction. Use idempotency/correlation IDs for critical commands.
