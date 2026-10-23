<?php

declare(strict_types=1);

namespace Plagx\Preflight;

/**
 * The individual check groups. Each reports issues through Context::add() with byte offsets.
 *
 * @internal
 */
final class Checks
{
    public const LONG_SENTENCE_WORDS = 40;
    public const LONG_PARAGRAPH_WORDS = 250;

    private const SILENT_H = ['hour', 'honest', 'honor', 'honour', 'heir'];
    private const CONSONANT_SOUND = ['uni', 'use', 'usa', 'usu', 'uti', 'ure', 'uro', 'urani', 'eu', 'ewe', 'one', 'once', 'ubiq', 'unanim'];
    private const UNCERTAIN = ['herb', 'histor', 'hotel', 'unin', 'unim', 'unid', 'oner'];
    private const PAIRS = [')' => '(', ']' => '[', '}' => '{'];

    private static function upperFirst(string $s): string
    {
        if ($s === '') {
            return $s;
        }
        $first = mb_substr($s, 0, 1, 'UTF-8');
        // JavaScript upper-cases only the first UTF-16 unit, which leaves astral characters unchanged.
        if (strlen($first) === 4) {
            return $s;
        }
        return mb_strtoupper($first, 'UTF-8') . substr($s, strlen($first));
    }

    private static function matchCase(string $fix, string $original): string
    {
        return preg_match('/^\p{Lu}/u', $original) ? self::upperFirst($fix) : $fix;
    }

    private static function startsWithAny(string $w, array $list): bool
    {
        foreach ($list as $p) {
            if (str_starts_with($w, $p)) {
                return true;
            }
        }
        return false;
    }

