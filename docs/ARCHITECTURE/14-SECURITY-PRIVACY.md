# Security and Privacy Architecture

- HTTPS only in production; secure cookies; CSRF protection; rate-limit authentication and public guest endpoints.
- Laravel 13 request-forgery protections must not be broadly disabled.
- Encrypt sensitive files at rest where hosting/storage permits; database fields containing high-risk secrets/PII use application encryption where searchable plaintext is not required.
- File downloads are authorized and logged; sensitive exports expire.
- Credentials exist only in environment/secret configuration, not Git or logs.
- Validate MIME/content/size for uploads; randomize storage names; never execute uploaded content.
- Apply object-level authorization to every identifier to prevent IDOR.
- Minimize data collection. Retention and deletion follow configured policy and legal/business decision; unresolved retention periods remain BLOCKED requirements, not guessed defaults.
- Backups must be protected separately from the live application and restore-tested.
