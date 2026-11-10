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


## Layout choice (2026-10-02, updated 2026-10-05)

`AppFrame` offers three layouts, chosen per browser with the layout button in the header (next to the language) and remembered in `localStorage` (`innsync.layout`, a display preference only):

- **Icon rail** (default): an 80px rail of module icons with a short label, and a panel beside it with the pages of the active module; more room for the page.
  - *Scrollbar rule*: The icon rail navigation is wrapped in `<ScrollArea className="w-full flex-1">` (Radix UI custom scrollbar) rather than raw `overflow-y-auto`. This prevents OS-native scrollbars (especially on Linux and Windows) from rendering 17px wide grey bars with stepper arrows that squeeze the 80px rail and displace icon centering. The active module subpage panel also uses `<ScrollArea>`.
- **Sidebar**: the grouped sidebar with the pages of the active domain nested under it. Uses `<ScrollArea className="flex-1">` for smooth, unobtrusive scrolling.
- **Top bar**: modules across the top, the pages of the active module as tabs under them, the property and business date at the right of the tab row; the page takes the whole width; an **All pages** menu lists every page of the module when the tabs do not fit.
  - *Scrollbar rule*: The horizontal module navigation bar and subpage tab bar use `.no-scrollbar` with `overflow-x-auto`. Native horizontal scrollbar tracks are hidden so they never consume vertical space in compact 40px/56px headers, while retaining smooth horizontal scrolling via wheel/touchpad/touch. All pages remain directly accessible via the "Semua halaman" / "All pages" dropdown menu.

Below `lg` every layout uses the same drawer (`<Sheet>`), which also wraps menu content in `<ScrollArea>`. Pages are not aware of the layout.

In the drawer every module is a collapsible group: tapping a module opens or closes the list of its pages (the active module starts open) and does not navigate or close the drawer; only choosing a page navigates and closes it. Every module's pages come from `components/layout/module-links.ts`, the one list shared with the module shells, so a module that is not the current one can still show its pages. The desktop layouts are unchanged.

On a touch screen every text field, select and text area is at least 16px so the browser does not zoom on focus (`resources/css/app.css`). Times are entered with `TimeInput` (`components/ui/time-input.tsx`): the same height and look as every `Input`, numeric keypad, `HH:MM` completed on leaving the field; the browser's own time widget is not used.

## Language Switcher (2026-10-05)

The header includes a `LanguageSwitcher` (`resources/js/components/ui/language-switcher.tsx`) positioned beside the layout switcher:
- Uses a `DropdownMenu` showing flag icons and language codes: Indonesian (`ID`, `id.png`) and English (`ENG`, `uk.png`).
- Asset paths: `resources/js/assets/flags/` for Vite bundling and `public/images/flags/` for public assets.
- Trigger displays the active country flag and 2-3 letter code (`ID` or `ENG`) in a compact 40px (`size-10`) button matching the header icon button standard.
- Active language is marked with a checkmark in the dropdown menu. Switching language posts to `/language` and reloads Inertia page state.

## Sections of one page: tabs

A page that holds several independent lists or reports (the menu's categories, items and groups of choices; the cash flow's receipts and payments; a stock report by department and by location) shows them as tabs, not stacked one under the other. The tabs (`components/ui/tabs.tsx`) are a toolbar under the page header and above the content: navy, with the tab being shown in orange and navy text. Rules:

- Tabs split lists and reports. They do not split one document (a bill, an order, a reservation) whose parts are read together, and they are not for the pages of a module, which stay in the sidebar or the rail.
- Every panel stays mounted, so what was typed in one is kept when another is shown. The tab bar is hidden when printing and a printout has all the panels.
- A tab says what is in it and, where it helps, how many (`Items (12)`).

## Pages of a module: only the ones the person has work in, in groups (2026-11-10)

Traceability: owner instruction of 2026-11-10 (too many menus confuse people), `NFR-06`, `docs/OPERATIONS/AUTOMATION-PLAN.md` (Tahap 2).

