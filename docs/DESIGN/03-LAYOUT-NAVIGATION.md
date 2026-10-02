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

`AppFrame` (`resources/js/components/layout/app-frame.tsx`) is the one frame of every back-office page: a persistent sidebar on a large screen and a drawer on a phone, grouped by domain with the pages of the active domain nested under it; a header with the property, the business date, the language and the person's menu (sessions, sign out); and the page's title, description and actions above its content. The module shells (`FrontOfficeShell`, `HousekeepingShell`, `LaundryShell`, `ReportingShell`, `PropertyShell`) only declare their pages and pass them to the frame. The property, the business date and the person's name reach the frame as the shared Inertia prop `shell`.

