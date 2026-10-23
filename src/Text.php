<?php

declare(strict_types=1);

namespace Plagx\Preflight;

/**
 * Text structure helpers: words, lines, paragraphs, headings, sentences, syllables.
 * Offsets here are byte offsets into the UTF-8 text; Preflight converts them to
 * UTF-16 code units (the JavaScript reference) for output.
 *
 * @internal
 */
final class Text
{
    /** JavaScript's \s, which PCRE does not match exactly. Use inside a character class. */
    public const WS = '\t\n\x{0B}\f\r \x{a0}\x{1680}\x{2000}-\x{200a}\x{2028}\x{2029}\x{202f}\x{205f}\x{3000}\x{feff}';

    private const WORD_RE = "/[\\p{L}\\p{M}0-9]+(?:['\\x{2019}-][\\p{L}\\p{M}0-9]+)*/u";

    private const ABBREVIATIONS = [
        'al', 'approx', 'cf', 'co', 'dept', 'dr', 'ed', 'eds', 'eg', 'et', 'etc', 'fig', 'figs', 'ie',
        'inc', 'jr', 'ltd', 'mr', 'mrs', 'ms', 'no', 'nos', 'p', 'pp', 'prof', 'sr', 'st', 'vol', 'vols', 'vs',
    ];

    public const REFERENCE_HEADINGS = [
        'references', 'reference list', 'bibliography', 'works cited', 'work cited', 'sources', 'literature cited',
    ];

    /** The characters text.js treats as space when trimming. */
    private const TRIM = " \t\r\n\f\v";

    /** @return list<array{text: string, start: int, end: int}> */
    public static function words(string $text): array
    {
        preg_match_all(self::WORD_RE, $text, $m, PREG_OFFSET_CAPTURE);
        $out = [];
        foreach ($m[0] as [$w, $at]) {
            $out[] = ['text' => $w, 'start' => $at, 'end' => $at + strlen($w)];
        }
        return $out;
    }

    public static function countWords(string $text): int
    {
        return preg_match_all(self::WORD_RE, $text);
    }

    /** @return list<int> */
    public static function lineStarts(string $text): array
    {
        $starts = [0];
        $at = -1;
        while (($at = strpos($text, "\n", $at + 1)) !== false) {
            $starts[] = $at + 1;
        }
        return $starts;
    }

    /** JavaScript String.prototype.trim(). */
    public static function jsTrim(string $s): string
    {
        return preg_replace('/^[' . self::WS . ']+|[' . self::WS . ']+\z/u', '', $s);
    }

    /** Moves $s forward and $e back past text.js space characters (space, tabs, line breaks, NBSP). */
    private static function trimRange(string $text, int &$s, int &$e): void
    {
        while ($s < $e) {
            if (strpos(self::TRIM, $text[$s]) !== false) {
                $s += 1;
            } elseif ($text[$s] === "\xC2" && $s + 1 < $e && $text[$s + 1] === "\xA0") {
                $s += 2;
            } else {
                break;
            }
        }
        while ($e > $s) {
            if (strpos(self::TRIM, $text[$e - 1]) !== false) {
                $e -= 1;
            } elseif ($e - 2 >= $s && $text[$e - 2] === "\xC2" && $text[$e - 1] === "\xA0") {
                $e -= 2;
            } else {
                break;
            }
        }
    }

    public static function normalizeHeading(string $s): string
    {
        $s = self::jsTrim($s);
        $s = preg_replace('/^#+[' . self::WS . ']*/u', '', $s);
        $s = preg_replace('/^(?:\d+(?:\.\d+)*\.?|[ivxlc]+\.)[' . self::WS . ']+/ui', '', $s);
        $s = preg_replace('/[' . self::WS . ']*:\z/u', '', $s);
        $s = preg_replace('/[' . self::WS . ']+/u', ' ', $s);
        return mb_strtolower($s, 'UTF-8');
    }

    /** @param array{text: string} $p */
    private static function isHeading(array $p): bool
    {
        $t = $p['text'];
        if (str_contains($t, "\n")) {
            return false;
        }
        if (str_starts_with($t, '#')) {
            return true;
        }
        if (in_array(self::normalizeHeading($t), self::REFERENCE_HEADINGS, true)) {
            return true;
        }
        $n = self::countWords($t);
        if ($n < 1 || $n > 10) {
            return false;
        }
        if (preg_match('/[.!?,;"\'\x{201d}\x{2019})\]]\z/u', $t)) {
            return false;
        }
        return (bool) preg_match('/^[\p{Lu}0-9]/u', $t);
    }

