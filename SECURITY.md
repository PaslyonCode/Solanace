# Security / Безопасность

Solanace is intended primarily for internal deployments behind a LAN, VPN, or trusted reverse proxy. It is an administrative application with intentional access to selected server-side media folders and should not be exposed directly to the public Internet without an additional access-control layer.

## Built-in protections

- Argon2id application passwords;
- strict PHP sessions, HttpOnly, SameSite=Lax and Secure cookies under HTTPS;
- CSRF protection for state-changing requests;
- prepared SQL statements for user-controlled values;
- media-root containment checks and optional `ALLOWED_MEDIA_ROOTS` allowlist;
- authenticated video and attachment delivery;
- no arbitrary base64/absolute-path reads in `media.php`;
- direct HTTP denial for `uploads/`, service libraries, workers, SQL and Python files under the supplied Apache rules;
- CSP, X-Frame-Options, X-Content-Type-Options, Referrer-Policy and Permissions-Policy;
- upload size/count validation;
- CLI-only background workers;
- ZIP path validation during import to prevent path traversal / Zip Slip.

## Deployment recommendations

1. Change both the application password and the default DB password immediately after installation.
2. Run the PHP/web-server account with only the filesystem permissions Solanace actually needs.
3. Restrict media access with `ALLOWED_MEDIA_ROOTS` on multi-purpose servers.
4. Prefer HTTPS through a trusted reverse proxy or VPN.
5. Do not enable `TRUST_PROXY_HEADERS` unless direct access to the backend is blocked and the proxy rewrites forwarded headers.
6. For Nginx, reproduce the deny rules described in `README_RU.md` / `README_EN.md`; Nginx ignores `.htaccess`.

## Reporting security issues

If a GitHub repository is used for the project, prefer a private GitHub Security Advisory / private contact channel for exploitable vulnerabilities rather than publishing sensitive reproduction details in a public issue.
