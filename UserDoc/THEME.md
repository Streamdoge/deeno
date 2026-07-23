# THEME.md — Building deeno themes

A theme is a folder in `/themes/` with plain PHP templates. No templating language,
no build step: you write HTML and insert data through ready-made objects.

> 🚀 Beginners will find it easier to start with the step-by-step guide
> [THEME-TUTORIAL.md](THEME-TUTORIAL.md) — it takes the "copy the `example` theme and
> fill in the CSS" path and even includes a ready prompt for Claude. This file
> (THEME.md) is the detailed reference for the whole API.

## Quick start: a minimal theme

Thanks to inheritance, a theme can consist of three files — everything missing is
taken from the `default` theme:

```
/themes/my-theme/
  theme.json      ← metadata
  layout.php      ← the shell: header, menu, footer
  /assets/
    style.css     ← all the styles
```

`theme.json`:

```json
{
  "name": "my-theme",
  "version": "1.0",
  "author": "You",
  "description": "My first theme"
}
```

Activation: copy the folder into `/themes/` and pick the theme in
**Admin → Settings** (or **Themes**). Install from ZIP: **Admin → Themes → Install
theme** — the archive must contain `theme.json`.

## The full theme layout

| File | Responsibility | Required |
|---|---|---|
| `theme.json` | metadata (name, version, author) | yes |
| `layout.php` | the shared shell; content is included via `require $contentFile` | inherited |
| `index.php` | post list: home, category, tag, search | inherited |
| `post.php` | a single post | inherited |
| `page.php` | a static page | inherited |
| `404.php` | the error page | inherited |
| `assets/style.css` | styles | — |
| `screenshot.png` | preview in the admin | no |

## Template inheritance

For each template (`layout`, `index`, `post`, `page`, `404`) the CMS first looks for
the file in your theme and, if it's missing, takes the same-named file from the
`default` theme (`system/ThemeManager::resolve()`). So the theme in the example above
(`theme.json` + `layout.php` + `style.css`) is already fully working: the post list,
a single post, a page and 404 render with `default`'s templates, but with **your**
`layout.php` and **your** styles — `$theme->asset()` always points to *your* theme's
`/assets/`, `default`'s assets are not mixed in.

## Scripts in a theme and CSP

The public site sends a strict `Content-Security-Policy` (assembled in
`system/Csp.php`), so there are three things worth knowing about scripts in a theme:

- **Your own files work as usual.** `<script src="<?= $theme->asset('main.js') ?>">`
  is allowed — it's your domain.
