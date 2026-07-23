# Changelog

Format based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).
This project adheres to [Semantic Versioning](https://semver.org/).

## [1.0.0] — 2026-07-17

First public release. A complete, database-less flat-file CMS.

### Content

- Posts and static pages in Markdown with a YAML frontmatter header.
- Categories (title, slug, description, merging) and tags.
- A navigation menu built from static pages.
- Post statuses: draft, published, sticky, scheduled, unlisted.
- Site search and automatic 301 redirects when a post's address changes.
- RSS 2.0 feed and Sitemap 0.9.

### Editor

- Formatting toolbar: headings, bold/italic/strikethrough, highlight, text color,
  alignment, lists, quote, tables, inline/block code, links.
- Image and video (YouTube/Vimeo) embedding.
- Image crop and rotation on upload; live preview; scheduled publishing.
- Automatic draft recovery from the browser.
- Built with no external libraries.

### Media

- Media library with grid/list views and drag-and-drop upload.
- Automatic photo optimization on upload: downscale, compression, EXIF rotation
  baked into pixels, GPS metadata stripping.
- SVG and ICO support (SVG is sanitized on upload).

### Admin panel

- Dashboard with counters, a views chart, top pages and a security checklist.
- View statistics without cookies or IP storage (daily-salted hashes).
- Roles: administrator / editor / author, each with its own permissions.
- Jump bar (⌘K) for instant navigation.
- Light and dark admin themes; bilingual interface (English / Russian).
- One-click ZIP backups.

### Themes and plugins

- Themes are plain PHP templates with inheritance from `default`, no build step.
- Four bundled themes: `default`, `journal`, `deeno-news`, `deeno-docs`
  (a documentation/wiki theme with a navigation tree, on-page TOC with scroll-spy,
  breadcrumbs, and drag-and-drop arrangement for logged-in editors).
- An `example` starter theme (all markup and classes, no styling).
- Hook-based plugins (filters and events); five bundled: external links,
  lazy images, reading time, table of contents, share buttons.

### Security

- CSRF protection on every form; bcrypt password hashing.
- Brute-force protection by IP and by username.
- A strict Content-Security-Policy on the public site (inline scripts allowed by
  sha256 hash; external domains configurable in Settings → External scripts).
- HMAC-signed preview and password-reset tokens; sessions invalidated on password
  change.
- Self-protecting data files (`config.php`, `users/`, `secret.key` return 403).
- Upload type allowlist with MIME checked by content.
- HTML sanitization for non-administrator content.

### Reliability & performance

- Full-page cache with automatic content-based invalidation.
- Atomic writes for all data files (no corruption on interrupted writes).
- Append-based view statistics that don't slow down page serving.
- Cache-busting for theme assets and post covers.
