# Stack and Runtime Constraints

## Approved baseline

| Area | Decision |
| --- | --- |
| Backend | Laravel 13, PHP >= 8.3 |
| Database | MySQL 8, InnoDB, utf8mb4 |
| Server UI | Inertia.js |
| Frontend | React + TypeScript strict |
| Styling | Tailwind CSS 4 + CSS variable design tokens |
| Components | shadcn/ui, locally owned components |
| Server state | TanStack Query where background/refetch/cache behavior is actually needed |
| Tables | TanStack Table; server-side pagination/filter/sort for large datasets |
| Hosting | Niagahoster/Hostinger shared web hosting profile |

## Hosting consequences

1. Laravel 13 requires PHP 8.3+. Deployment must fail fast if the selected hosting runtime is older.
2. The web server document root must expose Laravel `public/`, never the repository root.
3. Build Node/Vite assets in CI or a development machine. Production must not require a persistent Node process.
4. Shared hosting is treated as **no permanent process supervisor**. Do not depend on Horizon, Reverb, Octane, RoadRunner, or an always-on `queue:work` process.
5. Use Laravel Scheduler through cron and a short-lived database queue drain such as `queue:work --stop-when-empty` when async processing is needed.
6. Redis is an optional optimization, not a hard dependency, unless a later ADR changes the hosting profile.
7. Real-time UI must work with polling/refetch first. WebSockets may be added only through an approved managed provider or infrastructure change.
8. Private guest/HR files must not be placed in a permanently public directory. Serve them through authorization or approved private object storage.

## Verified external references (2026-09-30)

- Laravel 13 deployment requirements: https://laravel.com/framework/docs/13.x/deployment
- Laravel 13 release/PHP support: https://laravel.com/framework/docs/releases
- Hostinger PHP versions: https://www.hostinger.com/id/support/1575755-cara-mengubah-versi-php-hostinger/
- Hostinger cron: https://www.hostinger.com/id/support/1583465-cara-setup-cron-job-di-hostinger/
- Hostinger background-process guidance: https://www.hostinger.com/id/support/1583713-bisakah-saya-menjalankan-proses-latar-belakang-dengan-ssh-di-hostinger/

If the purchased hosting plan differs materially, create an ADR rather than silently assuming VPS capabilities.
