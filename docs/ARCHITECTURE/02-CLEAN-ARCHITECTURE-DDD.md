# Clean Architecture + DDD Rules

## Layer direction

`Presentation -> Application -> Domain`

`Infrastructure -> Application/Domain contracts`

The Domain never imports Laravel or Infrastructure. Application may depend on Domain and ports/contracts. Infrastructure implements ports. Presentation converts transport concepts into application input and output.

## Module anatomy

```text
app/
  Modules/
    FrontOffice/
      Domain/
        Aggregates/
        Entities/
        ValueObjects/
        Enums/
        Events/
        Policies/
        Services/
        Repositories/        # interfaces only
        Exceptions/
      Application/
        Commands/
        Queries/
        DTOs/
        Handlers/
        Ports/
        Projectors/
      Infrastructure/
        Persistence/Eloquent/
        Repositories/
        Integrations/
        Jobs/
        Providers/
      Presentation/
        Http/Controllers/
        Http/Requests/
        Http/Resources/
        Routes/
  Shared/
    Domain/
    Application/
    Infrastructure/
```

Frontend mirrors domain ownership under `resources/js/modules/<module>/` while reusable UI primitives live in `resources/js/components/ui/` and cross-module utilities in `resources/js/shared/`.

## Aggregate rule

Each command loads one aggregate root whenever possible. Invariants are enforced by aggregate methods/value objects, not controllers. Cross-aggregate consistency requiring strict atomicity is coordinated by an application service within one DB transaction. Eventual side effects use the outbox.

## Query rule

Read-heavy dashboard/reporting may use optimized read models/query services and does not need to rehydrate aggregates. Read models may join across contexts but may not mutate another context.
