<?php

declare(strict_types=1);

namespace Plagx\Preflight\Tests;

use PHPUnit\Framework\TestCase;
use Plagx\Preflight\Preflight;

final class PreflightTest extends TestCase
{
    private static function codes(string $text, array $options = []): array
    {
        return array_column(Preflight::checkDraft($text, $options)['issues'], 'code');
    }

    public function testResultShape(): void
    {
        $r = Preflight::checkDraft('The the cat sat.');
        $this->assertSame(['stats', 'errors', 'warnings', 'infos', 'issues'], array_keys($r));
        $this->assertSame(['words', 'sentences', 'paragraphs', 'characters', 'readingTimeMinutes', 'fleschReadingEase'], array_keys($r['stats']));
        $this->assertSame(['level', 'code', 'message', 'start', 'end', 'line', 'column', 'text', 'suggestion'], array_keys($r['issues'][0]));
        $this->assertSame('Suggestion: "The the" looks like a repeated word.', $r['issues'][0]['message']);
    }

    public function testUtf16Offsets(): void
    {
        $issues = Preflight::checkDraft("\u{1F600} the the end.")['issues'];
        $i = $issues[array_search('repeated-word', array_column($issues, 'code'), true)];
        $this->assertSame([3, 10, 4], [$i['start'], $i['end'], $i['column']]);
        $this->assertSame(3, Preflight::checkDraft("a\u{1F600}b")['stats']['characters']);
    }

    public function testHelpers(): void
    {
        $this->assertSame(6, Preflight::countWords("It's a well-known fact, 42 times."));
        $this->assertSame(['One.', 'Two!'], array_column(Preflight::splitSentences('One. Two!'), 'text'));
        $c = Preflight::findCitations("\u{e9} (Moore, 2011; Price, 2016, 2018)");
        $this->assertSame(['moore:2011', 'price:2016', 'price:2018'], array_map(fn ($x) => "{$x['key']}:{$x['year']}", $c));
        $this->assertSame(3, $c[0]['start']);
        $this->assertSame(['greenblatt'], array_column(Preflight::findCitations('(Greenblatt 45)', 'mla'), 'key'));
    }

    public function testOptions(): void
    {
        $this->assertSame(['missing-heading', 'missing-heading', 'word-count-low'],
            self::codes('One two. Three four.', ['minWords' => 10, 'requiredHeadings' => ['Introduction', 'Conclusion'], 'checks' => ['length']]));
        $this->assertSame([], self::codes('the the ,,', ['checks' => []]));
        $this->assertContains('passive-voice', self::codes('The data were collected.', ['checks' => ['passive-voice']]));
    }

    public function testInvalidInput(): void
    {
        foreach ([['style' => 'chicago'], ['checks' => ['magic']], ['minWords' => -1], ['maxWords' => 'ten']] as $bad) {
            try {
                Preflight::checkDraft('x', $bad);
                $this->fail('No exception for ' . json_encode($bad));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        Preflight::checkDraft("\xFF\xFE");
    }
}
