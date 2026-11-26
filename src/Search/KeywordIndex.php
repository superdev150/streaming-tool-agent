<?php

declare(strict_types=1);

namespace Showcase\Agent\Search;

/**
 * Small in-memory keyword search, good enough for a few thousand documents without a vector database. In production
 * the same idea searches 31 000 Bible verses in about 100 ms.
 *
 * - tokens are lower-cased, accent-folded ("canción" = "cancion") and lightly stemmed ("syncing" = "sync")
 * - score = Σ idf(term) over the query terms a document contains (rare words matter more), where idf = ln(1 + N / df)
 * - an exact phrase match doubles the score; the title counts twice
 */
final class KeywordIndex
{
    private const STOP = ['a', 'an', 'the', 'and', 'or', 'of', 'to', 'in', 'on', 'for', 'is', 'it', 'my', 'i', 'how',
        'do', 'can', 'what', 'with', 'me', 'you', 'your', 'are', 'be', 'el', 'la', 'los', 'las', 'de', 'y', 'en', 'un',
        'una', 'que', 'mi', 'como', 'por', 'para', 'es', 'se', 'lo'];

    private const FOLD = ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a', 'é' => 'e', 'è' => 'e',
        'ê' => 'e', 'ë' => 'e', 'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o',
        'õ' => 'o', 'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ñ' => 'n', 'ç' => 'c', 'ý' => 'y', 'ÿ' => 'y'];

    /** @var array<string, array{title: string, text: string, terms: array<string, int>, folded: string}> */
    private array $docs = [];

    /** @var array<string, int> term → number of documents containing it */
    private array $df = [];

    /** @param iterable<array{id: string, title: string, text: string}> $documents */
    public function __construct(iterable $documents)
    {
        foreach ($documents as $d) {
            $terms = array_count_values([...self::tokens($d['title']), ...self::tokens($d['title']), ...self::tokens($d['text'])]);
            $this->docs[$d['id']] = [
                'title' => $d['title'],
                'text' => $d['text'],
                'terms' => $terms,
                'folded' => ' ' . implode(' ', self::words($d['title'] . ' ' . $d['text'])) . ' ',
            ];
            foreach (array_keys($terms) as $t) {
                $this->df[$t] = ($this->df[$t] ?? 0) + 1;
            }
        }
    }

    /** @return list<array{id: string, title: string, score: float}> best first */
    public function search(string $query, int $limit = 5): array
    {
        $terms = array_unique(self::tokens($query));
        if (!$terms) {
            return [];
        }
        $n = count($this->docs);
        $phrase = ' ' . implode(' ', self::words($query)) . ' ';

        $hits = [];
        foreach ($this->docs as $id => $doc) {
            $score = 0.0;
            foreach ($terms as $t) {
                if (isset($doc['terms'][$t])) {
                    $score += log(1 + $n / $this->df[$t]) * (1 + log($doc['terms'][$t]));
                }
            }
            if ($score > 0 && count($terms) > 1 && str_contains($doc['folded'], $phrase)) {
                $score *= 2;
            }
            if ($score > 0) {
                $hits[] = ['id' => $id, 'title' => $doc['title'], 'score' => round($score, 3)];
            }
        }
        usort($hits, fn ($a, $b) => $b['score'] <=> $a['score'] ?: strcmp($a['id'], $b['id']));
        return array_slice($hits, 0, max(1, $limit));
    }

    /** @return list<string> */
    public static function tokens(string $text): array
    {
        $out = [];
        foreach (self::words($text) as $w) {
            if (!in_array($w, self::STOP, true) && mb_strlen($w) > 1) {
                $out[] = self::stem($w);
            }
        }
        return $out;
    }

    /** @return list<string> lower-cased, accent-free words */
    public static function words(string $text): array
    {
        $text = mb_strtolower($text);
        if (class_exists(\Normalizer::class)) {
            $text = preg_replace('/\p{Mn}+/u', '', (string) \Normalizer::normalize($text, \Normalizer::FORM_D));
        } else {
            // Without ext-intl: the Latin letters used by the languages the app supports
            $text = strtr($text, self::FOLD);
        }
        preg_match_all('/[\p{L}\p{N}]+/u', (string) $text, $m);
        return $m[0];
    }

    /** Deliberately light English stemming (verb endings and plurals), never below 3 letters. */
    public static function stem(string $w): string
    {
        $cut = fn (int $n) => mb_strlen($w) - $n >= 3 ? mb_substr($w, 0, -$n) : $w;
        return match (true) {
            str_ends_with($w, 'ing') => $cut(3),
            str_ends_with($w, 'ed') => $cut(2),
            (bool) preg_match('/(s|x|z|ch|sh)es$/u', $w) => $cut(2),   // boxes, wishes
            str_ends_with($w, 's') && !str_ends_with($w, 'ss') => $cut(1),   // notes, but not "class"
            default => $w,
        };
    }
}
