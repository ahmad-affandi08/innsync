# Deployment Profile — Niagahoster / Hostinger Shared Web Hosting

## Release artifact

Production receives Composer production dependencies and prebuilt Vite assets. Never run a dev server in production.

## Directory safety

Preferred deployment keeps the Laravel project outside the public document root and maps the domain document root to `/public`. If the plan cannot configure this safely, deployment is BLOCKED until a secure layout is proven. Do not copy the whole framework into a publicly browsable directory as a shortcut.

## Release steps

1. Verify PHP >= 8.3 and required Laravel extensions.
2. Put application in maintenance/release-safe mode only when migration risk requires it.
3. Upload/release code and vendor/build artifacts.
4. Run migrations with `--force` after backup and compatibility checks.
5. Run `config:cache`, `route:cache` when compatible, `view:cache`, and application-specific warmups.
6. Ensure writable `storage` and `bootstrap/cache`.
7. Validate private/public storage links and access policy.
8. Run smoke tests and health endpoint.
9. Enable release and monitor logs/critical flows.

## Cron

Configure scheduler every minute. If queue jobs are used, configure a bounded queue drain through cron; design jobs to be safe if a previous cron overlaps or is killed by hosting limits.

## Explicitly unsupported in this profile

Permanent Supervisor workers, Laravel Horizon dashboard worker management, self-hosted Reverb/WebSocket server, Octane/RoadRunner/Swoole, arbitrary system services, and infrastructure that assumes root access.
