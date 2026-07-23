# Security Policy

## Supported versions

Security updates are released for the latest minor release (`1.x`).
Keep up to date — see [README → Updating](../README.md#updating).

## Reporting a vulnerability

**Please do not open a public issue for vulnerabilities.**

Use a private channel:

1. **GitHub Security Advisories** (preferred) — the **Security → Report a
   vulnerability** tab in the repository. This channel is hidden from the public.
2. Or email the maintainer: `<support@deeno.tech>`.

Please include, where possible:

- the deeno, PHP and web-server (Apache/nginx) versions;
- reproduction steps or a PoC;
- the expected impact (RCE, XSS, auth bypass, etc.).

We'll try to respond as soon as we can and to agree on a disclosure timeline.
After a fix ships, we'll credit the reporter in the changelog (optional).

## What protection is already built in

- CSRF tokens on every form, `password_hash()` (bcrypt), brute-force protection.
- HMAC-signed preview and password-reset tokens.
- HTML sanitization for non-administrator content (safe-mode Markdown).
- Self-protecting data files (`config.php`, `users/`, `secret.key` → `403`).
- An upload type allowlist (MIME checked by content); SVG is sanitized on upload.

## Trust model

Installing **plugins and themes** (the `admin` role) runs their PHP code on the
server — a deliberate trust boundary, as in any CMS. Only install code you trust.
See [README → Security](../README.md#security) for more.
