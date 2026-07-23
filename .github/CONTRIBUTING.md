# Contributing to deeno

Thanks for your interest! deeno is a flat-file CMS in plain PHP with no database
and no runtime dependencies. That imposes a few rules.

## Before you start

- **Bug or idea** — check the [issues](../../issues) first; if there's nothing
  similar, open a new one. For large changes, discuss the plan before writing code.
- **Vulnerabilities** — not in a public issue; see [SECURITY.md](SECURITY.md).

## Project philosophy

1. **No runtime dependencies.** No composer packages, no jQuery, no CSS
   frameworks. Parsedown is bundled as a single file. Composer is used only for
   dev tooling (lint); the end user does not need it.
2. **Runs on any shared hosting** with PHP 8.0+, FTP upload, no CLI.
3. **Secure by default** (see the rules below).

## Development rules

- `declare(strict_types=1)` in every PHP file.
- All output through `e()` / `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`.
- Every POST verifies a CSRF token **before** any action.
- `ContentManager` knows nothing about HTTP — files only.
- In the admin panel, vanilla JS and CSS Custom Properties only (no libraries).
- UI changes follow the "deeno UI" design system (indigo `#4F6EF7`, 6px radius).
- Indentation: PHP — 4 spaces, CSS/JS/JSON/YAML — 2 (see `.editorconfig`).

## Before opening a PR

```bash
composer test    # unit: tests/run.php + tests/managers.php
composer smoke   # HTTP integration: tests/smoke.sh (needs bash)
composer lint    # php -l over all files
```

All three must be green — the same set runs in CI on PHP 8.0–8.3. Where to add your
own check: pure logic (parsers/security) → [`tests/run.php`](../tests/run.php);
a file manager → [`tests/managers.php`](../tests/managers.php) (it has a sandbox);
a route/access check over HTTP → [`tests/smoke.sh`](../tests/smoke.sh).

## PR conventions

- One logical change per PR, a clear title.
- Describe **what** and **why**; attach screenshots for UI changes
  (light and dark themes).
- Update the documentation (`README.md`, `UserDoc/`) if behavior changed.
