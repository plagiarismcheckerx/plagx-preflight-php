<?php

declare(strict_types=1);

namespace Plagx\Preflight;

/**
 * Plagx Preflight: a draft checker with basic grammar suggestions.
 * A free academic writing utility by Plagiarism Checker X. Runs fully offline.
 *
 * Results match the JavaScript reference exactly: start, end and column are
 * UTF-16 code-unit offsets.
 */
final class Preflight
{
    public const VERSION = '0.1.2';

    /** Check groups. 'passive-voice' is off by default. */
    public const CHECKS = ['length', 'references', 'punctuation', 'grammar', 'passive-voice'];
    public const DEFAULT_CHECKS = ['length', 'references', 'punctuation', 'grammar'];
    public const STYLES = Citations::STYLES;

    private const RUNNERS = [
        'length' => 'length',
        'references' => 'references',
        'punctuation' => 'punctuation',
        'grammar' => 'grammar',
        'passive-voice' => 'passiveVoice',
    ];

    /**
     * Checks a draft and returns stats plus a sorted list of suggestions.
     *
     * @param array{minWords?: ?int, maxWords?: ?int, style?: string, checks?: list<string>, requiredHeadings?: list<string>} $options
     * @return array{stats: array, errors: int, warnings: int, infos: int, issues: list<array>}
     */
    public static function checkDraft(string $text, array $options = []): array
    {
        self::assertUtf8($text);
        $opts = self::normalizeOptions($options);
        $doc = Text::structure($text);
        $ctx = new Context($text, $opts, $doc, self::stats($text, $doc));
        foreach ($opts['checks'] as $c) {
            Checks::{self::RUNNERS[$c]}($ctx);
        }

        $offsets = new Offsets($text);
        $starts = Text::lineStarts($text);
        $issues = [];
        foreach ($ctx->issues as $x) {
            $line = self::lineIndex($starts, $x['start']);
            $issues[] = [
                'level' => $x['level'],
                'code' => $x['code'],
                'message' => $x['message'],
                'start' => $offsets->at($x['start']),
                'end' => $offsets->at($x['end']),
                'line' => $line + 1,
                'column' => $offsets->at($x['start']) - $offsets->at($starts[$line]) + 1,
                'text' => substr($text, $x['start'], $x['end'] - $x['start']),
                'suggestion' => $x['suggestion'],
            ];
        }
        usort($issues, fn ($a, $b) => $a['start'] <=> $b['start'] ?: strcmp($a['code'], $b['code']) ?: $a['end'] <=> $b['end']);
        $count = fn (string $level) => count(array_filter($issues, fn ($i) => $i['level'] === $level));
        return [
            'stats' => $ctx->stats,
            'errors' => $count('error'),
            'warnings' => $count('warning'),
            'infos' => $count('info'),
            'issues' => $issues,
        ];
    }

    /** Number of words in the text. */
    public static function countWords(string $text): int
    {
        self::assertUtf8($text);
        return Text::countWords($text);
    }

    /**
     * Sentences with UTF-16 offsets. Headings count as one sentence each.
     *
     * @return list<array{text: string, start: int, end: int}>
     */
    public static function splitSentences(string $text): array
    {
        self::assertUtf8($text);
        $o = new Offsets($text);
        return array_map(fn ($s) => ['text' => $s['text'], 'start' => $o->at($s['start']), 'end' => $o->at($s['end'])], Text::splitSentences($text));
    }

    /**
     * In-text citations with UTF-16 offsets. key is the first word of the author
     * text in lowercase; year is null for MLA.
     *
     * @return list<array{text: string, start: int, end: int, author: string, key: string, year: ?string}>
     */
    public static function findCitations(string $text, string $style = 'apa'): array
    {
        self::assertUtf8($text);
        $o = new Offsets($text);
        return array_map(fn ($c) => ['start' => $o->at($c['start']), 'end' => $o->at($c['end'])] + $c, Citations::find($text, $style));
    }

    private static function assertUtf8(string $text): void
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            throw new \InvalidArgumentException('text must be valid UTF-8');
        }
    }

    private static function limit(mixed $value, string $name): ?int
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value) && preg_match('/^\d+\z/', $value)) {
            $value = (int) $value;
        }
        if (is_float($value) && floor($value) === $value) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < 0) {
            throw new \InvalidArgumentException("{$name} must be a whole number");
        }
        return $value;
    }

    private static function normalizeOptions(array $options): array
    {
        $style = $options['style'] ?? 'apa';
        if (!in_array($style, self::STYLES, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown style "%s". Use: %s', $style, implode(', ', self::STYLES)));
        }
        $list = $options['checks'] ?? self::DEFAULT_CHECKS;
        foreach ($list as $c) {
            if (!in_array($c, self::CHECKS, true)) {
                throw new \InvalidArgumentException(sprintf('Unknown check "%s". Use: %s', $c, implode(', ', self::CHECKS)));
            }
        }
        return [
            'minWords' => self::limit($options['minWords'] ?? null, 'minWords'),
            'maxWords' => self::limit($options['maxWords'] ?? null, 'maxWords'),
            'style' => $style,
            'checks' => array_values(array_filter(self::CHECKS, fn ($c) => in_array($c, $list, true))),
            'requiredHeadings' => array_map('strval', array_values($options['requiredHeadings'] ?? [])),
        ];
    }

    /** JavaScript Math.round. */
    private static function jsRound(float $v): float
    {
        $r = floor($v);
        return $v - $r >= 0.5 ? $r + 1 : $r;
    }

    private static function stats(string $text, array $doc): array
    {
        $bodyWords = Text::countWords(substr($text, 0, $doc['bodyEnd']));
        $sentences = 0;
        $sentenceWords = 0;
        $syl = 0;
        $paras = 0;
        foreach ($doc['body'] as $p) {
            if ($p['heading']) {
                continue;
            }
            $paras += 1;
            $sentences += count(Text::paragraphSentences($p));
            foreach (Text::words($p['text']) as $w) {
                $sentenceWords += 1;
                $syl += Text::syllables($w['text']);
            }
        }
        $flesch = 0;
        if ($sentences && $sentenceWords) {
            $f = self::jsRound((206.835 - 1.015 * ($sentenceWords / $sentences) - 84.6 * ($syl / $sentenceWords)) * 10) / 10;
            // Serialise like JavaScript: whole numbers without ".0", and no negative zero.
            $flesch = $f == floor($f) ? (int) $f : $f;
        }
        return [
            'words' => $bodyWords,
            'sentences' => $sentences,
            'paragraphs' => $paras,
            'characters' => mb_strlen($text, 'UTF-8'),
            'readingTimeMinutes' => (int) ceil($bodyWords / 200),
            'fleschReadingEase' => $flesch,
        ];
    }

    /** @param list<int> $starts */
    private static function lineIndex(array $starts, int $offset): int
    {
        $lo = 0;
        $hi = count($starts) - 1;
        while ($lo < $hi) {
            $mid = ($lo + $hi + 1) >> 1;
            if ($starts[$mid] <= $offset) {
                $lo = $mid;
            } else {
                $hi = $mid - 1;
            }
        }
        return $lo;
    }
}