- **Inline scripts also work, nothing to do.** The engine computes the hash (sha256) of
  every `<script>` without `src` in the ready page and adds it to the policy. That's how
  the dark-theme anti-flicker script lives, for instance: it must run before rendering
  and can't be replaced by an external file. The only implication: such a script's
  content must not change from request to request (don't put random values in it) —
  otherwise the hash is recomputed on every request, and a page from the cache gets the
  wrong one.
- **A foreign domain must be allowed.** A counter, widget or fonts from an outside
  server won't run until the administrator adds the domain in Settings → "External
  scripts". Don't assume they did: if your theme needs an external resource, say so in
  the theme description.

`JSON-LD` (`<script type="application/ld+json">`) needs no hash — the browser doesn't
execute it.

> **Template names are flat.** Template files live in the theme root; nested folders
> are not supported: `post-wide.php` — yes, `partials/card.php` — no. A name may contain
> Latin letters, digits, hyphen and underscore; anything else is rejected by `resolve()`
> which returns `null`. The restriction isn't cosmetic: a template name is set by the
> post author in the "Template" field, and without it one could include an arbitrary PHP
> file outside the theme. Extract shared markup into separate templates in the theme root
> (`card.php`, `header.php`) and include them with a normal `require __DIR__ . '/card.php'`.

You can inherit only part: for example, keep the `default` template for a single post
but write your own `index.php` for lists — just don't put `post.php` in your theme.

## Objects available in templates

### `$site` — information about the site
```php
$site->title        // Site title
$site->tagline      // Tagline — a short phrase next to the title (may be '')
$site->description  // Description — the SEO meta description, not for visual output
$site->footerText   // Your own footer text, optional (may be '')
                    // — output it IN ADDITION to "Powered by deeno",
                    // not instead: that line is non-removable in themes
$site->url          // URL with no trailing slash
$site->language     // 'ru' / 'en'
$site->logo         // Path to the logo image from settings ('' — not set).
                    // If set — show the image next to the title; if '' — title only.
$site->rss          // bool: the RSS feed is enabled in settings. Hide the RSS link when false.
$site->categoryOrder // Section ordering mode (for documentation themes):
$site->articleOrder  // manual | alpha | created | modified. See the helpers below.
$site->social       // Array of socials: [['name'=>'telegram','url'=>'https://…'], …]
                    // Only those filled in settings. Take the icon from SocialIcons::svg($name).
```

**The documentation theme.** The stock `deeno-docs` theme (wiki/docs) builds a "section
= category → article = post" tree and orders it by the site settings with two helpers:
`(new CategoryManager())->ordered($slugs, $site->categoryOrder)` — sorts category slugs,
`ContentManager::orderPostsBy($posts, $site->articleOrder)` — sorts posts. Both accept
the modes `manual` (the "order"/`position` field), `alpha`, `created`, `modified`. The
"On this page" table of contents and scroll-spy are built by the theme on the client from
the `h2/h3` headings.

Logo next to the title (blank — title only):
```php
<a class="logo" href="<?= $e($site->url) ?>/">
  <?php if ($site->logo !== ''): ?><img src="<?= $e($site->logo) ?>" alt=""><?php endif; ?>
  <span><?= $e($site->title) ?></span>
</a>
```

**The favicon** doesn't need to be declared in the theme: `$seo->head()` outputs
`<link rel="icon">` itself from the "Favicon" setting (the same for every theme).

Outputting socials in the footer (icons come from the shared `SocialIcons` helper):
```php
<?php if (!empty($site->social)): ?>
  <ul class="footer-social">
    <?php foreach ($site->social as $s): ?>
      <li><a href="<?= $e($s['url']) ?>" target="_blank" rel="noopener nofollow"
             aria-label="<?= $e($s['name']) ?>"><?= SocialIcons::svg($s['name']) ?></a></li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>
```
Dates are formatted with `$post->date()` — the format comes from the site settings; you
can override it with an argument: `$post->date('Y-m-d')`.

### `$post` — a post or page (in post.php, page.php)
```php
$post->title            // Title
$post->content()        // HTML from Markdown
$post->excerpt()        // HTML preview (up to <!--more--> or the excerpt field)
$post->url()            // The post's full URL
$post->date('d.m.Y')    // Date in the given format
$post->dateModified()   // Modification date
$post->author           // Author
$post->category         // ⚠️ This is a slug, NOT a human-readable name — see
                        // the "CategoryManager" section below if you need the name
$post->tags             // Array of tags
$post->cover            // Cover URL ('' if none) — for og:image and metadata
$post->coverSrc()       // Cover URL for <img src> — with a ?v cache-busting mark
$post->icon             // Navigation icon: image path or emoji, '' if none
$post->hasMore()        // Whether there's a <!--more--> break
$post->custom('key')    // A custom field from the frontmatter
```

> **`cover` or `coverSrc()`?** For an image on the page — `<img src="…">` — use
> `coverSrc()`: it appends a `?v=<file mtime>` mark to the address so that when the
> cover is replaced the browser takes the new version right away instead of showing the
> old one from cache (images in `/media/` are cached for 30 days). For external addresses
> and missing files no mark is added — it's returned as is. Keep the raw `cover` for
> metadata (`og:image`, JSON-LD): a mark isn't needed there, since social networks and
> search engines cache the preview by a stable address. The engine inserts `cover` into
> `<head>` via `$seo` itself; you don't need to do it by hand.

### `$cms` — content access (in any template)
```php
$cms->posts(5)                          // The latest 5 posts
$cms->posts(0, ['category' => 'news'])  // All posts in a category
$cms->posts(0, ['tag' => 'php'])        // By tag
$cms->categories()                      // ['slug' => count, ...] — slugs only,
                                         // not names (see "CategoryManager" below)
$cms->tags()                            // ['tag' => count, ...]
$cms->menuPages()                       // Pages for the menu (by position)
$cms->pages()                           // All static pages
$cms->related($post, 3)                 // Related posts (by tags)
```

### `CategoryManager` — human-readable category names

A category slug (`$post->category`, the keys of `$cms->categories()`, `$category` in
lists) is what ended up in the URL and the post frontmatter: often Latin or a
transliteration, not suitable for showing to the user. A category's title and
description are separate optional metadata, edited in the admin ("Categories") and
stored through `system/CategoryManager.php`. If a theme needs human-readable text, fetch
it yourself — `$post->category` isn't suitable for that:

```php
<?php $categoryManager = new CategoryManager(); ?>

<?php // Category badge on a post (card, post page) ?>
<?php if ($post->category): ?>
  <a href="<?= $e($site->url . '/' . $post->category . '/') ?>">
    <?= $e($categoryManager->get($post->category)['title']) ?>
  </a>
<?php endif; ?>

<?php // A "Categories" menu across all site categories ?>
<ul class="categories-menu">
  <?php foreach ($cms->categories() as $slug => $count): ?>
    <li>
      <a href="<?= $e($site->url . '/' . $slug . '/') ?>">
        <?= $e($categoryManager->get($slug)['title']) ?> (<?= $count ?>)
      </a>
    </li>
  <?php endforeach; ?>
</ul>
```

`get($slug)` always returns `['title', 'description', 'icon', 'position', 'created',
'modified']` (icon — an image path or emoji for navigation) — if a category has no
metadata (no one edited it in the admin), `title` equals the `$slug` itself, so calling
`get()` is safe for any category without extra checks. That's how it's done in all
three stock themes (`default`, `journal`, `deeno-news`) — each template file
(`index.php`, `post.php`) creates its own `new CategoryManager()` instance, like the
`$e` escaping helper: PHP variables from `layout.php` don't "leak" into the included
files by design, each template is self-contained.

For the category's own page (e.g. `/news/`) the CMS already prepares
`$categoryTitle`/`$categoryDescription` itself — no need to reach for `CategoryManager`
manually, see "List variables" below.

### `$theme` — links to the theme's files
```php
$theme->asset('style.css')  // → /themes/my-theme/assets/style.css?v=1784499262
```

A `?v=<file mtime>` cache-buster is added to the address automatically. Thanks to it the
browser picks up the new CSS/JS right after a theme edit, not a day later. **Always link
assets through `$theme->asset()`, not a hardcoded path** — otherwise browsers (Safari
especially stubbornly) will serve the user a stale file and your edits won't arrive. You
don't need to bump the version by hand.

### `$seo` — the SEO block for `<head>` (in layout.php)
```php
<?= $seo->head($post ?? null, $seoCtx ?? []) ?>
```
Outputs the title, description, canonical, Open Graph, Twitter Card, JSON-LD and the RSS
link. Just drop one line into `<head>` — the CMS does the rest.

### List variables (in index.php)
```php
$posts        // Array of posts for the current page (may be empty — a category
              // with no posts, created in the admin, exists too)
$category     // The current category slug (if this is a category page)
$categoryTitle       // Human-readable category name (defaults to the slug itself)
$categoryDescription // Category description, shown under the heading (may be '')
$tag          // The current tag
$searchQuery  // The search query (on /search/)
$page, $total, $perPage  // Pagination
```
A category's title/description are edited in the admin ("Categories") and stored through
`CategoryManager` (`system/CategoryManager.php`) — an optional layer over the post's slug
field, not present on every category.

The CMS provides `$categoryTitle`/`$categoryDescription` ready only for the CURRENT
category page. For a category name **on a post** (a badge on a card, on the post page)
or a list of all categories (a "Categories" menu) — use `CategoryManager` directly, see
the "CategoryManager — human-readable category names" section above.

### Service variables (in post.php / page.php / layout.php)
```php
$isPreview  // true on /preview/... — show a "Preview" banner
$editUrl    // A link to the material's editor if the site is viewed by a user
            // logged into the admin; otherwise null. For guests the page is
            // cached without this variable — no leak into the cache.
$reorder    // ['url'=>…, 'csrf'=>…] if the site is viewed by a logged-in admin/editor;
            // otherwise null. For dragging the arrangement right on the site
            // (see "Drag-and-drop arrangement" below). Guests — null.
```
The recommended pattern is a floating button in layout.php:
```php
<?php if (!empty($editUrl)): ?>
  <a class="edit-fab" href="<?= htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8') ?>">Edit</a>
<?php endif; ?>
```

### Drag-and-drop arrangement on the site (for documentation themes)

If your theme shows an article tree (like `deeno-docs`), a logged-in admin/editor can be
allowed to drag articles with the mouse — reorder within a section and move between
sections. All the theme needs: when `$reorder` is set, mark the tree container and its
items with data attributes and send the changes with JS. The CMS writes
`position`/changes the category and sets a 301 redirect for the old URL itself. The
`$reorder['url']` endpoint requires the CSRF token `$reorder['csrf']` and a live admin
session.

```php
<nav class="tree<?= !empty($reorder) ? ' is-reorderable' : '' ?>"
     <?php if (!empty($reorder)): ?>
     data-reorder-url="<?= $e($reorder['url']) ?>"
     data-reorder-csrf="<?= $e($reorder['csrf']) ?>"<?php endif; ?>>
  <?php foreach ($sections as $slug => $posts): ?>
    <div class="tree__section" data-category="<?= $e($slug) ?>">
      <?php foreach ($posts as $p): ?>
        <a class="tree__link" data-file="<?= $e(basename($p->filePath)) ?>"
           href="<?= $e($p->url()) ?>"><?= $e($p->title) ?></a>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
</nav>
```

The JS collects the sections into `{sections:[{category, posts:[filename,…]}]}` and
sends a `POST` to `data-reorder-url` with the fields `csrf` and `data` (JSON). A ready
DnD example is in `themes/deeno-docs/assets/main.js`.

### Plugin hook points (layout.php)

For plugins that insert code into the page (counters, fonts, widgets — see PLUGINS.md)
to work in your theme, add:

```php
<?= Hooks::filter('site.head', '') ?>   <!-- before </head> -->
<?= Hooks::filter('site.footer', '') ?> <!-- before </body> -->
```

## Content CSS classes

Extended formatting from the editor renders to HTML with fixed classes. For the
constructs to look right, add styles to the theme's CSS (the stock themes already have
them — you can copy from there):

