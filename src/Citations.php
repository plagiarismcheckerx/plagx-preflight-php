<?php

declare(strict_types=1);

namespace Plagx\Preflight;

/**
 * In-text citations (APA author-date, MLA author-page) and reference list entries.
 * Offsets are byte offsets.
 *
 * @internal
 */
final class Citations
{
    public const STYLES = ['apa', 'mla'];

    private const YEAR = '(?:\d{4}[a-z]?|n\.d\.)';
    private const NAME = "\\p{Lu}[\\p{L}'\\x{2019}-]+";
    private const NOT_AUTHORS = [
        'appendix', 'chapter', 'chapters', 'fig', 'figure', 'figures', 'line', 'lines', 'p', 'page', 'pages', 'part',
        'phase', 'pp', 'section', 'see', 'step', 'table', 'tables', 'version', 'vol', 'volume',
    ];

    private static function lower(string $s): string
    {
        return str_replace("\u{2019}", "'", mb_strtolower($s, 'UTF-8'));
    }

    private static function firstWord(string $s): string
    {
        return preg_match("/\\p{L}[\\p{L}'\\x{2019}-]*/u", $s, $m) ? self::lower($m[0]) : '';
    }

    /**
     * Splits the inside of a parenthesis on ";" and yields [trimmed part, byte start].
     *
     * @return list<array{0: string, 1: int}>
     */
    private static function parts(string $inner, int $offset): array
    {
        $out = [];
        foreach (explode(';', $inner) as $part) {
            $lead = strlen($part) - strlen(preg_replace('/^[' . Text::WS . ']+/u', '', $part));
            $out[] = [Text::jsTrim($part), $offset + $lead];
            $offset += strlen($part) + 1;
        }
        return $out;
    }

    private static function apa(string $text): array
    {
        $y = self::YEAR;
        $ws = Text::WS;
        $out = [];
        // Parenthetical: (Smith, 2020), (Smith & Lee, 2020, p. 4; Brown, 2018a)
        preg_match_all("/\\(([^()]*?{$y}[^()]*)\\)/u", $text, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($all as $m) {
            foreach (self::parts($m[1][0], $m[1][1]) as [$trimmed, $pStart]) {
                $skip = preg_match("/^(?:e\\.g\\.|see also|see|cf\\.),?[{$ws}]+/ui", $trimmed, $px) ? strlen($px[0]) : 0;
                $rest = substr($trimmed, $skip);
                // JavaScript "." does not match line terminators.
                $dot = "[^\\n\\r\\x{2028}\\x{2029}]";
                if (!preg_match("/^({$dot}*?\\p{L}{$dot}*?),?[{$ws}]+({$y}(?:,[{$ws}]*{$y})*)(?=\\z|,)/u", $rest, $am)) {
                    continue;
                }
                $author = $am[1];
                if (!preg_match('/^\p{Lu}/u', $author) || in_array(self::firstWord($author), self::NOT_AUTHORS, true)) {
                    continue;
                }
                foreach (preg_split("/,[{$ws}]*/u", $am[2]) as $year) {
                    $out[] = [
                        'text' => $rest, 'start' => $pStart + $skip, 'end' => $pStart + strlen($trimmed),
                        'author' => $author, 'key' => self::firstWord($author), 'year' => strtolower($year),
                    ];
                }
            }
        }
        // Narrative: Smith (2020), Smith and Lee (2020, p. 4), Smith et al. (2020)
        $n = self::NAME;
        $narrative = "/((?:{$n}[{$ws}]+){0,4}{$n}(?:[{$ws}]+(?:and|&)[{$ws}]+{$n}|[{$ws}]+et\\.?[{$ws}]*al\\.*)?)[{$ws}]+\\(({$y})(?:,[^()]*)?\\)/u";
        preg_match_all($narrative, $text, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($all as $m) {
            $out[] = [
                'text' => $m[0][0], 'start' => $m[0][1], 'end' => $m[0][1] + strlen($m[0][0]),
                'author' => $m[1][0], 'key' => self::firstWord($m[1][0]), 'year' => strtolower($m[2][0]),
            ];
        }
        return $out;
    }

    private static function mla(string $text): array
    {
        $ws = Text::WS;
        $out = [];
        // (Smith 50), (Smith and Lee 50-52), (Smith et al. 12; Brown 7)
        preg_match_all('/\(([^()]+)\)/u', $text, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($all as $m) {
            foreach (self::parts($m[1][0], $m[1][1]) as [$trimmed, $pStart]) {
                $re = "/^(\\p{Lu}[\\p{L}'\\x{2019}.\\- &,]*?),?[{$ws}]+(\\d+(?:[{$ws}]*[-\\x{2013}][{$ws}]*\\d+)?)\\z/u";
                if (!preg_match($re, $trimmed, $pm) || in_array(self::firstWord($pm[1]), self::NOT_AUTHORS, true)
                    || preg_match('/^\d{4}\z/', $pm[2])) {
                    continue;
                }
                $out[] = [
                    'text' => $trimmed, 'start' => $pStart, 'end' => $pStart + strlen($trimmed),
                    'author' => $pm[1], 'key' => self::firstWord($pm[1]), 'year' => null,
                ];
            }
        }
        return $out;
    }

    /** @return list<array{text: string, start: int, end: int, author: string, key: string, year: ?string}> */
    public static function find(string $text, string $style): array
    {
        if (!in_array($style, self::STYLES, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown style "%s". Use: %s', $style, implode(', ', self::STYLES)));
        }
        $list = $style === 'apa' ? self::apa($text) : self::mla($text);
        usort($list, fn ($a, $b) => $a['start'] <=> $b['start'] ?: $a['end'] <=> $b['end']);
        return $list;
    }

    /** Whether a citation points at a reference entry. */
    public static function matches(array $citation, array $entry): bool
    {
        if ($citation['year'] !== null && $citation['year'] !== $entry['year']) {
            return false;
        }
        preg_match_all("/\\p{L}[\\p{L}'\\x{2019}-]*/u", $citation['author'], $m);
        return in_array($entry['key'], array_map([self::class, 'lower'], $m[0]), true);
    }

    /** @return array{key: string, year: ?string} */
    public static function parseEntry(string $text): array
    {
        $body = preg_replace('/^(?:\[\d+\]|\d+\.|[-*\x{2022}])[' . Text::WS . ']+/u', '', $text);
        $y = preg_match('/\((' . self::YEAR . ')/u', $body, $m) ? strtolower($m[1]) : null;
        return ['key' => self::firstWord($body), 'year' => $y];
    }
}
