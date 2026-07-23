<?php
declare(strict_types=1);

/**
 * Оглавление: если в статье ≥2 заголовков h2/h3 — добавляет в начало
 * кликабельный список разделов и проставляет заголовкам якоря (id).
 * Slug якоря — через системный Slugger (транслит кириллицы).
 */
Hooks::add('post.content', function (string $html, array $ctx): string {
    if (!preg_match_all('/<(h[23])\b([^>]*)>(.*?)<\/\1>/is', $html, $m, PREG_SET_ORDER)) {
        return $html;
    }
    $valid = array_filter($m, static fn(array $h): bool => trim(strip_tags($h[3])) !== '');
    if (count($valid) < 2) {
        return $html; // мало заголовков — оглавление ни к чему
    }

    $used  = [];
    $items = '';
    foreach ($m as $h) {
        $text = trim(strip_tags($h[3]));
        if ($text === '') {
            continue;
        }
        $base = class_exists('Slugger') ? Slugger::make($text) : '';
        if ($base === '') {
            $base = 'section';
        }
        $id = $base;
        $n  = 2;
        while (isset($used[$id])) {
            $id = $base . '-' . $n++;
        }
        $used[$id] = true;

        // Проставить якорь первому непомеченному вхождению этого заголовка
        $pos = strpos($html, $h[0]);
        if ($pos !== false) {
            $withId = '<' . $h[1] . ' id="' . $id . '"' . $h[2] . '>' . $h[3] . '</' . $h[1] . '>';
            $html = substr_replace($html, $withId, $pos, strlen($h[0]));
        }

        $indent = strtolower($h[1]) === 'h3' ? 'padding-left:1.1em;' : '';
        $items .= '<li style="' . $indent . 'margin:.2em 0"><a href="#' . $id . '"'
            . ' style="color:inherit;text-decoration:none">'
            . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</a></li>';
    }

    $toc = '<nav class="dn-toc" style="margin:0 0 1.8em;padding:1em 1.2em;'
        . 'border:1px solid rgba(128,128,128,.22);border-radius:8px;font-size:.95em">'
        . '<div style="font-weight:600;margin-bottom:.5em;opacity:.75">В этой статье</div>'
        . '<ul style="list-style:none;margin:0;padding:0;line-height:1.6">' . $items . '</ul></nav>';

    return $toc . $html;
});
