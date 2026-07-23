<?php
declare(strict_types=1);

/**
 * Генерация /rss.xml — RSS 2.0 (раздел 13 ТЗ).
 * Кэшируется через CacheManager, инвалидация — по подписи контента.
 */
class RssManager
{
    public function __construct(
        private array $config,
        private ContentManager $cms,
    ) {}

    public function output(): void
    {
        if (empty($this->config['rss_enabled'])) {
            http_response_code(404);
            echo 'RSS is disabled.';
            return;
        }

        header('Content-Type: application/rss+xml; charset=UTF-8');

        $cache = new CacheManager($this->config);
        $sig   = 'rss:' . $this->cms->contentSignature();
        $xml   = $cache->get('rss', $sig);

        if (!is_string($xml)) {
            $xml = $this->build();
            $cache->set('rss', $sig, $xml);
        }

        echo $xml;
    }

    private function build(): string
    {
        $siteUrl   = rtrim((string)($this->config['site_url'] ?? ''), '/');
        $title     = (string)($this->config['site_title'] ?? '');
        $desc      = (string)($this->config['site_description'] ?? '');
        $lang      = (string)($this->config['language'] ?? 'ru');
        $limit     = max(1, (int)($this->config['rss_items'] ?? 20));

        $posts = $this->cms->posts($limit);

        $x = fn(string $s) => htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');

        $out   = [];
        $out[] = '<?xml version="1.0" encoding="UTF-8"?>';
        $out[] = '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">';
        $out[] = '<channel>';
        $out[] = '  <title>' . $x($title) . '</title>';
        $out[] = '  <link>' . $x($siteUrl . '/') . '</link>';
        $out[] = '  <description>' . $x($desc) . '</description>';
        $out[] = '  <language>' . $x($lang) . '</language>';
        $out[] = '  <lastBuildDate>' . date(DATE_RSS) . '</lastBuildDate>';
        $out[] = '  <generator>deeno</generator>';
        $out[] = '  <atom:link href="' . $x($siteUrl . '/rss.xml') . '" rel="self" type="application/rss+xml"/>';

        foreach ($posts as $post) {
            $url    = $post->url();
            $pubTs  = strtotime($post->dateRaw ?: 'now') ?: time();
            // Описание — HTML-превью в CDATA (CDATA не может содержать "]]>")
            $body   = str_replace(']]>', ']]&gt;', $post->excerpt());

            $out[] = '  <item>';
            $out[] = '    <title>' . $x($post->title) . '</title>';
            $out[] = '    <link>' . $x($url) . '</link>';
            $out[] = '    <guid isPermaLink="true">' . $x($url) . '</guid>';
            $out[] = '    <pubDate>' . date(DATE_RSS, $pubTs) . '</pubDate>';
            if ($post->category !== '') {
                $out[] = '    <category>' . $x($post->category) . '</category>';
            }
            $out[] = '    <description><![CDATA[' . $body . ']]></description>';
            $out[] = '  </item>';
        }

        $out[] = '</channel>';
        $out[] = '</rss>';

        return implode("\n", $out) . "\n";
    }
}