    /** preg_match_all with byte offsets: list of [match, offset, groups]. */
    private static function all(string $re, string $s): array
    {
        preg_match_all($re, $s, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        return $m;
    }

    // ---------------------------------------------------------------- length

    public static function length(Context $ctx): void
    {
        $words = $ctx->stats['words'];
        $o = $ctx->opts;
        if ($o['minWords'] !== null && $words < $o['minWords']) {
            $ctx->addDoc('warning', 'word-count-low', "Suggestion: the draft has {$words} words; the minimum is {$o['minWords']}.");
        }
        if ($o['maxWords'] !== null && $words > $o['maxWords']) {
            $ctx->addDoc('warning', 'word-count-high', "Suggestion: the draft has {$words} words; the maximum is {$o['maxWords']}.");
        }
        $ls = self::LONG_SENTENCE_WORDS;
        $lp = self::LONG_PARAGRAPH_WORDS;
        foreach ($ctx->body as $p) {
            if ($p['heading']) {
                continue;
            }
            $sentences = Text::paragraphSentences($p);
            foreach ($sentences as $s) {
                $n = Text::countWords($s['text']);
                if ($n > $ls) {
                    $ctx->add('warning', 'long-sentence', $s['start'], $s['end'],
                        "Suggestion: this sentence has {$n} words; consider splitting it (over {$ls}).");
                }
            }
            $n = Text::countWords($p['text']);
            if ($n > $lp) {
                $ctx->add('info', 'long-paragraph', $p['start'], $p['end'],
                    "Suggestion: this paragraph has {$n} words; consider splitting it (over {$lp}).");
            }
            if (count($sentences) === 1 && !preg_match('/^(?:[-*+\x{2022}]|\d+[.)])[' . Text::WS . ']/u', $p['text'])) {
                $ctx->add('info', 'single-sentence-paragraph', $p['start'], $p['end'], 'Suggestion: this paragraph has only one sentence.');
            }
        }
        $headings = array_map(fn ($h) => Text::normalizeHeading($h['text']), array_values(array_filter($ctx->doc['paragraphs'], fn ($p) => $p['heading'])));
        foreach ($o['requiredHeadings'] as $h) {
            if (!in_array(Text::normalizeHeading($h), $headings, true)) {
                $ctx->addDoc('warning', 'missing-heading', "Suggestion: add a \"{$h}\" heading.");
            }
        }
    }

    // ---------------------------------------------------------------- references

    public static function references(Context $ctx): void
    {
        $doc = $ctx->doc;
        $citations = Citations::find($ctx->bodyText, $ctx->opts['style']);
        if ($citations && $doc['referenceHeading'] === null) {
            $ctx->addDoc('error', 'missing-reference-section',
                'Suggestion: the draft has citations but no reference list heading (References or Works Cited).');
        }
        $entries = array_map(fn ($e) => $e + Citations::parseEntry($e['text']), $doc['entries']);
        if ($doc['referenceHeading'] !== null) {
            foreach ($citations as $c) {
                $found = false;
                foreach ($entries as $e) {
                    if (Citations::matches($c, $e)) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $ctx->add('error', 'citation-no-reference', $c['start'], $c['end'],
                        "Suggestion: \"{$c['text']}\" has no matching entry in the reference list.");
                }
            }
            foreach ($entries as $e) {
                $found = false;
                foreach ($citations as $c) {
                    if (Citations::matches($c, $e)) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $ctx->add('warning', 'reference-not-cited', $e['start'], $e['end'], 'Suggestion: this reference is never cited in the text.');
                }
            }
        }
        foreach (self::all('/\bet\.?[' . Text::WS . ']*al\b\.*/ui', $ctx->bodyText) as $m) {
            if (mb_strtolower($m[0][0], 'UTF-8') !== 'et al.') {
                $ctx->add('warning', 'et-al-format', $m[0][1], $m[0][1] + strlen($m[0][0]), 'Suggestion: write "et al." with one period.', 'et al.');
            }
        }
    }

    // ---------------------------------------------------------------- punctuation

    public static function punctuation(Context $ctx): void
    {
        $ws = Text::WS;
        $straight = -1;
        $curly = -1;
        $unmatched = 'Suggestion: this quotation mark has no matching pair.';
        foreach ($ctx->body as $p) {
            $t = $p['text'];
            $at = fn (int $i) => $p['start'] + $i;

            // Quotation marks
            $quotes = [];
            $pos = -1;
            while (($pos = strpos($t, '"', $pos + 1)) !== false) {
                $quotes[] = $pos;
            }
            if ($quotes && $straight < 0) {
                $straight = $at($quotes[0]);
            }
            if (count($quotes) % 2 === 1) {
                $i = end($quotes);
                $ctx->add('warning', 'unmatched-quote', $at($i), $at($i) + 1, $unmatched);
            }
            $open = [];
            foreach (self::all('/[\x{201c}\x{201d}]/u', $t) as $m) {
                [$q, $i] = $m[0];
                if ($curly < 0) {
                    $curly = $at($i);
                }
                if ($q === "\u{201c}") {
                    $open[] = $i;
                } elseif ($open) {
                    array_pop($open);
                } else {
                    $ctx->add('warning', 'unmatched-quote', $at($i), $at($i) + 3, $unmatched);
                }
            }
            foreach ($open as $i) {
                $ctx->add('warning', 'unmatched-quote', $at($i), $at($i) + 3, $unmatched);
            }

            // Brackets
            $stack = [];
            $len = strlen($t);
            for ($i = 0; $i < $len; $i += 1) {
                $c = $t[$i];
                if ($c === '(' || $c === '[' || $c === '{') {
                    $stack[] = $i;
                } elseif (isset(self::PAIRS[$c])) {
                    if ($stack && $t[end($stack)] === self::PAIRS[$c]) {
                        array_pop($stack);
                    } else {
                        // List markers such as "a)", "ii)" or "1)".
                        if ($c === ')' && preg_match("/(?:^|[{$ws}])[A-Za-z0-9]{1,3}\\z/u", substr($t, 0, $i))) {
                            continue;
                        }
                        $ctx->add('warning', 'unmatched-bracket', $at($i), $at($i) + 1, "Suggestion: \"{$c}\" has no matching bracket.");
                    }
                }
            }
            foreach ($stack as $i) {
                $ctx->add('warning', 'unmatched-bracket', $at($i), $at($i) + 1, "Suggestion: \"{$t[$i]}\" has no matching bracket.");
            }

            foreach (self::all("/(?<=[^{$ws}]) {2,}(?=[^{$ws}])/u", $t) as $m) {
                $ctx->add('info', 'double-space', $at($m[0][1]), $at($m[0][1] + strlen($m[0][0])), 'Suggestion: use a single space.', ' ');
            }
            foreach (self::all("/(?<=[\\p{L}0-9)\\]\"\\x{201d}]) +([,;:!?.])(?=[{$ws}]|\\z)/u", $t) as $m) {
                $ctx->add('warning', 'space-before-punctuation', $at($m[0][1]), $at($m[0][1] + strlen($m[0][0])),
                    "Suggestion: remove the space before \"{$m[1][0]}\".", $m[1][0]);
            }
            foreach (self::all('/[,;:!?](?=[A-Za-z])|(?<=[a-z]{2})\.(?=[A-Z][a-z])/', $t) as $m) {
                $ctx->add('warning', 'missing-space-after-punctuation', $at($m[0][1]), $at($m[0][1] + 1),
                    "Suggestion: add a space after \"{$m[0][0]}\".", "{$m[0][0]} ");
            }
            foreach (self::all('/([!?,;:])\1+|(?<!\.)\.\.(?!\.)/', $t) as $m) {
                $ctx->add('warning', 'repeated-punctuation', $at($m[0][1]), $at($m[0][1] + strlen($m[0][0])),
                    "Suggestion: \"{$m[0][0]}\" repeats punctuation.", $m[0][0][0]);
            }
        }
        if ($straight >= 0 && $curly >= 0) {
            $i = max($straight, $curly);
            $ctx->add('info', 'mixed-quote-styles', $i, $i + ($i === $curly ? 3 : 1), 'Suggestion: the draft mixes straight and curly quotation marks.');
        }
    }

    // ---------------------------------------------------------------- grammar

    private const CONFUSIONS = [
        ['/\b(could|would|should|must|might) of\b/iu', '%1$s have'],
        ['/\bits (a|an|been|not|going)\b/iu', 'it\'s %1$s'],
        ["/\\bit['\\x{2019}]s own\\b/iu", 'its own'],
        ['/\b(more|less|better|worse|rather|greater|fewer|larger|smaller|higher|lower|faster|slower|easier|harder) then\b/iu', '%1$s than'],
        ['/\btheir (is|are|was|were)\b/iu', 'there %1$s'],
        ['/\bthere own\b/iu', 'their own'],
        ['/\b(the|an) affect\b/iu', '%1$s effect'],
        ['/\b(may|might|will|can|could|would|should|negatively|positively|greatly|significantly) effect\b/iu', '%1$s affect'],
        ['/\bloose (weight|track|control)\b/iu', 'lose %1$s'],
        ['/\balot\b/iu', 'a lot'],
    ];

    private const PASSIVE = '/\b(?:am|is|are|was|were|be|been|being)[WS]+(?:[a-z]+ly[WS]+)?(?:[a-z]{3,}ed|written|done|made|given|taken|shown|seen|known|found|held|chosen|built|drawn|taught|thought|brought|said|told|sent|kept|paid|begun|broken|driven|eaten|forgotten|hidden|spoken|stolen|worn)\b/iu';

    public static function grammar(Context $ctx): void
    {
        $iForm = "/^i(['\\x{2019}](?:m|ve|ll|d))?\\z/u";
        foreach ($ctx->body as $p) {
            $t = $p['text'];
            $toks = Text::words($t);
            $n = count($toks);
            for ($i = 0; $i < $n; $i += 1) {
                $tok = $toks[$i];
                $low = mb_strtolower($tok['text'], 'UTF-8');
                $next = null;
                if ($i + 1 < $n) {
                    $gap = substr($t, $tok['end'], $toks[$i + 1]['start'] - $tok['end']);
                    if (preg_match('/^[' . Text::WS . ']+\z/u', $gap)) {
                        $next = $toks[$i + 1];
                    }
                }

                if ($next && mb_strtolower($next['text'], 'UTF-8') === $low && preg_match('/\p{L}/u', $low) && $low !== 'had' && $low !== 'that') {
                    $ctx->add('warning', 'repeated-word', $p['start'] + $tok['start'], $p['start'] + $next['end'],
                        "Suggestion: \"{$tok['text']} {$next['text']}\" looks like a repeated word.", $tok['text']);
                }

                if ($next && ($low === 'a' || $low === 'an')) {
                    $nw = explode('-', $next['text'])[0];
                    $nl = mb_strtolower($nw, 'UTF-8');
                    $nwLen = Offsets::length($nw);
                    $acronym = $nwLen > 1 && $nw === mb_strtoupper($nw, 'UTF-8');
                    if ($nwLen > 1 && !preg_match('/[0-9]/', $nw) && !$acronym && preg_match('/^[a-z]/', $nl) && !self::startsWithAny($nl, self::UNCERTAIN)) {
                        $vowel = self::startsWithAny($nl, self::SILENT_H) || (preg_match('/^[aeiou]/', $nl) && !self::startsWithAny($nl, self::CONSONANT_SOUND));
                        $expected = $vowel ? 'an' : 'a';
                        if ($low !== $expected) {
                            $ctx->add('warning', 'a-an', $p['start'] + $tok['start'], $p['start'] + $tok['end'],
                                "Suggestion: use \"{$expected}\" before \"{$next['text']}\".", self::matchCase($expected, $tok['text']));
                        }
                    }
                }

                if (preg_match($iForm, $tok['text'], $im)) {
                    $before = $tok['start'] > 0 ? $t[$tok['start'] - 1] : '';
                    $after = $tok['end'] < strlen($t) ? $t[$tok['end']] : '';
                    if (isset($im[1]) || ($after !== '.' && $after !== ')' && $before !== '.')) {
                        $fix = self::upperFirst($tok['text']);
                        $ctx->add('warning', 'lowercase-i', $p['start'] + $tok['start'], $p['start'] + $tok['end'],
                            "Suggestion: use \"{$fix}\" instead of \"{$tok['text']}\".", $fix);
                    }
                }
            }

            if (!$p['heading']) {
                foreach (Text::paragraphSentences($p) as $s) {
                    $first = Text::words($s['text'])[0] ?? null;
                    if ($first && $first['start'] === 0 && preg_match('/^\p{Ll}/u', $first['text']) && !preg_match('/\p{Lu}/u', $first['text'])
                        && !preg_match($iForm, $first['text']) && ($s['text'][$first['end']] ?? '') !== ')') {
                        $ctx->add('warning', 'sentence-capital', $s['start'], $s['start'] + $first['end'],
                            'Suggestion: start the sentence with a capital letter.', self::upperFirst($first['text']));
                    }
                }
            }

            foreach (self::CONFUSIONS as [$re, $fix]) {
                foreach (self::all($re, $t) as $m) {
                    $suggestion = self::matchCase(sprintf($fix, $m[1][0] ?? ''), $m[0][0]);
                    $ctx->add('warning', 'common-confusion', $p['start'] + $m[0][1], $p['start'] + $m[0][1] + strlen($m[0][0]),
                        "Suggestion: \"{$m[0][0]}\" may be \"{$suggestion}\".", $suggestion);
                }
            }
        }
    }

    public static function passiveVoice(Context $ctx): void
    {
        $re = str_replace('[WS]', '[' . Text::WS . ']', self::PASSIVE);
        foreach ($ctx->body as $p) {
            foreach (self::all($re, $p['text']) as $m) {
                $ctx->add('info', 'passive-voice', $p['start'] + $m[0][1], $p['start'] + $m[0][1] + strlen($m[0][0]),
                    "Suggestion: \"{$m[0][0]}\" may be passive voice.");
            }
        }
    }
}
