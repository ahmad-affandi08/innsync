# Component Contract

Use shadcn/ui primitives as owned source code under shared UI. Standardize project wrappers for:

`Button`, `IconButton`, `Input`, `Textarea`, `Select`, `Combobox`, `DatePicker`, `MoneyInput`, `QuantityInput`, `FormField`, `Dialog`, `AlertDialog`, `Drawer/Sheet`, `DropdownMenu`, `Tabs`, `Badge/StatusBadge`, `Toast`, `Skeleton`, `EmptyState`, `ErrorState`, `Pagination`, `DataTable`, `FilterBar`, `PageHeader`, `Metric`, `Timeline`, `AuditTrail`, `ApprovalPanel`.

Do not create one-off variants inside feature pages when a semantic shared variant should exist.

## Control dimensions and vertical alignment (2026-10-05)

- **Standard 40px height**: All single-line form controls (`Input`, `Select`, `Combobox`, `DatePicker`) and standard `Button` variants share an identical height of `h-10 min-h-10` (40px / 2.5rem). Never set standalone inputs to `min-h-11` (44px) or ad-hoc heights that cause misalignment against adjacent buttons or select menus.
- **Inline filter rows**: In filter bars (such as in reporting, cashier shifts, routines, housekeeping), wrap fields in `<FormField>` without vertical hacks (`mt-auto`). Align the row with `items-end gap-3`. The submit/apply button must use standard height (`<Button type="submit" variant="outline">` without `size="sm"`) so it aligns flush with the inputs and pickers.

## Interaction affordance (cursor: pointer) (2026-10-05)

- Every clickable or interactive element must have `cursor: pointer`.
- Enforced globally in `resources/css/app.css` for `button:not(:disabled)`, `[role="button"]`, `[type="button"]`, `[type="submit"]`, `[type="reset"]`, `select:not(:disabled)`, `summary`, and explicitly declared on `buttonVariants`, combobox triggers, and interactive dropdowns.

## Scrollbar contract (2026-10-05)

- **Global scrollbars**: Configured in `app.css` via `scrollbar-width: thin; scrollbar-color: var(--border) transparent;` and 6px WebKit scrollbars with rounded pill thumbs. No native browser element (tables, dialogs, pages) should render wide 17px default OS scrollbars with stepper buttons.
- **Radix ScrollArea**: `<ScrollArea>` in `resources/js/components/ui/scroll-area.tsx` supports `orientation="vertical" | "horizontal" | "both"`. Its thumb is styled as `rounded-full bg-border hover:bg-muted-foreground/50 transition-colors`. Used in `sidebar`, `rail`, `sheet`, and scrollable panels.
- **Horizontal navigation bars**: Elements with horizontal scrolling in headers (such as topbar tabs) use the `.no-scrollbar` utility to hide scrollbars while preserving touch/wheel/trackpad scrolling.


## Money and shift on screen (2026-11-10)

- `MoneyInput` shows the currency before the typed amount (`Rp` for the rupiah, otherwise the code), taken from the property (`shell.currency`); pass `currency="USD"` for another one, or `currency={null}` for an amount inside a table cell. The mark is outside the value: what the page reads and sends is unchanged.
- The top bar shows the cashier shift the signed-in person has open (`shell.shift`) on every page, linking to the cashier screen; a shift opened on an earlier business date is marked with `!` and a warning colour, because it should be closed.
- Plain `<input type="checkbox">` and `type="radio"` are styled once in `app.css` (16px, brand colour); use the shared `Checkbox`/`RadioGroup` when a control needs more than a native one gives.
- One primary (filled) button for each task context: on a folio it is *Record payment*.
