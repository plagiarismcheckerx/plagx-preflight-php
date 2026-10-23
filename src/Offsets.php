<?php

declare(strict_types=1);

namespace Plagx\Preflight;

/**
 * Converts UTF-8 byte offsets to UTF-16 code-unit offsets, the unit the
 * JavaScript reference (and every port) reports.
 *
 * @internal
 */
final class Offsets
{
    /** @var array<int, int> byte offset => UTF-16 offset, for every character boundary */
    private array $map = [];

    public function __construct(string $text)
    {
        $units = 0;
        $len = strlen($text);
        for ($i = 0; $i < $len; $i += 1) {
            $b = ord($text[$i]);
            if (($b & 0xC0) === 0x80) {
                continue; // continuation byte
            }
            $this->map[$i] = $units;
            $units += $b >= 0xF0 ? 2 : 1;
        }
        $this->map[$len] = $units;
    }

    public function at(int $byte): int
    {
        return $this->map[$byte];
    }

    /** Length of a string in UTF-16 code units. */
    public static function length(string $s): int
    {
        return intdiv(strlen(mb_convert_encoding($s, 'UTF-16LE', 'UTF-8')), 2);
    }
}
