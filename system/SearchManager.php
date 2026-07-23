<?php
declare(strict_types=1);

/**
 * Поиск по сайту: заголовки, теги, категория, анонс, текст поста.
 * Ранжирование: заголовок > теги > категория > анонс > тело.
 * Каждое слово запроса обязано найтись хотя бы в одном поле.
 */
class SearchManager
{
    private const MIN_QUERY = 2;

    public function __construct(private ContentManager $cms) {}

    /** @return Post[] отсортированные по релевантности */
    public function search(string $query, int $limit = 50): array
    {
        $query = trim($query);
        if (mb_strlen($query) < self::MIN_QUERY) return [];

        $terms = preg_split('/\s+/u', mb_strtolower($query, 'UTF-8')) ?: [];
        $terms = array_values(array_filter($terms, fn($t) => mb_strlen($t) >= self::MIN_QUERY));
        if ($terms === []) return [];

        $scored = [];
        foreach ($this->cms->posts() as $post) {
            $score = $this->score($post, $terms);
            if ($score > 0) {
                $scored[] = ['post' => $post, 'score' => $score];
            }
        }

        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
        return array_map(fn($s) => $s['post'], array_slice($scored, 0, $limit));
    }

    // ----------------------------------------------------------------

    /** 0 — если хотя бы одно слово запроса не найдено нигде */
    private function score(Post $post, array $terms): int
    {
        $title    = mb_strtolower($post->title, 'UTF-8');
        $tags     = mb_strtolower(implode(' ', $post->tags), 'UTF-8');
        $category = mb_strtolower($post->category, 'UTF-8');
        $excerpt  = mb_strtolower($post->excerpt_raw, 'UTF-8');
        $body     = null; // лениво: тело читается с диска только если нужно

        $total = 0;
        foreach ($terms as $term) {
            $termScore = 0;
            if (str_contains($title, $term))    $termScore += 10;
            if (str_contains($tags, $term))     $termScore += 5;
            if (str_contains($category, $term)) $termScore += 3;
            if (str_contains($excerpt, $term))  $termScore += 2;

            if ($termScore === 0) {
                $body ??= mb_strtolower($post->rawBody(), 'UTF-8');
                if (str_contains($body, $term)) $termScore += 1;
            }

            if ($termScore === 0) return 0; // слово не найдено — пост не подходит
            $total += $termScore;
        }
        return $total;
    }
}
