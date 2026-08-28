# Security / Безопасность

Solanace is intended for internal deployments behind a VPN or trusted reverse proxy.

The fresh-install build includes authentication on media endpoints, CSRF protection for state-changing API calls, strict sessions, security headers, protected uploads, filesystem containment checks, prepared SQL statements, CLI-only workers, and optional `ALLOWED_MEDIA_ROOTS` restrictions.

For deployment details see `README_RU.md` or `README_EN.md`.
