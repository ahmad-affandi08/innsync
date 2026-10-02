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

## Required fields (2026-10-02)

A field the backend requires shows a **red `*`** after its label by itself, and its control gets `aria-required`. Nobody lists required fields in the frontend; they come from the validation rules:

1. `php tools/export-form-rules.php` reads the controllers' `validate([...])` arrays and `FormRequest::rules()` (static, nothing runs; `tests/Support/FormRuleExtractor.php`) into `resources/js/generated/route-rules.json`: for each route, the fields it requires (`required`, not `sometimes`; nested keys such as `lines.*.quantity` kept; `confirmed` adds `<field>_confirmation`).
2. `npm run forms` (`scripts/form-requirements.mjs`, also run by `npm run build`) reads every screen: the requests it sends (`action.run('/url', { body })`, `apiRequest`, `router.post`, `form.put`) and the `<FormField field="…">` props, and writes `resources/js/generated/form-requirements.json` (screen → required fields). A field is starred when every request of that screen that carries it requires it; when that is unclear it gets no star, never a wrong one.
3. `FormField` looks the field up for the current screen. Give each data-entry `FormField` the **request key** as `field="…"` (the key the server validates, for example `amount_minor`, not the on-screen name) and bind `error` to the same key. Filters, searches and period pickers that only reload a page have no `field` and no star.

Changing a validation rule: run `npm run forms:rules` and commit both generated files. Two tests fail until you do: `FormRulesFreshnessTest` (PHP) and `form-requirements.test.ts` (which also fails on a `field` name that no request of its screen declares, a typo or a renamed key).