| Editor markup | HTML | What to style |
|---|---|---|
| `==text==` | `<mark>text</mark>` | `mark` — highlight background |
| `{red:text}` | `<span class="c-red">…` | `.c-red … .c-gray` (7 colors: red, orange, yellow, green, blue, purple, gray) — `color` |
| `::: center … :::` | `<div class="md-center">…` | `.md-left`, `.md-center`, `.md-right`, `.md-justify` — `text-align` |
| a video link | `<div class="md-video"><iframe…>` | `.md-video` — a responsive 16:9 container (padding-bottom: 56.25 %), `.md-video iframe` — `position:absolute; inset:0; width/height:100 %` |

Set the colors and highlight background to your theme's palette — the class names are
fixed, the values are free.

## Rules

1. **Escape all output**: `htmlspecialchars($post->title, ENT_QUOTES, 'UTF-8')`. The
   exceptions are `$post->content()` and `$post->excerpt()`: those are ready HTML.
2. You don't need to think about the cache: pages are cached whole, and editing a theme's
   files clears the cache automatically.
3. A theme is executable PHP. Only install themes from trusted sources.

## Dark theme and language switching (optional)

If a theme supports a light/dark scheme or localizes its own strings, there are two
conventions the core knows about:

- **The dark theme is client-side.** Set the attribute `data-theme="dark"` on `<html>`
  from your JS and save the choice in `localStorage` (e.g. `deeno-site-theme`). Against
  flicker — a tiny inline script in `<head>` that reads `localStorage` and sets the
  attribute before rendering. Drive all CSS colors through variables and override them in
  `:root[data-theme="dark"]`. The full-page cache doesn't get in the way here — the theme
  is applied on the client.
