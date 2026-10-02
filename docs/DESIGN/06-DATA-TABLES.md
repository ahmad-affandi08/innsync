# Data Tables — TanStack Table Standard

Every operational table defines:

- typed column model;
- stable row ID;
- server-side pagination by default for unbounded data;
- server-side filtering/sorting for large/authoritative lists;
- URL-synchronized filters when a list should be shareable/bookmarkable;
- role-aware actions rendered only after server permission data, while backend re-authorizes;
- column visibility and density preferences where useful;
- empty, loading, error, and retry states;
- accessible headers and keyboard focus;
- export as a server job/endpoint when full dataset is required.

Never load tens of thousands of rows into the browser just to let TanStack paginate locally.

## DataGrid (2026-10-02)

Every list table in the back office uses `DataGrid` (`resources/js/components/ui/data-grid.tsx`, logic in `resources/js/shared/table/grid-model.ts`, unit-tested). It works on the rows a screen already holds, so it is for bounded lists; an unbounded list still pages on the server (the rule above stands) and may put a DataGrid on the page the server returned.

Each grid has:

- a search box over every column that has a `value` (every word must match; `searchText` covers a formatted amount or date);
- a **Filters** popover with one control per filterable column (`filter: 'select'` lists the distinct values, `'text'` matches a substring), a count of active filters and a clear-all action;
- a **Columns** menu to show or hide columns (`hidden: true` hides one by default, the row-header column cannot be hidden), remembered per grid in the browser (`innsync.grid.<id>`);
- sortable headers (ascending, descending, none; empty values always last; `aria-sort` set);
- pagination below: a rows-per-page choice of **10 / 25 / 50 / 100 (default 10)**, first, previous, numbered pages with gaps, next, last, and a summary such as "Showing 11–20 of 35" (with "from N in all" while a search or filter is active);
- a "no rows match" state with a clear-all action, distinct from the screen's own empty state.

Fixed-shape tables (a handful of fixed rows, a printed bill, a price quote, the availability calendar, an editable form grid) use the flat `Table` primitives and have no search or paging. Totals live in a column's `footer`. Each grid has a stable `id`; keep every `data-testid` through `testId`, `rowTestId` and the cell content.
