# Layout and Navigation

## Back office

- Persistent desktop sidebar grouped by operational domain.
- Header shows property, business date, user/role, critical alerts, and command/search entry if implemented.
- Breadcrumb only when hierarchy adds real orientation.
- Content width is fluid for tables; avoid narrow centered marketing containers.

## Field staff

- Mobile-first task queue.
- Bottom/compact navigation only for frequent destinations.
- Primary actions reachable without horizontal scrolling.
- Sync/offline status visible on POS/Housekeeping.

## Guest

- No staff navigation chrome.
- Short session-scoped flows with clear room/stay context and language switch.

## As built (2026-10-02)

`AppFrame` (`resources/js/components/layout/app-frame.tsx`) is the one frame of every back-office page: a persistent light sidebar on a large screen and a Sheet drawer on a phone, grouped by domain (start, operations, insight, control) with the pages of the active domain nested under it; a header with breadcrumbs, the property, the business date, the language and the person's menu (sessions, sign out); and the page's title, description and actions above its content. The module shells (`FrontOfficeShell`, `HousekeepingShell`, `LaundryShell`, `ReportingShell`, `PropertyShell`) only declare their pages and pass them to the frame. The property, the business date and the person's name reach the frame as the shared Inertia prop `shell`.


## Layout choice (2026-10-02)

`AppFrame` offers three layouts, chosen per browser with the layout button in the header (next to the language) and remembered in `localStorage` (`innsync.layout`, a display preference only):

- **Icon rail** (default): an 80px rail of module icons with a short label, and a panel beside it with the pages of the active module; more room for the page.
- **Sidebar**: the grouped sidebar with the pages of the active domain nested under it.
- **Top bar**: modules across the top, the pages of the active module as tabs under them, the property and business date at the right of the tab row; the page takes the whole width; an **All pages** menu lists every page of the module when the tabs do not fit.

Below `lg` every layout uses the same drawer. Pages are not aware of the layout.

## Sections of one page: tabs

A page that holds several independent lists or reports (the menu's categories, items and groups of choices; the cash flow's receipts and payments; a stock report by department and by location) shows them as tabs, not stacked one under the other. The tabs (`components/ui/tabs.tsx`) are a toolbar under the page header and above the content: navy, with the tab being shown in orange and navy text. Rules:

- Tabs split lists and reports. They do not split one document (a bill, an order, a reservation) whose parts are read together, and they are not for the pages of a module, which stay in the sidebar or the rail.
- Every panel stays mounted, so what was typed in one is kept when another is shown. The tab bar is hidden when printing and a printout has all the panels.
- A tab says what is in it and, where it helps, how many (`Items (12)`).