- **The theme's interface language is the `deeno_lang` cookie** (`ru`/`en`). The
  full-page cache **distinguishes it**: the RU and EN versions of a page are cached
  separately, so the switch can be server-side (cookie → reload → the theme renders its
  strings in the chosen language). Only the theme's own strings are translated; post and
  page content is stored in one language. See the `deeno-news` theme for an example.

### Browser bar color on mobile

If you build a dark scheme, add a meta tag to `<head>` and update it together with the
theme — otherwise the address bar on a phone will stay light over a dark page:

```html
<meta name="theme-color" content="#f9fafb">
```

The tag goes **before** the anti-flicker inline script so it has something to find; the
script swaps `content` for the dark color. That's how it's done in `deeno-news` and
`deeno-docs`.

**A caveat about Safari 26+:** it **ignores** this tag and colors its bars by the
`background-color` of **fixed and sticky** elements, or, if there are none, by the
`<body>` background; the color is taken at render time, and JS changes don't trigger a
recompute. The practical takeaway for theme authors: **don't build full-screen dimming
backdrops with `position: fixed`** (modals, the mobile-menu overlay) — Safari will mix
their semi-transparent background into the bar and it'll turn gray. Use
`position: absolute`, locking the page while the layer is shown. A ready approach is
`.overlay` and `.is-locked` in `deeno-news`.

