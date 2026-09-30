# Responsive and PWA

- Housekeeping/POS critical actions target touch interfaces and intermittent network.
- Back-office table-heavy screens may require desktop/tablet; mobile receives simplified summary/actions, not an unusable squeezed table.
- PWA caching must not cache private HTML/API responses indiscriminately.
- Static assets may be cached aggressively with content hashes.
- Offline mutation queue is feature-specific and follows Architecture offline rules; no generic service-worker write replay for payments.
