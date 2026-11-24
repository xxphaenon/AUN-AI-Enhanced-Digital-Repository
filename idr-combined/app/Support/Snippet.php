<?php

namespace App\Support;

/**
 * Picks the part of a retrieved chunk worth showing under a search result.
 *
 * Chunks are ~500-word windows, so the start of the best chunk is often the
 * title page or the middle of a sentence. This:
 *   1. skips a title page (everything before an "Abstract" heading),
 *   2. drops a leading sentence fragment,
 *   3. starts at the sentence that mentions the most query words (falling back
 *      to the first sentence when the match was purely by meaning),
 *   4. adds following sentences up to $maxChars.
 */
final class Snippet
{
    private const STOPWORDS = ['the', 'and', 'for', 'with', 'from', 'that', 'this', 'what', 'how', 'does', 'are', 'was',
        'were', 'which', 'who', 'why', 'when', 'where', 'about', 'into', 'using', 'use', 'can', 'any', 'all', 'has', 'have',
        'its', 'their', 'there', 'than', 'then', 'them', 'they', 'not', 'but', 'you', 'your', 'our', 'out', 'on', 'of'];

    /**
     * Thesis section headings. PDF text loses the line break after a heading, so
     * "Conclusion and Recommendations The system…" reads as one sentence; these
     * are removed from the start of a sentence when a capitalised word follows.
     */
    private const HEADINGS = ['Conclusion and Recommendations', 'Conclusions and Recommendations', 'Conclusion and Future Work',
        'Literature Review and Framework', 'Theoretical Framework and Literature', 'Theoretical Framework',
        'Literature Review', 'Related Work', 'Requirements and Design', 'Methodology and Design', 'System Design',
        'Implementation and Testing', 'Results and Discussion', 'Discussion and Conclusion', 'Research Methodology',
        'Statement of the Problem', 'Problem Statement', 'Objectives of the Study', 'Scope of the Study', 'Future Work',
        'Recommendations', 'Introduction', 'Background', 'Methodology', 'Methods', 'Implementation', 'Evaluation',
        'Calibration', 'Results', 'Findings', 'Discussion', 'Conclusions', 'Conclusion', 'Summary', 'Testing', 'Design'];

    public static function make(string $text, string $query, int $maxChars = 320): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        if ($text === '' || mb_strlen($text) <= $maxChars && ! str_contains($text, 'Abstract')) {
            return $text;
        }

        $text = self::skipTitlePage($text);
        $text = self::skipBackMatter($text);
        $text = self::dropLeadingFragment($text);

        $sentences = array_values(array_filter(
            preg_split('/(?<=[.!?])(?<!\bal\.)(?<!\be\.g\.)(?<!\bi\.e\.)\s+(?=[\p{Lu}\d"(\[])/u', $text) ?: [$text],
            fn (string $s) => ! preg_match('/^\d+(\.\d+)*\.?$/', $s),   // bare section numbers like "2."
        ));

        if ($sentences === []) {
            return self::cut($text, $maxChars);
        }

        $sentences = array_map(self::withoutHeading(...), $sentences);

        $start = self::bestSentence($sentences, self::terms($query));
        $snippet = '';

        foreach (array_slice($sentences, $start) as $sentence) {
            $next = $snippet === '' ? $sentence : $snippet.' '.$sentence;

            if (mb_strlen($next) > $maxChars) {
                return $snippet === '' ? self::cut($sentence, $maxChars) : $snippet;
            }

            $snippet = $next;
        }

        return $snippet;
    }

    /** "3.2 Methodology Dataset: 3,560 images…" → "Dataset: 3,560 images…" */
    private static function withoutHeading(string $sentence): string
    {
        static $pattern = null;
        $pattern ??= '/^(?:\d+(?:\.\d+)*\.?\s+)?(?:'
            .implode('|', array_map(fn (string $h) => preg_quote($h, '/'), self::HEADINGS))
            .')\s+(?=\p{Lu}|\d)/u';

        $stripped = preg_replace($pattern, '', $sentence, 1) ?? $sentence;

        return $stripped !== '' ? $stripped : $sentence;
    }

    private static function skipTitlePage(string $text): string
    {
        if (preg_match('/\bAbstract:?\s+(?=\p{Lu})/u', $text, $m, PREG_OFFSET_CAPTURE) && $m[0][1] > 0 && $m[0][1] < 2000) {
            return substr($text, $m[0][1] + strlen($m[0][0]));
        }

        return $text;
    }

    /** Drops a keywords line, page numbers before headings, and a trailing reference list. */
    private static function skipBackMatter(string $text): string
    {
        $text = preg_replace('/\bKeywords?:\s.*?(?:(?=\s\d{1,3}\s+\d+\.\s+\p{Lu})|(?=\s\d+\.\s+\p{Lu})|(?<=\.)\s|$)/u', '', $text) ?? $text;
        $text = preg_replace('/(?<=\s|^)\d{1,3}\s+(?=\d+\.\s+\p{Lu})/u', '', $text) ?? $text;

        if (preg_match('/\s(?:References|Bibliography)\s+(?=\p{Lu})/u', $text, $m, PREG_OFFSET_CAPTURE) && $m[0][1] > 200) {
            $text = substr($text, 0, $m[0][1]);
        }

        return trim($text);
    }

    private static function dropLeadingFragment(string $text): string
    {
        if (preg_match('/^[\p{Lu}\d"(\[]/u', $text)) {
            return $text;
        }

        if (preg_match('/[.!?]["\')\]]?\s+(?=[\p{Lu}])/u', $text, $m, PREG_OFFSET_CAPTURE) && $m[0][1] < 300) {
            $rest = substr($text, $m[0][1] + strlen($m[0][0]));

            return $rest !== '' ? $rest : $text;
        }

        return $text;
    }

    /** @param list<string> $terms */
    private static function bestSentence(array $sentences, array $terms): int
    {
        if ($terms === []) {
            return 0;
        }

        $best = 0;
        $bestHits = 0;

        foreach ($sentences as $i => $sentence) {
            $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($sentence), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $hits = 0;

            foreach ($terms as $term) {
                foreach ($words as $word) {
                    if (str_starts_with($word, $term)) {
                        $hits++;
                        break;
                    }
                }
            }

            if ($hits > $bestHits) {
                [$best, $bestHits] = [$i, $hits];
            }
        }

        return $best;
    }

    /** Query words, lower-cased and crudely stemmed ("farms" → "farm", "saving" → "sav"). */
    private static function terms(string $query): array
    {
        $terms = [];

        foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if (mb_strlen($word) < 3 || in_array($word, self::STOPWORDS, true)) {
                continue;
            }

            $stem = preg_replace('/(ing|ers|er|es|ed|s)$/u', '', $word);
            $terms[] = mb_strlen($stem) >= 3 ? $stem : $word;
        }

        return array_values(array_unique($terms));
    }

    private static function cut(string $text, int $maxChars): string
    {
        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }

        $cut = mb_substr($text, 0, $maxChars);
        $space = mb_strrpos($cut, ' ');

        return rtrim(mb_substr($cut, 0, $space ?: $maxChars), " ,;:").'…';
    }
}