One more small thing of the same kind: keep `font-size: 16px` or larger on input fields.
Below that, Safari on iOS auto-zooms the page on focus, and it stays zoomed.

## Example: index.php with every kind of list

`index.php` is responsible for four situations at once — the CMS decides which one by
the variables it passes (see "List variables" above). Check them in this order
(search/tag/category before home):

```php
<?php declare(strict_types=1);
$e = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
?>

<?php if (isset($searchQuery)): ?>
  <h1>Results for "<?= $e($searchQuery) ?>"</h1>
<?php elseif (isset($tag)): ?>
  <h1>Tag: <?= $e($tag) ?></h1>
<?php elseif (isset($category) && $category !== ''): ?>
  <h1><?= $e($categoryTitle ?? $category) ?></h1>
  <?php if (!empty($categoryDescription)): ?>
    <p><?= $e($categoryDescription) ?></p>
  <?php endif; ?>
<?php else: ?>
  <h1><?= $e($site->title) ?></h1>
<?php endif; ?>

<?php if (empty($posts)): ?>
  <p>No posts found.</p>
<?php else: ?>
  <ul>
    <?php foreach ($posts as $post): ?>
      <li>
        <a href="<?= $e($post->url()) ?>"><?= $e($post->title) ?></a>
        <p><?= $post->excerpt() /* already ready HTML, don't escape */ ?></p>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>

<?php if ($total > $perPage): ?>
  <nav>
    <?php for ($i = 1; $i <= (int)ceil($total / $perPage); $i++): ?>
      <a href="<?= $e($site->url . '/page/' . $i . '/') ?>"><?= $i ?></a>
    <?php endfor; ?>
  </nav>
<?php endif; ?>
```

In `post.php`, `$cms->related($post, 3)` is similarly useful — three related posts by
tags, for a "Read also" block at the end of an article.

## Example: a minimal layout.php

```php
<?php declare(strict_types=1); ?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($site->language, ENT_QUOTES, 'UTF-8') ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <?= $seo->head($post ?? null, $seoCtx ?? []) ?>
  <link rel="stylesheet" href="<?= htmlspecialchars($theme->asset('style.css'), ENT_QUOTES, 'UTF-8') ?>">
</head>
<body>
  <header>
    <a href="<?= htmlspecialchars($site->url . '/', ENT_QUOTES, 'UTF-8') ?>">
      <?= htmlspecialchars($site->title, ENT_QUOTES, 'UTF-8') ?>
    </a>
    <nav>
      <?php foreach ($cms->menuPages() as $p): ?>
        <a href="<?= htmlspecialchars($site->url . '/' . $p->slug . '/', ENT_QUOTES, 'UTF-8') ?>">
          <?= htmlspecialchars($p->title, ENT_QUOTES, 'UTF-8') ?>
        </a>
      <?php endforeach; ?>
    </nav>
  </header>

  <main><?php require $contentFile; ?></main>

  <footer>&copy; <?= date('Y') ?> <?= htmlspecialchars($site->title, ENT_QUOTES, 'UTF-8') ?></footer>
</body>
</html>
```
