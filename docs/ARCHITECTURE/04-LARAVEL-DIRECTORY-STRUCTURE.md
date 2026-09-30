# Laravel Directory Structure

```text
app/
├── Modules/
│   ├── IdentityAccess/
│   ├── Property/
│   ├── FrontOffice/
│   ├── Housekeeping/
│   ├── Laundry/
│   ├── FnbSales/
│   ├── Kitchen/
│   ├── InventoryPurchasing/
│   ├── Maintenance/
│   ├── HumanResource/
│   ├── Finance/
│   ├── Reporting/
│   └── GuestExperience/
├── Shared/
│   ├── Domain/
│   ├── Application/
│   └── Infrastructure/
└── Providers/

resources/js/
├── app.tsx
├── components/ui/          # shadcn primitives
├── layouts/
├── modules/<module>/
│   ├── pages/
│   ├── components/
│   ├── features/
│   ├── queries/
│   ├── tables/
│   ├── types/
│   └── utils/
└── shared/

routes/
├── web.php                 # bootstrap only
└── modules/                # module route files if chosen by provider

tests/
├── Unit/Domain/
├── Feature/Application/
├── Feature/Http/
├── Integration/
└── Architecture/
```

Do not recreate a generic `Services/` dumping ground. A class must live in the context and layer whose responsibility it implements.
