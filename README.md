# Plagx Preflight for PHP

*A free academic writing tool by Plagiarism Checker X.*

**Plagx Preflight for PHP is a free, open-source draft checker for PHP and Laravel apps.** Use it as an essay checker before students submit: it checks the word count and structure, matches in-text citations against the reference list, and gives punctuation and basic grammar suggestions plus readability stats.

- No dependencies beyond `ext-mbstring`, works offline: text never leaves your server
- PHP 8.1+, works in any framework (Laravel, Symfony, WordPress plugins)
- APA and MLA citation checks
- Results identical to the [JavaScript version](https://github.com/plagiarismcheckerx/plagx-preflight), offsets included

**[Try the live demo →](https://plagiarismcheckerx.github.io/plagx-preflight/)**

Developer downloads: [Packagist](https://packagist.org/packages/plagiarismcheckerx/plagx-preflight) · [npm](https://www.npmjs.com/package/plagx-preflight) · [Docker Hub](https://hub.docker.com/r/plagiarismcheckerx/plagx-preflight) · [GitHub](https://github.com/plagiarismcheckerx/plagx-preflight)

> Plagx Preflight is a free academic writing utility by Plagiarism Checker X. It checks length, structure, references, punctuation and basic grammar, and every result is a suggestion. Plagiarism checking and AI-generated text detection are available in Plagiarism Checker X, linked at the end of this page.

## Install

```bash
composer require plagiarismcheckerx/plagx-preflight
```

## Quick start

```php
use Plagx\Preflight\Preflight;

$result = Preflight::checkDraft($text, [
    'minWords' => 1500,
    'maxWords' => 2000,
    'style' => 'apa',
    'requiredHeadings' => ['Introduction', 'Conclusion'],
]);

$result['stats'];
// ['words' => 1620, 'sentences' => 84, 'paragraphs' => 12, 'characters' => 9811,
//  'readingTimeMinutes' => 9, 'fleschReadingEase' => 48.2]

foreach ($result['issues'] as $issue) {
    echo "{$issue['line']}:{$issue['column']} {$issue['level']} {$issue['message']}\n";
}
// 5:14 warning Suggestion: "the the" looks like a repeated word.
```

## API

`Preflight::checkDraft(string $text, array $options = [])` returns `['stats', 'errors', 'warnings', 'infos', 'issues']`. Each issue has `level`, `code`, `message`, `start`, `end`, `line`, `column`, `text` and `suggestion`. Issues are sorted by position.

`start`, `end` and `column` count UTF-16 code units, like JavaScript, so results match the other Plagx Preflight libraries exactly. To highlight text in PHP, use `text` or `line` and `column`.

| Option | Default | |
|---|---|---|
| `minWords`, `maxWords` | none | Word limits (reference list excluded) |
| `style` | `'apa'` | `'apa'` or `'mla'` |
| `checks` | all except `'passive-voice'` | `length`, `references`, `punctuation`, `grammar`, `passive-voice` |
| `requiredHeadings` | `[]` | e.g. `['Introduction', 'Conclusion']` |

Helpers: `Preflight::countWords($text)`, `Preflight::splitSentences($text)`, `Preflight::findCitations($text, $style)`, `Preflight::VERSION`, `Preflight::CHECKS`.

Invalid options or text that is not valid UTF-8 throw `InvalidArgumentException`.

## Checks

| Group | Codes | Level |
|---|---|---|
| Length & structure | `word-count-low`, `word-count-high`, `long-sentence` (over 40 words), `long-paragraph` (over 250 words), `single-sentence-paragraph`, `missing-heading` | warning / info |
| References | `citation-no-reference`, `missing-reference-section`, `reference-not-cited`, `et-al-format` | error / warning |
| Punctuation | `unmatched-quote`, `unmatched-bracket`, `double-space`, `space-before-punctuation`, `missing-space-after-punctuation`, `repeated-punctuation`, `mixed-quote-styles` | warning / info |
| Grammar | `repeated-word`, `a-an`, `sentence-capital`, `lowercase-i`, `common-confusion`, `passive-voice` (off by default) | warning / info |

## Good to know

- Every result is a suggestion. The grammar rules are deliberately conservative, so they miss some mistakes rather than flag correct text.
- The reference list starts at a heading such as References, Bibliography or Works Cited. It is excluded from the word count and from the punctuation and grammar checks.

## Development

```bash
composer install
composer test
```

`tests/parity/expected.json` and `tests/fixtures/` come from the JavaScript reference (`npm run parity` in plagx-preflight). Every case must match exactly.

## Plagx Preflight - Free Draft & Grammar Checker

An open-source writing utility for students, researchers and developers. Check length, structure, citations, punctuation and basic grammar before you submit.

Developed by Plagiarism Checker X.

Plagx Preflight gives writing suggestions; it does not detect plagiarism or AI-generated text. For full document checks, explore [Plagiarism Checker X](https://plagiarismcheckerx.com/?utm_source=plagx-preflight-php&utm_medium=readme&utm_campaign=plagx&utm_content=about-cta) and its [AI Content Detector](https://plagiarismcheckerx.com/ai-content-detector?utm_source=plagx-preflight-php&utm_medium=readme&utm_campaign=plagx&utm_content=about-ai-detector).

## License

MIT © Plagiarism Checker X
