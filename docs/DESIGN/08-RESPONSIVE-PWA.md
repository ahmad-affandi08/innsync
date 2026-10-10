# Responsive and PWA

- Housekeeping/POS critical actions target touch interfaces and intermittent network.
- Back-office table-heavy screens may require desktop/tablet; mobile receives simplified summary/actions, not an unusable squeezed table.
- PWA caching must not cache private HTML/API responses indiscriminately.
- Static assets may be cached aggressively with content hashes.
- Offline mutation queue is feature-specific and follows Architecture offline rules; no generic service-worker write replay for payments.
- Installable: manifest, icons, offline page and `public/sw.js` are described in `docs/OPERATIONS/OFFLINE-OPERATIONS.md` (Installable app). The worker keeps only content-hashed build files and the offline page; it is covered by `resources/js/shared/pwa/*.test.ts` and `tests/Feature/Foundation/InstallableAppTest.php`.
