# Forms and Validation UX

- Labels remain visible; placeholder is not a label.
- Mark required fields explicitly where ambiguity exists.
- Show server validation next to the field and preserve non-sensitive user input.
- Domain conflicts (room no longer available, folio changed, stock version changed) use a conflict message with refresh/retry/review action, not generic "Something went wrong".
- Money fields show currency and server-returned calculated totals.
- Approval/void/refund/price override forms require reason when configured by PRD.
- Disable duplicate submission visually, but backend idempotency remains mandatory.

## Pickers (2026-10-02)

- **Select** (`components/ui/select.tsx`) keeps the native `<option>` API and `onChange(event)` but opens a **searchable list** (`Combobox`, cmdk on a Radix Popover). Turn the search off with `searchable={false}` only for a short fixed list (a page size, the language).
- **DatePicker** and **DateRangePicker** (`components/ui/date-picker.tsx`) replace `<input type="date">`. They hold plain `YYYY-MM-DD` strings (no time zone, see `shared/time/calendar-date.ts`), show the date in the person's language, take `min`/`max`, start the week on Monday and let month and year be picked from a list. A range is two clicks (first day, last day) with two months side by side on a wide screen and one on a phone.
- A list or calendar always opens **below** its field, or above it when there is no room, and is never drawn over the field itself, so the chosen value stays readable while choosing. It is checked in the browser by comparing the field's and the list's boxes.
- Inside a dialog the lists stack above the dialog (`z-[70]`) and close with Escape without closing the dialog.
