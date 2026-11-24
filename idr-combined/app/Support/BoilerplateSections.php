<?php

namespace App\Support;

/**
 * Finds the sections of a document that are front matter or a reference list,
 * so the similarity check doesn't count them. Every AUN thesis shares the same
 * title-page, certification and declaration wording, and projects on related
 * topics cite the same textbooks; neither is copying.
 *
 * Works on the ordered ~500-word sections (chunks) stored for search. A section
 * is skipped when at least half of its words are:
 *   - front matter: everything before the "Abstract" heading (title page,
 *     certification, approval, dedication, acknowledgements), looked for in
 *     the first 20% of the document (at least 2 sections);
 *   - a table of contents (dot leaders "......" before page numbers);
 *   - the reference list: from a "References"/"Bibliography" heading in the
 *     second half of the document up to an "Appendix" heading, if any;
 *   - reference-list-like text anywhere: many authors written "Surname, A."
 *     (6+ per 100 words, i.e. mostly a list), which in-text citations never use.
 */
final class BoilerplateSections
{
    private const ABSTRACT = '/\b(?:Abstract|ABSTRACT)\b:?\s+(?=\p{Lu})/u';

    private const REFERENCES = '/\b(?:References|REFERENCES|Bibliography|BIBLIOGRAPHY|Works Cited|WORKS CITED)\b:?\s+(?=\p{Lu})/u';

    /**
     * An author as written in a reference list: "Okafor, A." / "Devlin, J.-W."
     * In-text citations ("Okafor (2019)", "Devlin et al.") never use initials,
     * so a literature review full of citations is not mistaken for references.
     */
    private const REFERENCE_ENTRY = '/\b\p{Lu}[\p{L}\'’-]+,\s+(?:\p{Lu}\.(?:[\s-]?\p{Lu}\.)*)/u';

    private const APPENDIX = '/\b(?:Appendix|APPENDIX|Appendices|APPENDICES)\b/u';

    /**
     * @param  array<int, string>  $texts  section number => text, in document order
     * @return array<int, string> section number => why it is skipped ('front matter' | 'contents' | 'references')
     */
    public static function find(array $texts): array
    {
        ksort($texts);
        $keys = array_keys($texts);
        $count = count($keys);
        $skip = [];

        if ($count === 0) {
            return [];
        }

        // Front matter: whole sections before the abstract, plus the part of the
        // abstract's own section that comes before the heading.
        $frontLimit = min($count, max(2, (int) ceil($count * 0.2)));
        for ($i = 0; $i < $frontLimit; $i++) {
            $text = self::normalise($texts[$keys[$i]]);

            if (preg_match(self::ABSTRACT, $text, $m, PREG_OFFSET_CAPTURE)) {
                for ($j = 0; $j < $i; $j++) {
                    $skip[$keys[$j]] = 'front matter';
                }
                if (self::share(substr($text, 0, $m[0][1]), $text) >= 0.5) {
                    $skip[$keys[$i]] = 'front matter';
                }
                break;
            }
        }

        // Reference list, starting in the second half of the document.
        $inReferences = false;
        for ($i = intdiv($count, 2); $i < $count; $i++) {
            $key = $keys[$i];
            $text = self::normalise($texts[$key]);
            $refStart = null;
            $refEnd = strlen($text);

            // Sections overlap, so the section after the heading may repeat it:
            // the list starts at the heading wherever it appears.
            if (preg_match(self::REFERENCES, $text, $m, PREG_OFFSET_CAPTURE)) {
                $refStart = $m[0][1];
                $inReferences = true;
            } elseif ($inReferences) {
                $refStart = 0;
            }

            if ($refStart === null) {
                continue;
            }

            if (preg_match(self::APPENDIX, $text, $m, PREG_OFFSET_CAPTURE, $refStart)) {
                $refEnd = $m[0][1];
                $inReferences = false;   // appendices are the student's own work again
            }

            if (self::share(substr($text, $refStart, $refEnd - $refStart), $text) >= 0.5) {
                $skip[$key] = 'references';
            }
        }

        // Tables of contents and reference-like text wherever they are.
        foreach ($texts as $key => $raw) {
            if (isset($skip[$key])) {
                continue;
            }
            $text = self::normalise($raw);
            $words = max(1, self::words($text));

            if (preg_match_all('/\.{5,}|…{2,}/u', $text) >= 5) {
                $skip[$key] = 'contents';
            } elseif ((preg_match_all(self::REFERENCE_ENTRY, $text) / $words) * 100 >= 6.0) {
                $skip[$key] = 'references';
            }
        }

        ksort($skip);

        return $skip;
    }

    private static function normalise(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    private static function words(string $text): int
    {
        return $text === '' ? 0 : count(preg_split('/\s+/u', trim($text)) ?: []);
    }

    /** Share of $whole's words that are in $part. */
    private static function share(string $part, string $whole): float
    {
        $total = self::words($whole);

        return $total === 0 ? 0.0 : self::words($part) / $total;
    }
}
