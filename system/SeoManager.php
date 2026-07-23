<?php
declare(strict_types=1);

/**
 * Генерация SEO-блока для <head>: title, description, canonical, robots,
 * Open Graph, Twitter Card, JSON-LD (раздел 12 ТЗ).
 * Автогенерация (12.1): title = post.title | site.title,
 * description = excerpt (160 симв.), og:image = cover или site.og_image.
 */
class SeoManager
{
    private string $siteTitle;
    private string $siteDescription;
    private string $siteUrl;
    private string $defaultOgImage;
    private string $favicon;
    private bool   $rssEnabled;

    public function __construct(array $config)
    {
        $this->siteTitle       = (string)($config['site_title'] ?? '');
        $this->siteDescription = (string)($config['site_description'] ?? '');
        $this->siteUrl         = rtrim((string)($config['site_url'] ?? ''), '/');
        $this->defaultOgImage  = (string)($config['og_image'] ?? '');
        $this->favicon         = (string)($config['favicon'] ?? '');
        $this->rssEnabled      = !empty($config['rss_enabled']);
    }

    /**
     * Полный SEO-блок для страницы. $post — пост/страница или null (главная, списки).
     * $ctx: ['title' => заголовок списка, 'noindex' => bool]
     */
    public function head(?Post $post = null, array $ctx = []): string
    {
        $isPost      = $post !== null;
        $title       = $isPost
            ? ($post->seoTitle !== '' ? $post->seoTitle : $post->title . ' / ' . $this->siteTitle)
            : (isset($ctx['title']) && $ctx['title'] !== '' ? $ctx['title'] . ' / ' . $this->siteTitle : $this->siteTitle);
        $description = $isPost
            ? ($post->seoDescription !== '' ? $post->seoDescription : $this->excerptText($post))
            : ((string)($ctx['description'] ?? '') !== '' ? (string)$ctx['description'] : $this->siteDescription);
        $url       = $this->currentUrl();
        $canonical = $isPost && $post->canonical !== '' ? $post->canonical : $url;
        $noindex   = ($isPost && $post->seoNoindex) || !empty($ctx['noindex']);

        $ogTitle = $isPost && $post->ogTitle !== '' ? $post->ogTitle : $title;
        $ogDesc  = $isPost && $post->ogDescription !== '' ? $post->ogDescription : $description;
        $ogImage = $isPost && $post->ogImage !== '' ? $post->ogImage
                 : ($isPost && $post->cover !== '' ? $post->cover : $this->defaultOgImage);
        $ogImage = $this->absoluteUrl($ogImage);

        $e = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        $lines   = [];
        $lines[] = '<title>' . $e($title) . '</title>';
        $lines[] = '<meta name="description" content="' . $e(mb_substr($description, 0, 160)) . '">';
        $lines[] = '<meta name="robots" content="' . ($noindex ? 'noindex,nofollow' : 'index,follow') . '">';
        $lines[] = '<link rel="canonical" href="' . $e($canonical) . '">';
        if ($this->favicon !== '') {
            $lines[] = '<link rel="icon" href="' . $e($this->favicon) . '">';
        }

        // Open Graph
        $lines[] = '<meta property="og:title" content="' . $e($ogTitle) . '">';
        $lines[] = '<meta property="og:description" content="' . $e(mb_substr($ogDesc, 0, 200)) . '">';
        if ($ogImage !== '') {
            $lines[] = '<meta property="og:image" content="' . $e($ogImage) . '">';
        }
        $lines[] = '<meta property="og:url" content="' . $e($url) . '">';
        $lines[] = '<meta property="og:type" content="' . ($isPost ? 'article' : 'website') . '">';
        $lines[] = '<meta property="og:site_name" content="' . $e($this->siteTitle) . '">';

        // Twitter Card
        $lines[] = '<meta name="twitter:card" content="summary_large_image">';
        $lines[] = '<meta name="twitter:title" content="' . $e($ogTitle) . '">';
        $lines[] = '<meta name="twitter:description" content="' . $e(mb_substr($ogDesc, 0, 200)) . '">';
        if ($ogImage !== '') {
            $lines[] = '<meta name="twitter:image" content="' . $e($ogImage) . '">';
        }

        // RSS
        if ($this->rssEnabled) {
            $lines[] = '<link rel="alternate" type="application/rss+xml" title="' . $e($this->siteTitle) . '" href="' . $e($this->siteUrl . '/rss.xml') . '">';
        }

        // JSON-LD
        $lines[] = $this->jsonLd($post, $title, $description, $url, $ogImage);

        return implode("\n  ", $lines);
    }

    // ----------------------------------------------------------------

    private function jsonLd(?Post $post, string $title, string $description, string $url, string $image): string
    {
        if ($post !== null) {
            $data = [
                '@context'      => 'https://schema.org',
                '@type'         => 'BlogPosting',
                'headline'      => $post->title,
                'description'   => mb_substr($description, 0, 160),
                'url'           => $url,
                'datePublished' => $post->dateRaw,
                'dateModified'  => $post->dateModifiedRaw,
                'author'        => ['@type' => 'Person', 'name' => $post->author ?: $this->siteTitle],
                'publisher'     => ['@type' => 'Organization', 'name' => $this->siteTitle],
            ];
            if ($image !== '') {
                $data['image'] = $image;
            }
        } else {
            $data = [
                '@context'    => 'https://schema.org',
                '@type'       => 'WebSite',
                'name'        => $this->siteTitle,
                'description' => $this->siteDescription,
                'url'         => $this->siteUrl ?: $url,
            ];
        }

        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        // Защита от закрытия тега внутри JSON
        $json = str_replace('</', '<\/', (string)$json);
        return '<script type="application/ld+json">' . $json . '</script>';
    }

    /** Текст для description: excerpt поста без HTML, до 160 символов */
    private function excerptText(Post $post): string
    {
        if ($post->excerpt_raw !== '') {
            return mb_substr($post->excerpt_raw, 0, 160);
        }
        $text = trim(strip_tags($post->excerpt()));
        $text = (string)preg_replace('/\s+/u', ' ', $text);
        return mb_substr($text, 0, 160);
    }

    private function currentUrl(): string
    {
        $path = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
        return $this->siteUrl . $path;
    }

    private function absoluteUrl(string $url): string
    {
        if ($url === '' || str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }
        return $this->siteUrl . '/' . ltrim($url, '/');
    }
}
