# deeno user guide

A practical guide to the admin panel. For installation, see [README.md](../README.md);
for building themes/plugins, see [THEME.md](THEME.md) and [PLUGINS.md](PLUGINS.md).

## Contents

- [Admin sections — what each one does](#admin-sections--what-each-one-does)
- [Overview (dashboard)](#overview-dashboard)
- [Your first post](#your-first-post)
- [Formatting text](#formatting-text)
- [Pages and menu](#pages-and-menu)
- [Media](#media)
- [Categories](#categories)
- [Themes](#themes)
- [Documentation / wiki (the deeno-docs theme)](#documentation--wiki-the-deeno-docs-theme)
- [Plugins](#plugins)
- [Backups](#backups)
- [Settings](#settings)
- [Users and roles](#users-and-roles)

## Admin sections — what each one does

A quick map: the sidebar on the left holds these sections. Below is what each one
is for and who can see it.

| Section | What it does | Who sees it |
|---|---|---|
| **Overview** | Dashboard: counters (posts, in progress, views over 7 days, last backup), views chart, top pages, security checklist | everyone (author — their own data) |
| **Posts** | Feed articles: create, edit, statuses, filters by status/category, search | everyone (author — their own only) |
| **Categories** | Post sections: title, slug, description, merging | admin, editor |
| **Pages** | Static pages ("About", "Contact") and site menu items | admin, editor |
| **Media** | Media library: upload with crop/rotate, grid/list, inserting into posts, covers | everyone |
| **Users** | Accounts and roles | admin |
| **Themes** | Site appearance: activation, install from ZIP | admin |
| **Plugins** | Extensions (on/off toggles), install from ZIP | admin |
| **Backups** | Content backups as ZIP | admin |
| **Settings** | Site, theme, date format, home page, socials, RSS/Sitemap, cache, photo optimization | admin |
| **Profile** *(in the sidebar footer)* | Your name, email, password | everyone |

**Search and navigate (⌘K / Ctrl+K)** — the jump bar at the top: instant navigation
to any post, page or action by name.

## Overview (dashboard)

The landing screen after login. At the top — four counters:

- **Published** — how many articles are live;
- **In progress** — drafts + scheduled (what needs attention);
- **Views · 7 days** — with a trend ↑/↓ against the previous week;
- **Last backup** — how many days ago (turns red if it's been a while).

Below — the **views chart** for the current month, a **top pages** list, and a
**security checklist** (HTTPS, debug off, `install.php` removed, cache on). An author
sees metrics for their own posts only.

The chart has two lines: indigo — **views** (all page opens), teal — **unique
visitors** per day. Hover the cursor and the tooltip shows both numbers for that day.

How uniques are counted: on each visit a hash is taken of the visitor's IP address
and browser, keyed to a secret that **changes every day**; only a truncated hash
lands in the statistics file. Neither the IP nor the page address of the visitor is
stored anywhere, and no cookie is set for the counter — so no cookie banner is needed
for statistics. The flip side: **uniques are counted within a day only**. Someone who
visited you five days in a row is five daily "uniques", not one for the month. Visits
from within an open admin session and search-crawler requests are not counted.

The second line appears once data accumulates: statistics collected before the update
have no uniques yet.

## Your first post

**Posts → New post.** The form is plain fields, no visual builder: a title, the text
in Markdown, and the options on the right. The formatting toolbar at the top offers a
heading style (H1–H4/paragraph), bold/italic/strikethrough, highlight and text color,
clear formatting, inline code and a code block, a link, an image from the media
library, video (YouTube/Vimeo), alignment, lists, a quote, a table and a divider.
Keyboard shortcuts: Ctrl+B/I/K. Syntax details are in the
[Formatting text](#formatting-text) section.

Below the editor — a word and character counter. The **"Preview"** button opens the
post as it would look on the site (in the current theme) without saving it.

**Statuses:** draft, published, sticky (always at the top of the list), scheduled
(published automatically on the given date — a calendar with a date and time picker
appears) and "unlisted" (available by direct link but not in archives/RSS/search —
handy for landing pages). The save button changes its label to match the status:
"Save", "Publish" or "Schedule".

In the posts and pages list, **clicking the title** goes where the material actually
is: a published one (including "unlisted") opens on the site in a new tab, while a
draft and a scheduled one open straight in the editor, because they aren't public yet
and the address would return 404. The pencil button in the "Actions" column always
opens the editor.

**Excerpt break:** the `···` button (or by hand) inserts `<!--more-->` — everything
before this mark becomes the excerpt on the list page if the "Excerpt" field is empty.

**Category** — a dropdown of already-created categories (see the "Categories" section
below); you can't type a value outside the list. **Tags** — comma-separated, created
on the fly; there's no separate tag management.

**Cover** — a file path, or the image-icon button opens the media library. When you
upload a new image (as a cover or into the text) you can **crop and rotate** it — a
free aspect ratio or 1:1 / 4:3 / 16:9. Below is an optional **"Icon"** (image or
emoji): it shows next to the item in the documentation theme's tree.

**The "SEO" tab** — your own title/description for the page, canonical, noindex,
separate Open Graph title/image/description for social networks. Leave them blank and
everything is filled in automatically (the post title, the start of the text, the cover).

**The "Custom fields" tab** — arbitrary key-value pairs, available in the theme via
`$post->custom('key')` (for themes with their own non-standard blocks).

**"Advanced"** (on the main tab) — a custom theme template file (`template`, if the
theme supports several post layouts) and a position for manual sorting (if the
settings use "by position" ordering rather than by date).

> A template name is a **file name without `.php`** and without a path: `post-wide`,
> but not `themes/my/post-wide` and not `../something`. Latin letters, digits, hyphen
> and underscore are allowed. If no such template exists in the theme, the material is
> rendered with the normal template — the site won't break.

A draft is saved in the browser automatically (localStorage) — if the tab closed
before saving, the editor offers to restore the unsaved text next time you open it.

## Formatting text

Post text is **Markdown**. The toolbar buttons insert markup, but you can also write
it by hand. The basics:

| What you want | How to write it (button or by hand) |
|---|---|
| Headings | `## Heading`, `### Subheading` |
| Bold / italic | `**bold**`, `*italic*` |
| Strikethrough | `~~text~~` |
| Highlight | `==text==` |
| Text color | `{red:text}` (red, orange, yellow, green, blue, purple, gray) |
| Lists | `- item` or `1. item` |
| Quote | `> text` |
| Link | `[text](https://…)` |
| Image | `![caption](/media/…)` |
| Inline code | `` `code` `` (single backticks) |
| Code block | wrap the lines in triple backticks |
| Table | rows like `\| A \| B \|` (the button inserts a template) |
| Divider | `---` on its own line |

**Block alignment** — wrap the text:

```
::: center
Centered text
:::
```

(`left`, `center`, `right`, `justify`).

**Video** — just paste a YouTube or Vimeo link on its own line and it turns into a
player.

> All of this is safe syntax: it works for every role, including author. The ready
> styles (colors, highlight, video) are picked up by the theme automatically.

## Pages and menu

**Pages → New page.** The same editor as posts, but without category/tags/cover —
pages are about the menu and site structure ("About", "Contact"), not a chronological
feed.

The **"Show in site menu"** checkbox and the **"Position"** field control the
navigation item in the theme's header. There's an optional **"Icon"** (image/emoji).
Changing the slug of an already-published page automatically creates a 301 redirect
from the old address.

In the `deeno-docs` documentation theme, menu pages are shown **above the sections** —
handy as an intro to the wiki ("Introduction", "Getting started").

Pages are available to the admin and editor roles only.

## Media

**Media → Upload** — drag files in or pick them manually; images are automatically
optimized on upload: scaled down to the maximum side from the settings, compressed,
EXIF rotation is applied and baked into the pixels, GPS metadata is stripped. The
original is not kept separately — you get the optimized version right away.

When uploading a single image, a **crop-and-rotate** window appears (free or 1:1 / 4:3
/ 16:9). A file larger than the server limit (`upload_max_filesize`) is rejected right
away with a clear message — raise the limit on your host or shrink the image.

The library view switches between **grid and list** (the toggle on the right, the
choice is remembered). From the library an image can be inserted into post text (the
toolbar button) or picked into the **cover, icon, OG image** fields (in the editor),
**logo and favicon** (in Settings), or **category icon** — next to the field there's an
image-icon button that opens the library without switching tabs.

This picker window can do almost everything the "Media" page can: each file shows its
**name, size and date**, has its own **grid/list** toggle, a **copy-URL** button
(appears on hover) and uploading a new file — with the button at the bottom or by
**dragging straight into the window**. You deliberately can't delete files from here:
deletion lives only on the "Media" page, so you don't accidentally remove an image
that posts link to.

## Categories

**Categories** — a separate admin section (admin and editor roles). A category is more
than a post label: it has a **Title** (human-readable, shown on the site), a **Slug**
(part of the URL) and a **Description** (text under the heading of the category's
archive page on the site). For the documentation theme a category also has an **Order**
(a number for manually sorting sections) and an **Icon** (image/emoji next to the
section in the tree).

- **New category** — you can create a category in advance, even with no posts (the
  category page on the site shows "No posts yet." rather than a 404).
- **Edit** — changes the title/description; if you change the "Slug" to that of an
  existing category, they **merge** (all posts of both end up under one, and the old
  address gets a 301 redirect).
- **Delete** — the category disappears, posts are **not deleted** but become
  "Uncategorized".

Posts without a section land in the service category **"Uncategorized"** (the site
address is `/posts/…`). You don't need to create it and you can't delete it, so it's
not shown in the category list — but in the "Posts" section such articles are marked
"Uncategorized" and you can filter by them.

A post's category is picked in the editor from a dropdown — you can't create a new
category on the fly from the post field; use the "Categories" section for that.

## Themes

**Themes** — a list of installed themes with previews, one-click activation.
**Install theme** accepts a ZIP archive (it must contain `theme.json` at the root) —
that's how you install a theme someone sent you, without server file access. You can't
delete the active theme or the default theme.

Four themes are bundled:

- **`default`** — the base theme;
- **`journal`** — a magazine style;
- **`deeno-news`** — a light blog theme in deeno's brand style (card grid, sidebar
  menu, dark mode, EN/RU language switch, search);
- **`deeno-docs`** — a **documentation/wiki** theme (see "Documentation / wiki" below).

Plus the **`example`** theme — an empty skeleton with all the markup and classes but no
styling: a handy starting point for your own theme.

To build your own theme, see the step-by-step guide
[THEME-TUTORIAL.md](THEME-TUTORIAL.md) (including a prompt for Claude); the full API
reference is [THEME.md](THEME.md).

## Documentation / wiki (the `deeno-docs` theme)

The **`deeno-docs`** theme turns the site into documentation or a wiki — like the
well-known doc engines: a navigation tree on the left, the article in the center, "On
this page" on the right. You don't need to create separate entities; it all runs on the
familiar model:

- **A section = a category.** Categories become collapsible tree sections.
- **An article = a post** in a category. A section's posts are the items inside it.
- **An intro = static pages.** Menu pages are shown **above the sections** (handy for
  "Introduction", "Getting started"); no pages, no wasted space.

What the theme gives you:

- **A tree on the left** highlighting the current article and collapsing sections.
- **"On this page" on the right** — a heading-based table of contents that highlights
  where you currently are (scroll-spy), with `#` anchors on headings.
- **Breadcrumbs** and **← Back / Next →** navigation between articles.
- Light/dark theme, search, EN/RU language switch — as in `deeno-news`.

**The order of sections and articles** is set in Settings via two lists (see
"Settings"): by alphabet, creation date, modification date, or manually by number. In
"manual" mode a section's number is on the category card, an article's number is in the
post editor.

**Drag-and-drop right on the site.** If you are **logged into the admin**, the tree on
the left becomes draggable: drag an article with the mouse to change its order within a
section or **move it to another section**. Changes are saved immediately (a short tree
highlight — green on success). When moving to another section, the article's old address
automatically starts **redirecting** to the new one (301) — links don't break. Regular
visitors see the tree as-is, without dragging.

The order **within a section** can be changed with the mouse only when Settings use
**"Manual (numbers)"**. With alphabetical or date ordering, deeno computes the order
itself, so there's nothing to arrange by hand — a hint appears if you try. **Moving to
another section always works**, regardless of the ordering.

**Icons.** A post, page and category each have an optional **"Icon"** field (an image
from the media library or an emoji) — shown next to the item in the tree; no icon, no
empty space.

To build your own docs: create category-sections (the "Categories" section), write
article-posts with the right category, optionally add page-intros, and turn on the
`deeno-docs` theme in Settings.

## Plugins

**Plugins** — turn bundled plugins on or off with a toggle, no extra configuration.
Bundled:

- **External links** — open external links in posts in a new tab;
- **Lazy images** — `loading="lazy"` on every image;
- **Reading time** — "≈ N min read" at the top of a post;
- **Table of contents** — if an article has ≥2 headings, adds a clickable list of
  sections at the top;
- **Share buttons** — share links (Telegram/VK/X) at the end of an article.

**+ Plugin** — install your own or a third-party plugin from a ZIP archive (it must
contain `plugin.json`). The trash icon next to a plugin deletes it (files are not
recoverable; if the plugin was enabled, it's turned off automatically).

For writing your own plugins, see [PLUGINS.md](PLUGINS.md).

## Backups

**Settings → Backups → Create backup** — assembles a ZIP archive: posts and pages,
media, users, installed themes, `config.php` and the category/redirect data from
`system/`. The CMS's own code (`admin/`, `system/` except a couple of data files,
`index.php`) is not in the archive — it doesn't change between your content edits and
is updated separately (see [README → Updating](../README.md#updating)).

Download the archive with the "Download" button — it isn't stored anywhere except your
server (and your computer after downloading).

### Manual restore

There's no ready "Restore" button in the interface — if something goes wrong, restore
over FTP/SFTP:

1. Unpack the downloaded `backup-YYYY-MM-DD-HHMMSS.zip` locally.
2. Upload onto the server over the current files, replacing the contents of: `content/`,
   `media/`, `users/`, `themes/`, `config.php`, `system/categories.php`,
   `system/redirects.json` (the last two are in the archive only if they existed at
   backup time).
3. The backup doesn't touch the CMS code (`system/*.php`, `admin/`, `index.php`) — you
   don't need to touch it either, only the items above.
4. Clear `/cache/` (just delete everything inside — it rebuilds itself the next time the
   site is opened).

## Settings

The **Settings** section is open to every role, but they see different things: the
administrator manages the whole site, editor and author see only the "Control panel"
card. All fields are saved by a single form.

**Control panel** — personal settings that concern only your account and affect nothing
on the site:

- **Appearance** — a light or dark admin panel;
- **Panel language** — English or Russian.

The choice is stored in your profile, so it doesn't reset after logout and works the
same on every device you sign in on. It does not affect the site's own language —
that's the "Language" field on the "Site" card.

**Site:** title, **logo** (a path to an image from the library, png/jpg/svg — shown
before the title in the site header and in the admin sidebar; blank means title only),
tagline (a short phrase next to the title in the theme header), description (the SEO
meta description — not the same as the tagline), your own footer text (optional, added
next to the non-removable "Powered by deeno" line), site address, interface language,
timezone, active theme, **date format** (how post dates are shown), **home page** (the
default post feed or a chosen static page at "/"), posts per page, ordering (by date or
manually by position). For the `deeno-docs` documentation theme there are also **section
order** and **article order within a section** (4 options each: creation date,
modification date, alphabet, manual by number). In "manual" mode a section's number is
set on the category card (the "Order" field), an article's number in the post editor
("Advanced" → "Position").

**The `deeno-docs` theme** (documentation/wiki): a tree of sections (categories) and
articles (posts) on the left, "On this page" with the active heading highlighted while
scrolling on the right, breadcrumbs and prev/next navigation. Sections = categories,
articles = posts in them.

**Socials:** links to Telegram, VK, X, YouTube, Instagram, Facebook, GitHub, LinkedIn —
shown as icons in the theme footer. Empty fields aren't displayed.

**Maintenance mode:** temporarily show guests a 503 with your text while you edit the
site (people logged into the admin see the site as usual).

**RSS and Sitemap:** enable `/rss.xml` and `/sitemap.xml`, the number of posts in the
feed.

**External scripts:** the domains of counters and widgets allowed to run on your site —
one per line.

Why this exists. The browser is told in advance to run only your own code on the site:
if an attacker ever slips a foreign script onto a page, it simply won't run. The flip
side is that an analytics counter also counts as foreign code, and without permission it
silently won't work.

How to connect, for example, Google Analytics:

1. type `www.googletagmanager.com` into this field and save the settings;
2. paste the counter code itself into the theme template (usually before `</body>` in
   `layout.php`) — see [THEME.md](THEME.md).

For Yandex.Metrica the domain is `mc.yandex.ru`. If the counter isn't counting, open
the browser console (F12): a "Refused to load the script" message means the domain
isn't added here.

**System:** cache (content index + full-page cache of ready pages), debug mode
(detailed errors — for development only, not for a live site!), photo-optimization
parameters on upload, **favicon** (the browser-tab icon — applied to both the site and
the admin), a default OG image (when a post has none of its own).

Changing the `/admin/` address to a custom one is not done through this menu — see
[README → FAQ](../README.md#faq).

## Users and roles

**Users** (admin only) — create/edit/delete accounts. You can't delete yourself or the
last administrator. Every user has their own **Profile** link (in the sidebar footer) —
to change their name, email, password.

| Section | admin | editor | author |
|---|---|---|---|
| Own posts | ✓ | ✓ | ✓ |
| Others' posts | ✓ | ✓ | — |
| Pages | ✓ | ✓ | — |
| Media | ✓ | ✓ | ✓ |
| Categories | ✓ | ✓ | — |
| Themes | ✓ | — | — |
| Plugins | ✓ | — | — |
| Users | ✓ | — | — |
| Settings | ✓ | — | — |
| Backups | ✓ | — | — |

An author additionally can't insert arbitrary HTML into post text — the content is
rendered in safe mode (XSS protection for non-administrators).
