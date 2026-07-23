<?php
declare(strict_types=1);

/**
 * Генерация /sitemap.xml — Sitemap Protocol 0.9 (раздел 13 ТЗ).
 * Включает published посты и страницы; исключает drafts, unlisted, noindex.
 */
class SitemapManager
{
    public function __construct(
        private array $config,
        private ContentManager $cms,
    ) {}

    public function output(): void
    {
        if (empty($this->config['sitemap_enabled'])) {
            http_response_code(404);
            echo 'Sitemap is disabled.';
            return;
        }

        header('Content-Type: application/xml; charset=UTF-8');

        $cache = new CacheManager($this->config);
        $sig   = 'sitemap:' . $this->cms->contentSignature();
        $xml   = $cache->get('sitemap', $sig);

        if (!is_string($xml)) {
            $xml = $this->build();
            $cache->set('sitemap', $sig, $xml);
        }

        echo $xml;
    }

    private function build(): string
    {
        $siteUrl = rtrim((string)($this->config['site_url'] ?? ''), '/');
        $x = fn(string $s) => htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');

        $out   = [];
        $out[] = '<?xml version="1.0" encoding="UTF-8"?>';
        $out[] = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

        // Посты: только published/sticky и без noindex
        $posts   = array_filter($this->cms->posts(), fn(Post $p) => !$p->seoNoindex);
        $lastmod = '';

        foreach ($posts as $post) {
            $mod = $post->dateModifiedRaw ?: $post->dateRaw;
            if ($mod > $lastmod) $lastmod = $mod;
        }

        // Главная страница
        $out[] = '  <url>';
        $out[] = '    <loc>' . $x($siteUrl . '/') . '</loc>';
        if ($lastmod !== '') {
            $out[] = '    <lastmod>' . $x(date('Y-m-d', strtotime($lastmod) ?: time())) . '</lastmod>';
        }
        $out[] = '  </url>';

        foreach ($posts as $post) {
            $mod = $post->dateModifiedRaw ?: $post->dateRaw;
            $out[] = '  <url>';
            $out[] = '    <loc>' . $x($post->url()) . '</loc>';
            if ($mod !== '') {
                $out[] = '    <lastmod>' . $x(date('Y-m-d', strtotime($mod) ?: time())) . '</lastmod>';
            }
            $out[] = '  </url>';
        }

        // Статичные страницы
        foreach ($this->cms->pages() as $page) {
            if (!in_array($page->status, ['published', 'sticky'], true) || $page->seoNoindex) {
                continue;
            }
            $out[] = '  <url>';
            $out[] = '    <loc>' . $x($siteUrl . '/' . $page->slug . '/') . '</loc>';
            if ($page->dateModifiedRaw !== '') {
                $out[] = '    <lastmod>' . $x(date('Y-m-d', strtotime($page->dateModifiedRaw) ?: time())) . '</lastmod>';
            }
            $out[] = '  </url>';
        }

        $out[] = '</urlset>';
        return implode("\n", $out) . "\n";
    }
}
