<?php

namespace App\Support;

/**
 * Token-set (Jaccard) similarity over normalized text. Pure + deterministic —
 * no DB, no embeddings, no network — so anything built on it is unit-testable
 * in isolation. Catches both verbatim clones and reworded thematic dupes,
 * because normalization strips wording noise (urls/hashtags/punctuation/short +
 * stop words) down to the idea-bearing tokens.
 *
 * This is the single home for the heuristic the recycling detector
 * (ContentFindRecycled) and the Strategist's intra-batch dedup both use — so
 * "same idea, different words" is judged identically everywhere.
 */
final class TextSimilarity
{
    /**
     * Jaccard similarity of two strings' normalized token sets, in [0.0, 1.0].
     * Returns 0.0 when either side has no meaningful tokens (empty / all-stop).
     */
    public static function jaccard(string $a, string $b): float
    {
        $ta = self::tokens($a);
        $tb = self::tokens($b);
        if ($ta === [] || $tb === []) {
            return 0.0;
        }
        $inter = count(array_intersect($ta, $tb));
        $union = count(array_unique(array_merge($ta, $tb)));

        return $union > 0 ? $inter / $union : 0.0;
    }

    /**
     * Normalized, de-duplicated token list: lowercase, strip urls/hashtags/
     * punctuation, drop words ≤3 chars and common stop-words. The residue is
     * the idea-bearing content, so two rewordings of the same point overlap
     * heavily while genuinely different points don't.
     *
     * @return array<int,string>
     */
    public static function tokens(string $s): array
    {
        static $stop = null;
        if ($stop === null) {
            $stop = array_flip(explode(' ', 'the a an and or but if then your you they them their our we us is are was were be been being to of in on for with at by from as that this these those it its not do does did what when how why who which about into more most just like can will youre have has had our'));
        }

        $s = mb_strtolower($s);
        $s = preg_replace('#https?://\S+#', ' ', $s);
        $s = preg_replace('/#\w+/', ' ', $s);
        $s = preg_replace('/[^a-z0-9 ]+/', ' ', $s);
        $s = preg_replace('/\s+/', ' ', trim((string) $s));

        $out = [];
        foreach (explode(' ', (string) $s) as $w) {
            if (mb_strlen($w) > 3 && ! isset($stop[$w])) {
                $out[$w] = true;
            }
        }

        return array_keys($out);
    }
}
