<?php

declare(strict_types=1);

namespace Plagx\Preflight;

/**
 * State shared by the checks for one checkDraft() call. Collects issues with
 * byte offsets; Preflight converts them for output.
 *
 * @internal
 */
final class Context
{
    // Codes inside URLs, email addresses and `code` are skipped.
    private const MASKED = '/^(?:unmatched-|double-space|space-before|missing-space|repeated-|mixed-quote|a-an|sentence-capital|lowercase-i|common-confusion|passive-voice)/';

    /** @var list<array{0: int, 1: int}> */
    private array $masks = [];
    /** @var array<string, true> */
    private array $seen = [];
    /** @var list<array> issues with byte offsets */
    public array $issues = [];

    public readonly array $body;
    public readonly string $bodyText;

    public function __construct(
        public readonly string $text,
        public readonly array $opts,
        public readonly array $doc,
        public readonly array $stats,
    ) {
        $this->body = $doc['body'];
        $this->bodyText = substr($text, 0, $doc['bodyEnd']);
        $ws = Text::WS;
        $re = "/\\bhttps?:\\/\\/[^{$ws}<>()]+|\\bwww\\.[^{$ws}<>()]+|[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(?:\\.[A-Za-z0-9-]+)+|`[^`\\n]*`/u";
        preg_match_all($re, $text, $m, PREG_OFFSET_CAPTURE);
        foreach ($m[0] as [$s, $at]) {
            $this->masks[] = [$at, $at + strlen($s)];
        }
    }

    public function add(string $level, string $code, int $start, int $end, string $message, ?string $suggestion = null): void
    {
        if (preg_match(self::MASKED, $code)) {
            foreach ($this->masks as [$a, $b]) {
                if ($start < $b && $end > $a) {
                    return;
                }
            }
        }
        $key = "{$code}:{$start}:{$end}:{$message}";
        if (isset($this->seen[$key])) {
            return;
        }
        $this->seen[$key] = true;
        $this->issues[] = compact('level', 'code', 'message', 'start', 'end', 'suggestion');
    }

    public function addDoc(string $level, string $code, string $message): void
    {
        $this->add($level, $code, 0, 0, $message);
    }
}
