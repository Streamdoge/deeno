# Building your own deeno theme — step by step

A deeno theme is a folder with a few PHP files (markup) and one CSS file (styling).
**No build step, no npm, no frameworks** — open a file, edit it, save it.

This guide is for people who want their own theme but don't want to dig into the
internals. The full reference for every variable is in [THEME.md](THEME.md). And if
you don't want to write code at all, there's a [prompt for Claude](#prompt-for-claude)
at the end.

---

## The fastest path: copy `example`

The bundle includes an **`example`** theme — an "empty skeleton": all the platform's
markup and classes are already in place, but **with no styling**. The ideal starting
point.

1. Copy the `themes/example/` folder → `themes/my-theme/`.
2. Open `themes/my-theme/theme.json` and fill in the name/description:
   ```json
   {
     "name": "my-theme",
     "version": "1.0",
     "author": "Your name",
     "description": "A short description"
   }
   ```
3. All styling goes into `themes/my-theme/assets/style.css` — **every class** is
   already listed there as empty rules; just fill them in.
4. Activate the theme: **Admin → Themes → Activate** (or upload a ZIP, see below).

Done — from here you just style `style.css` until you like it.

---

## What a theme is made of

| File | Responsibility |
|---|---|
| `theme.json` | Name, version, author, description (required) |
| `layout.php` | The shell: `<head>`, header, footer. The rest is included inside it |
| `index.php` | Post lists: home, search, tag, category (+ pagination) |
| `post.php` | A single post (title, cover, text, tags, related) |
| `page.php` | A static page ("About", "Contact") |
| `404.php` | The "not found" page |
| `assets/style.css` | All the styling |

**Inheritance:** if a file is missing from your theme, deeno falls back to the one
from the `default` theme. So you can build a theme from just `theme.json` +
`style.css` and inherit the markup. But `assets/` are **not inherited** — you always
need your own `style.css`. (The `example` theme provides all the files upfront so
nothing is picked up from elsewhere.)

---

## What's available in templates

Briefly (in full — in [THEME.md](THEME.md)). Wrap all text output in `$e()`
(escaping), **except** `$post->content()` and `$post->excerpt()` — those are already
safe, ready HTML.

```php
$site->title, $site->tagline, $site->description, $site->url, $site->social  // about the site
$post->title, $post->url(), $post->date(), $post->author, $post->category,
$post->tags, $post->coverSrc(), $post->excerpt(), $post->content(), $post->hasMore()
$posts            // array of posts in lists
$cms->menuPages(), $cms->related($post)   // menu, related posts
$seo->head(...)   // the whole SEO block for <head> in one line
```

**Don't remove these three things** from `layout.php`, or functionality breaks:
- `<?= Hooks::filter('site.head', '') ?>` and `<?= Hooks::filter('site.footer', '') ?>` — plugins hook in here;
- the "Powered by deeno" line in the footer — you can add your own text next to it, but don't remove it.

---

## Don't forget the content constructs

Inside `$post->content()` there are elements created by the editor and plugins.
Their classes are **fixed** — just style them to your palette (in `example` these
selectors are already listed at the end of `style.css`):

- `mark` — highlight `==text==`;
- `.c-red … .c-gray` — text color `{red:…}`;
- `.md-left/.md-center/.md-right/.md-justify` — block alignment;
- `.md-video` — responsive video;
- `.dn-toc`, `.dn-share` — the "Table of contents" and "Share" plugins.

---

## How to test and install

- **Locally:** put the folder in `themes/`, activate it in the "Themes" section.
- **ZIP:** zip the folder's contents (so `theme.json` is at the archive root) and
  upload it via **Themes → Install theme**. That's how you hand a theme to someone else.

Check every page type: home, post, page, category, tag, search, 404 — and the
mobile layout.

---

## Prompt for Claude

Don't want to write CSS yourself? Open the project in Claude Code (or give Claude
access to the files) and paste a prompt like this, replacing the style description
with your own:

```
Build a theme for deeno CMS.

Base: copy the structure of the themes/example/ theme — it already has all the
markup and every platform class (layout.php, index.php, post.php, page.php,
404.php, theme.json, assets/style.css). Name the theme "<THEME-NAME>".

Task: write the styling in assets/style.css (and, if needed, carefully tweak the
markup in the templates). The style is:
<DESCRIBE: colors, fonts, mood, spacing, references — the more detail the better.
For example: "warm minimalism, cream background #faf7f2, terracotta accent, a serif
heading font like Georgia, wide margins, large typography, like a personal blog".>

Requirements:
- clean CSS, no external CDNs, npm or build step;
- responsive (check mobile);
- keep the content-construct classes (mark, .c-*, .md-*, .md-video, .dn-toc,
  .dn-share) and the Hooks::filter('site.head'|'site.footer') points;
- don't remove the "Powered by deeno" line;
- at the end, show the theme on demo content and fix whatever breaks.
```

Claude will assemble the theme from your description, and you just say what to tweak.

---

Detailed reference for every object and example — [THEME.md](THEME.md).
