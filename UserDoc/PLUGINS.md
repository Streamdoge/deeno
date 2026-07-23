# PLUGINS.md — deeno plugins

A plugin is a folder in `/plugins/` with two files:

```
/plugins/
  my-plugin/
    plugin.json   ← metadata (shown in the admin panel)
    plugin.php    ← code: attaches listeners to hooks
```

Install via **Plugins → + Plugin**; the ZIP archive must contain `plugin.json`
(in the archive root or in a single folder). Or manually: copy the folder into
`/plugins/` over FTP. Enable and disable it in the same place, in the plugin list
(or via ⌘K). The list of enabled plugins is stored in `config.json` →
`"plugins": ["my-plugin", ...]`. The page cache is cleared automatically — both
when a plugin is toggled and when its code is edited.

> ⚠️ A plugin is executable PHP code with full CMS privileges.
> Install plugins only from trusted sources — the same rule as for themes.

## plugin.json

```json
{
  "name": "My name",
  "description": "One sentence: what the plugin does.",
  "version": "1.0",
  "author": "You"
}
```

## plugin.php

The file is simply executed on CMS startup (both on the site and in the admin
panel). It usually attaches one or two listeners via `Hooks::add()`:

```php
<?php
declare(strict_types=1);

Hooks::add('post.content', function (string $html, array $ctx): string {
    // $ctx['post'] — the Post object (title, slug, category, tags…)
    return $html . '<p>Made it to the end? Thanks!</p>';
});
```

## Hooks

### Filters — change a value

The listener receives a value and a context and returns a new value
(returning `null` = leave it unchanged).

| Filter | Value | Context | When |
|---|---|---|---|
| `post.content` | article HTML | `['post' => Post]` | When a theme renders a post/page |
| `site.head` | HTML string | — | The theme inserts it into `<head>` |
| `site.footer` | HTML string | — | The theme inserts it before `</body>` |

Example — an analytics counter in the footer:

```php
Hooks::add('site.footer', function (string $html): string {
    return $html . '<script src="/media/counter.js" defer></script>';
});
```

### Events — notify that something happened

The listener receives a payload and returns nothing.

| Event | Payload | When |
|---|---|---|
| `post.saved` | `['filename' => ..., 'type' => post\|page]` | After saving from the admin |
| `post.deleted` | `['file' => ..., 'type' => ...]` | After deletion |
| `media.uploaded` | `['url' => ..., 'path' => ...]` | After a file is uploaded |

Example — ping a search engine on publish:

```php
Hooks::add('post.saved', function (array $p): void {
    @file_get_contents('https://example.com/ping?updated=' . urlencode($p['filename']));
});
```

## Rules

1. **Escape everything you output** from post data: `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`.
2. Never output anything directly (`echo`) — only through a filter's return value, or you'll break the full-page cache and headers.
3. Heavy work in `post.saved`/`media.uploaded` slows down saving — move it out or make it lazy.
4. One plugin, one job. See the examples in `/plugins/`: `reading-time` (a content filter), `external-links` (HTML parsing), `lazy-images` (a minimal filter), `table-of-contents` (a heading-based TOC), `share-buttons` (share buttons + reusing `SocialIcons`).

## For theme authors

For plugins using `site.head`/`site.footer` to work in your theme,
add this to `layout.php`:

```php
<?= Hooks::filter('site.head', '') ?>   <!-- before </head> -->
<?= Hooks::filter('site.footer', '') ?> <!-- before </body> -->
```

`post.content` works in any theme automatically — the filter is applied
inside `$post->content()`.
