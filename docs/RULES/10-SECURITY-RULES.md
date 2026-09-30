# Security Rules

- Authorize every command/query server-side.
- Scope every property-owned query.
- CSRF remains enabled for session web routes.
- Public/guest endpoints receive explicit rate limits.
- No secret/PII in logs, query strings, analytics events, or permanent public URLs.
- Sensitive exports are short-lived and audited.
- Passwords use framework-supported secure hashing.
- Session/cookie settings use secure production defaults.
- Payment card numbers are never stored.
- Webhooks validate provider authenticity where supported and are idempotent.
- Uploads are validated and stored outside executable public paths.
