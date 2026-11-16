# Component Contract

Use shadcn/ui primitives as owned source code under shared UI. Standardize project wrappers for:

`Button`, `IconButton`, `Input`, `Textarea`, `Select`, `Combobox`, `DatePicker`, `MoneyInput`, `QuantityInput`, `FormField`, `Dialog`, `AlertDialog`, `Drawer/Sheet`, `DropdownMenu`, `Tabs`, `Badge/StatusBadge`, `Toast`, `Skeleton`, `EmptyState`, `ErrorState`, `Pagination`, `DataTable`, `FilterBar`, `PageHeader`, `Metric`, `Timeline`, `AuditTrail`, `ApprovalPanel`.

Do not create one-off variants inside feature pages when a semantic shared variant should exist.

## Control dimensions and vertical alignment (2026-10-05)

- **Standard 36px height**: All single-line form controls (`Input`, `Select`, `Combobox`, `DatePicker`) and standard `Button` variants share an identical height of `h-9 min-h-9` (36px / 2.25rem); `sm` is 32px and `lg` is 40px. Never set standalone inputs to `min-h-10`/`min-h-11` or ad-hoc heights that cause misalignment against adjacent buttons or select menus.
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

## Surfaces (2026-11-10)

- The page is grey; what a person reads or acts on stands on the white surface. A table or grid, a card, a bordered box (`border border-border` with padding) and a list of rows all have `bg-surface` behind them. Write a list of rows as `list-panel divide-y divide-border` (a border, the surface and a side padding for the rows), never as bare rows with only a line between them.
- Headings, descriptions and definition lists may sit on the page itself; a row that has a button or a status does not.
- `resources/js/shared/ui/surface-contract.test.ts` fails on a bare list or a bordered box with no background.

## Row actions in tables (2026-11-16)

Every action inside a table row (a cell of a `DataGrid`, `DataTable` or plain `Table`) is a `RowAction` from `resources/js/components/ui/row-action.tsx`: a 32px square icon, coloured by what it does, with its name shown on hover and given to assistive technology (`label`). Never a text button inside a row. Actions above or below the table (add, export, filters) and inside dialogs stay text `Button`s. Inputs inside a row stay inputs.

The colour is the meaning, the same on every screen:

| Tone | Meaning | Usual icons (lucide-react) |
|---|---|---|
| `info` (blue) | Look: open, detail, view, history, print, download | `Eye`, `ExternalLink`, `History`, `Printer`, `Download`, `FileText` |
| `warning` (amber) | Change: edit, adjust, save an edit, reschedule, assign | `Pencil`, `Save`, `SlidersHorizontal`, `CalendarClock`, `UserCog` |
| `success` (green) | Move forward: approve, confirm, activate, post, pay, complete, receive, check in | `Check`, `CircleCheck`, `Send`, `Wallet`, `PackageCheck`, `LogIn` |
| `danger` (red) | Stop or remove: delete, cancel, reject, deactivate, void, check out | `Trash2`, `X`, `Ban`, `CircleX`, `Undo2`, `LogOut` |
| `neutral` (grey) | Anything else: copy, move, more | `Copy`, `ArrowRightLeft`, `Ellipsis` |

`label` is the same translated text the text button had. A link uses `href`; a busy action uses `loading`; `disabled` still shows its name on hover. Several actions sit in `RowActions` (or an existing `flex gap-1` wrapper).