- Each page link in `components/layout/module-links.ts` may carry `needs`: areas of permission (the first two parts of a permission code, `finance.payable`). The page is listed when the person holds any permission in one of its areas; a link with no `needs` is listed to the whole department. The server sends the person's areas with every page (`shell.permissionAreas`, `ModuleAccessMap::areas`). If they cannot be read, every page is listed. The page the person is on always stays in the list.
- This decides what is shown, nothing else: every page and every action still checks the permission on the server (the menu is not a security boundary).
- Links may carry `group`: the pages are listed under small headings (daily close, payables, receivables ...) in the sidebar, the drawer and the rail panel. The headings fold (`components/layout/nav-sections.tsx`): the group of the page the person is on is open, the others show only the heading and open with a click; headings are plain semibold text with a chevron, the pages of an open group are indented under a guide line, and the page the person is on is a soft brand-coloured pill; what the person opened stays open while they move between pages. In the rail the modules are separated by a thin line between the start, operations, insight and control groups. The scroll position of each menu list is kept between pages (`ScrollArea keep`). The top bar shows tabs without headings. Pages stay pages: each keeps its own address, and tabs are still not used for the pages of a module (the rule above is unchanged).
- A link may carry `feature`: it is left out while the feature is switched off for the installation (today only the loyalty programme). The door-lock page stays listed because its settings are where it is switched on.
- A page that two departments need is linked from the department that owns it. The housekeeping menu no longer repeats the front office room board, and the home page no longer repeats the point of sale and the guests' orders.
- The icon rail lists six entries (`AREAS` in `app-frame.tsx`): Home, Guests and rooms (front office, housekeeping, laundry), F&B (F&B, kitchen), Engineering (maintenance), Back office (finance, HR, inventory) and Reports (opening on the dashboard, the first page of its list). An area only decides which rail entry a department sits under: each department keeps its pages, permissions and on/off switch, and the departments of an area are chosen with the small tabs at the top of the page list. An entry is left out when the person has none of its departments. Approvals and the property's settings are in the account menu (the approvals inbox is also in the bell). The same six areas are used by every layout: the icon rail, the full sidebar (an area opens to its department tabs and pages), the top bar (areas across the top, the department tabs at the start of the page row) and the phone drawer (an area opens to its departments, each of which opens to its pages). A module may give a shorter name for the rail (`short`, today F&B, Kitchen, Property) so every label fits on one line; the tooltip, the sidebar and the drawer keep the full name.

## Home page: what waits for the person today (2026-11-10)

The home page ("Hari Ini") no longer lists every department. It shows the work waiting for this person, then the day's numbers (for those who may see the dashboard) and a few daily links offered by the same permission rule as the menu.

- Each department answers for itself through `Shared\Application\Setup\TodayTasks` (tag `today-tasks`; one class per module, e.g. `FrontOfficeTodayTasks`): a list of `{key, count, href, urgent}`, only for a person who holds the permission for that work. `key` is `<prefix>.<what>`; the wording is `today.task.<key>` in both languages. `Setup\TodayList::arrange` leaves out zero counts and the work of a department the property switched off, puts late work first and then the larger counts, and keeps twelve.
- A department that fails is reported and skipped; it never takes the page down.
- A person who works in one department only still lands on that department's own screen (`DepartmentEntries`); the home page is for people who work across departments, and is always one click away (the Home entry of the rail).

## Optional pages and the quick menu (2026-11-10)

- A page that most hotels never use is an optional feature (`App\Shared\Application\Setup\OptionalFeatures`, `property_features`): off for a new property, switched on in Set-up → How the property works. Give its menu entry `feature: '<key>'` in `module-links.ts`. It changes the menu only; the route, the permission and the data are untouched, and the page a person is on stays in the menu.
- What a person asks for from any department (a fault to report, something to buy) is not repeated in each department's menu. The top bar's *Report or request* menu lists it for the departments the person works in (`QUICK_ACTIONS` in `module-links.ts`).

