<?php
declare(strict_types=1);

/**
 * Расширение Parsedown под deeno: безопасные пользовательские конструкции.
 * HTML генерируем МЫ (не пользователь) — поэтому работает и в safe mode для
 * не-админов (у них сырой HTML экранируется, а эти правила — нет):
 *   - выделение   ==текст==            → <mark>
 *   - цвет        {red:текст}          → <span class="c-red">   (палитра фиксирована)
 *   - выравнивание ::: center … :::    → <div class="md-center"> (left|center|right|justify)
 *
 * Видео (строка-ссылка YouTube/Vimeo → iframe) делается пост-обработкой в
 * MarkdownParser, а не здесь.
 */
class Markdown extends Parsedown
{
    public const COLORS = ['red', 'orange', 'yellow', 'green', 'blue', 'purple', 'gray'];
    public const ALIGNS = ['left', 'center', 'right', 'justify'];

    public function __construct()
    {
        // Инлайн: = (выделение), { (цвет)
        $this->InlineTypes['='][] = 'Highlight';
        $this->InlineTypes['{'][] = 'Color';
        $this->inlineMarkerList .= '={';

        // Блок: ::: выравнивание (маркер ':' уже используется таблицами — ставим первым)
        array_unshift($this->BlockTypes[':'], 'FencedDiv');
    }

    // ---------- inline: ==выделение== ----------
    protected function inlineHighlight($Excerpt)
    {
        if (isset($Excerpt['text'][1]) && $Excerpt['text'][1] === '='
            && preg_match('/^==(?=\S)(.+?)(?<=\S)==/', $Excerpt['text'], $m)) {
            return [
                'extent'  => strlen($m[0]),
                'element' => [
                    'name'    => 'mark',
                    'handler' => ['function' => 'lineElements', 'argument' => $m[1], 'destination' => 'elements'],
                ],
            ];
        }
        return null;
    }

    // ---------- inline: {red:текст} ----------
    protected function inlineColor($Excerpt)
    {
        if (preg_match('/^\{([a-z]+):(?=\S)(.+?)(?<=\S)\}/', $Excerpt['text'], $m)
            && in_array($m[1], self::COLORS, true)) {
            return [
                'extent'  => strlen($m[0]),
                'element' => [
                    'name'       => 'span',
                    'attributes' => ['class' => 'c-' . $m[1]],
                    'handler'    => ['function' => 'lineElements', 'argument' => $m[2], 'destination' => 'elements'],
                ],
            ];
        }
        return null;
    }

    // ---------- block: ::: выравнивание … ::: ----------
    protected function blockFencedDiv($Line)
    {
        if (preg_match('/^:::[ ]*([a-z]+)[ ]*$/', $Line['text'], $m)
            && in_array($m[1], self::ALIGNS, true)) {
            return [
                'element' => [
                    'name'       => 'div',
                    'attributes' => ['class' => 'md-' . $m[1]],
                    'handler'    => ['function' => 'linesElements', 'argument' => [], 'destination' => 'elements'],
                ],
            ];
        }
        return null;
    }

    protected function blockFencedDivContinue($Line, $Block)
    {
        if (isset($Block['complete'])) {
            return null;
        }
        if (isset($Block['interrupted'])) {
            $Block['element']['handler']['argument'][] = '';
            unset($Block['interrupted']);
        }
        if (preg_match('/^:::[ ]*$/', $Line['text'])) {
            $Block['complete'] = true;
            return $Block;
        }
        $Block['element']['handler']['argument'][] = $Line['body'];
        return $Block;
    }

    protected function blockFencedDivComplete($Block)
    {
        return $Block;
    }
}