    /** @return list<array{text: string, start: int, end: int, heading: bool}> */
    public static function paragraphs(string $text): array
    {
        $out = [];
        $starts = self::lineStarts($text);
        $blockStart = -1;
        $blockEnd = -1;
        $flush = function () use (&$out, &$blockStart, &$blockEnd, $text): void {
            if ($blockStart < 0) {
                return;
            }
            $s = $blockStart;
            $e = $blockEnd;
            self::trimRange($text, $s, $e);
            $p = ['text' => substr($text, $s, $e - $s), 'start' => $s, 'end' => $e];
            $p['heading'] = self::isHeading($p);
            $out[] = $p;
            $blockStart = -1;
        };
        $count = count($starts);
        for ($i = 0; $i < $count; $i += 1) {
            $ls = $starts[$i];
            $le = $i + 1 < $count ? $starts[$i + 1] - 1 : strlen($text);
            $line = substr($text, $ls, $le - $ls);
            if (preg_match('/^[ \t\r\f\x{0B}\x{a0}]*\z/u', $line)) {
                $flush();
            } else {
                if ($blockStart < 0) {
                    $blockStart = $ls;
                }
                $blockEnd = $le;
            }
        }
        $flush();
        return $out;
    }

    /**
     * Splits a document into body and reference list.
     *
     * @return array{paragraphs: list<array>, body: list<array>, bodyEnd: int, referenceHeading: ?array, entries: list<array>}
     */
    public static function structure(string $text): array
    {
        $paras = self::paragraphs($text);
        $idx = -1;
        foreach ($paras as $i => $p) {
            if ($p['heading'] && in_array(self::normalizeHeading($p['text']), self::REFERENCE_HEADINGS, true)) {
                $idx = $i;
                break;
            }
        }
        $body = $idx < 0 ? $paras : array_slice($paras, 0, $idx);
        $heading = $idx < 0 ? null : $paras[$idx];
        $entries = [];
        if ($heading !== null) {
            $starts = self::lineStarts($text);
            $count = count($starts);
            for ($i = 0; $i < $count; $i += 1) {
                $ls = $starts[$i];
                if ($ls <= $heading['end']) {
                    continue;
                }
                $le = $i + 1 < $count ? $starts[$i + 1] - 1 : strlen($text);
                $s = $ls;
                $e = $le;
                self::trimRange($text, $s, $e);
                if ($e > $s) {
                    $entries[] = ['text' => substr($text, $s, $e - $s), 'start' => $s, 'end' => $e];
                }
            }
        }
        return [
            'paragraphs' => $paras,
            'body' => $body,
            'bodyEnd' => $heading === null ? strlen($text) : $heading['start'],
            'referenceHeading' => $heading,
            'entries' => $entries,
        ];
    }

    /** @return list<array{text: string, start: int, end: int}> */
    private static function sentencesIn(string $s, int $base): array
    {
        $out = [];
        $start = 0;
        $push = function (int $end) use (&$out, &$start, $s, $base): void {
            $a = $start;
            $b = $end;
            self::trimRange($s, $a, $b);
            if ($b > $a) {
                $out[] = ['text' => substr($s, $a, $b - $a), 'start' => $base + $a, 'end' => $base + $b];
            }
            $start = $end;
        };
        preg_match_all('/[.!?]+["\'\x{201d}\x{2019})\]]*(?=[' . self::WS . ']|\z)/u', $s, $m, PREG_OFFSET_CAPTURE);
        foreach ($m[0] as [$mark, $at]) {
            $marks = preg_replace('/["\'\x{201d}\x{2019})\]]+\z/u', '', $mark);
            if (str_starts_with($marks, '..')) {
                continue; // an ellipsis
            }
            if ($marks === '.') {
                // A lone period after an abbreviation, an initial or a dotted form (U.S.) does not end a sentence.
                if (preg_match('/(?:^|[' . self::WS . '(])([\p{L}.]+)\z/u', substr($s, 0, $at), $b)) {
                    $w = mb_strtolower($b[1], 'UTF-8');
                    if (in_array($w, self::ABBREVIATIONS, true) || preg_match('/^\p{L}\z/u', $w) || str_contains($w, '.')) {
                        continue;
                    }
                }
            }
            $push($at + strlen($mark));
        }
        $push(strlen($s));
        return $out;
    }

    /** @return list<array{text: string, start: int, end: int}> */
    public static function splitSentences(string $text): array
    {
        $out = [];
        foreach (self::paragraphs($text) as $p) {
            if ($p['heading']) {
                $out[] = ['text' => $p['text'], 'start' => $p['start'], 'end' => $p['end']];
            } else {
                array_push($out, ...self::sentencesIn($p['text'], $p['start']));
            }
        }
        return $out;
    }

    /** @param array{text: string, start: int} $p */
    public static function paragraphSentences(array $p): array
    {
        return self::sentencesIn($p['text'], $p['start']);
    }

    public static function syllables(string $word): int
    {
        $w = preg_replace('/[^a-z]/', '', mb_strtolower($word, 'UTF-8'));
        if ($w === '') {
            return 0;
        }
        if (strlen($w) <= 3) {
            return 1;
        }
        $w = preg_replace('/(?:[^laeiouy]es|ed|[^laeiouy]e)\z/', '', $w);
        $w = preg_replace('/^y/', '', $w);
        $n = preg_match_all('/[aeiouy]+/', $w);
        return $n > 0 ? $n : 1;
    }
}
