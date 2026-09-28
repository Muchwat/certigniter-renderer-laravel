<?php

declare(strict_types=1);

namespace Certigniter\CertificateRenderer\Support;

use InvalidArgumentException;

/**
 * A faithful PHP port of the `qrcode` npm package (1.5.4, lib/core) that the
 * web Studio draws QR codes with (canvasRendering.js's `qrMatrix()`), so the
 * same text gives the same symbol module for module, not merely an
 * equivalent one that scans the same. Stock PHP encoders disagree with it in
 * two visible ways: `qrcode` splits text into the cheapest mix of numeric,
 * alphanumeric and byte segments (which can pick a smaller version), and
 * its mask penalty scoring differs, so it often picks another mask.
 *
 * Kanji mode is left out, as in the Studio: `qrcode` only uses it when given
 * a Shift JIS converter, which the Studio never passes.
 */
class QrEncoder
{
    private const NUMERIC = 'numeric';

    private const ALPHANUMERIC = 'alphanumeric';

    private const BYTE = 'byte';

    private const MODE_BITS = [self::NUMERIC => 1, self::ALPHANUMERIC => 2, self::BYTE => 4];

    private const CHAR_COUNT_BITS = [self::NUMERIC => [10, 12, 14], self::ALPHANUMERIC => [9, 11, 13], self::BYTE => [8, 16, 16]];

    /** QR format bits for each error correction level (not alphabetical). */
    private const LEVEL_BITS = ['L' => 1, 'M' => 0, 'Q' => 3, 'H' => 2];

    /** Index into the per-version tables below. */
    private const LEVEL_COLUMN = ['L' => 0, 'M' => 1, 'Q' => 2, 'H' => 3];

    private const ALPHANUMERIC_CHARS = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ $%*+-./:';

    private const CODEWORDS_COUNT = [
        0, 26, 44, 70, 100, 134, 172, 196, 242, 292, 346,
        404, 466, 532, 581, 655, 733, 815, 901, 991, 1085,
        1156, 1258, 1364, 1474, 1588, 1706, 1828, 1921, 2051, 2185,
        2323, 2465, 2611, 2761, 2876, 3034, 3196, 3362, 3532, 3706,
    ];

    private const EC_BLOCKS_TABLE = [
        1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 2, 2, 1, 2, 2, 4, 1, 2, 4, 4,
        2, 4, 4, 4, 2, 4, 6, 5, 2, 4, 6, 6, 2, 5, 8, 8, 4, 5, 8, 8,
        4, 5, 8, 11, 4, 8, 10, 11, 4, 9, 12, 16, 4, 9, 16, 16, 6, 10, 12, 18,
        6, 10, 17, 16, 6, 11, 16, 19, 6, 13, 18, 21, 7, 14, 21, 25, 8, 16, 20, 25,
        8, 17, 23, 25, 9, 17, 23, 34, 9, 18, 25, 30, 10, 20, 27, 32, 12, 21, 29, 35,
        12, 23, 34, 37, 12, 25, 34, 40, 13, 26, 35, 42, 14, 28, 38, 45, 15, 29, 40, 48,
        16, 31, 43, 51, 17, 33, 45, 54, 18, 35, 48, 57, 19, 37, 51, 60, 19, 38, 53, 63,
        20, 40, 56, 66, 21, 43, 59, 70, 22, 45, 62, 74, 24, 47, 65, 77, 25, 49, 68, 81,
    ];

    private const EC_CODEWORDS_TABLE = [
        7, 10, 13, 17, 10, 16, 22, 28, 15, 26, 36, 44, 20, 36, 52, 64, 26, 48, 72, 88,
        36, 64, 96, 112, 40, 72, 108, 130, 48, 88, 132, 156, 60, 110, 160, 192, 72, 130, 192, 224,
        80, 150, 224, 264, 96, 176, 260, 308, 104, 198, 288, 352, 120, 216, 320, 384, 132, 240, 360, 432,
        144, 280, 408, 480, 168, 308, 448, 532, 180, 338, 504, 588, 196, 364, 546, 650, 224, 416, 600, 700,
        224, 442, 644, 750, 252, 476, 690, 816, 270, 504, 750, 900, 300, 560, 810, 960, 312, 588, 870, 1050,
        336, 644, 952, 1110, 360, 700, 1020, 1200, 390, 728, 1050, 1260, 420, 784, 1140, 1350, 450, 812, 1200, 1440,
        480, 868, 1290, 1530, 510, 924, 1350, 1620, 540, 980, 1440, 1710, 570, 1036, 1530, 1800, 570, 1064, 1590, 1890,
        600, 1120, 1680, 1980, 630, 1204, 1770, 2100, 660, 1260, 1860, 2220, 720, 1316, 1950, 2310, 750, 1372, 2040, 2430,
    ];

    /** @var array<int, int>|null */
    private static ?array $exp = null;

    /** @var array<int, int> */
    private static array $log = [];

    /**
     * @param  string  $level  'L', 'M', 'Q' or 'H'
     * @return list<list<bool>> rows of modules, true = dark
     */
    public static function matrix(string $data, string $level): array
    {
        if ($data === '') {
            throw new InvalidArgumentException('No input text');
        }
        if (! isset(self::LEVEL_BITS[$level])) {
            throw new InvalidArgumentException("Unknown EC Level: {$level}");
        }

        $estimatedVersion = self::bestVersion(self::rawSegments($data), $level);
        $segments = self::optimizedSegments($data, $estimatedVersion ?? 40);
        $version = self::bestVersion($segments, $level);
        if ($version === null) {
            throw new InvalidArgumentException('The amount of data is too big to be stored in a QR Code');
        }

        $codewords = self::codewords($version, $level, $segments);
        $size = $version * 4 + 17;
        $modules = array_fill(0, $size * $size, 0);
        $reserved = array_fill(0, $size * $size, false);
        $set = function (int $row, int $column, int $value, bool $reserve = false) use (&$modules, &$reserved, $size): void {
            $modules[$row * $size + $column] = $value;
            if ($reserve) {
                $reserved[$row * $size + $column] = true;
            }
        };

        self::setupFinderPatterns($set, $size);
        for ($r = 8; $r < $size - 8; $r++) {
            $value = $r % 2 === 0 ? 1 : 0;
            $set($r, 6, $value, true);
            $set(6, $r, $value, true);
        }
        self::setupAlignmentPatterns($set, $version);
        self::setupFormatInfo($set, $size, $level, 0);
        if ($version >= 7) {
            self::setupVersionInfo($set, $size, $version);
        }
        self::setupData($modules, $reserved, $size, $codewords);

        $bestPattern = 0;
        $lowerPenalty = INF;
        for ($pattern = 0; $pattern < 8; $pattern++) {
            self::setupFormatInfo($set, $size, $level, $pattern);
            self::applyMask($modules, $reserved, $size, $pattern);
            $penalty = self::penaltyN1($modules, $size) + self::penaltyN2($modules, $size)
                + self::penaltyN3($modules, $size) + self::penaltyN4($modules);
            self::applyMask($modules, $reserved, $size, $pattern);
            if ($penalty < $lowerPenalty) {
                $lowerPenalty = $penalty;
                $bestPattern = $pattern;
            }
        }
        self::applyMask($modules, $reserved, $size, $bestPattern);
        self::setupFormatInfo($set, $size, $level, $bestPattern);

        $rows = [];
        for ($row = 0; $row < $size; $row++) {
            $rows[] = array_map(fn (int $module): bool => $module === 1, array_slice($modules, $row * $size, $size));
        }

        return $rows;
    }

    // Segments (segments.js)

    /**
     * Numeric, alphanumeric and byte runs in string order, un-merged.
     *
     * @return list<array{data: string, mode: string}>
     */
    private static function rawSegmentParts(string $data): array
    {
        /** @var list<array{data: string, mode: string, index: int}> $parts */
        $parts = [];
        foreach ([self::NUMERIC => '/[0-9]+/', self::ALPHANUMERIC => '/[A-Z $%*+\-.\/:]+/', self::BYTE => '/[^A-Z0-9 $%*+\-.\/:]+/u'] as $mode => $pattern) {
            if (preg_match_all($pattern, $data, $matches, PREG_OFFSET_CAPTURE) === false) {
                throw new InvalidArgumentException('QR content must be valid UTF-8.');
            }
            foreach ($matches[0] as [$text, $offset]) {
                $parts[] = ['data' => $text, 'mode' => $mode, 'index' => $offset];
            }
        }
        usort($parts, fn (array $a, array $b): int => $a['index'] <=> $b['index']);

        return array_map(fn (array $part): array => ['data' => $part['data'], 'mode' => $part['mode']], $parts);
    }

    /** @return list<array{data: string, mode: string}> */
    private static function rawSegments(string $data): array
    {
        return self::rawSegmentParts($data);
    }

    /**
     * The cheapest mix of modes, found as `qrcode` finds it: a shortest path
     * through every mode each run could take, costed in bits.
     *
     * @return list<array{data: string, mode: string}>
     */
    private static function optimizedSegments(string $data, int $version): array
    {
        $nodes = [];
        foreach (self::rawSegmentParts($data) as $segment) {
            $nodes[] = match ($segment['mode']) {
                self::NUMERIC => [
                    ['data' => $segment['data'], 'mode' => self::NUMERIC, 'length' => strlen($segment['data'])],
                    ['data' => $segment['data'], 'mode' => self::ALPHANUMERIC, 'length' => strlen($segment['data'])],
                    ['data' => $segment['data'], 'mode' => self::BYTE, 'length' => strlen($segment['data'])],
                ],
                self::ALPHANUMERIC => [
                    ['data' => $segment['data'], 'mode' => self::ALPHANUMERIC, 'length' => strlen($segment['data'])],
                    ['data' => $segment['data'], 'mode' => self::BYTE, 'length' => strlen($segment['data'])],
                ],
                default => [
                    ['data' => $segment['data'], 'mode' => self::BYTE, 'length' => strlen($segment['data'])],
                ],
            };
        }

        // buildGraph: the running `lastCount` is mutated while costing, exactly as segments.js does.
        $table = [];
        $graph = ['start' => []];
        $previousIds = ['start'];
        foreach ($nodes as $i => $group) {
            $currentIds = [];
            foreach ($group as $j => $node) {
                $key = $i.'|'.$j;
                $currentIds[] = $key;
                $table[$key] = ['node' => $node, 'lastCount' => 0];
                $graph[$key] = [];
                foreach ($previousIds as $previousId) {
                    if (isset($table[$previousId]) && $table[$previousId]['node']['mode'] === $node['mode']) {
                        $graph[$previousId][$key] = self::segmentBits($table[$previousId]['lastCount'] + $node['length'], $node['mode'])
                            - self::segmentBits($table[$previousId]['lastCount'], $node['mode']);
                        $table[$previousId]['lastCount'] += $node['length'];
                    } else {
                        if (isset($table[$previousId])) {
                            $table[$previousId]['lastCount'] = $node['length'];
                        }
                        $graph[$previousId][$key] = self::segmentBits($node['length'], $node['mode'])
                            + 4 + self::charCountBits($node['mode'], $version);
                    }
                }
            }
            $previousIds = $currentIds;
        }
        foreach ($previousIds as $previousId) {
            $graph[$previousId]['end'] = 0;
        }

        $path = self::shortestPath($graph, 'start', 'end');
        /** @var list<array{data: string, mode: string}> $merged */
        $merged = [];
        foreach (array_slice($path, 1, -1) as $key) {
            $node = $table[$key]['node'];
            $last = array_key_last($merged);
            if ($last !== null && $merged[$last]['mode'] === $node['mode']) {
                $merged[$last]['data'] .= $node['data'];
            } else {
                $merged[] = ['data' => $node['data'], 'mode' => $node['mode']];
            }
        }

        return $merged;
    }

    /**
     * dijkstrajs's find_path, including its naive priority queue: a stable
     * sort by cost after every push, so ties resolve in push order.
     *
     * @param  array<string, array<string, int>>  $graph
     * @return list<string>
     */
    private static function shortestPath(array $graph, string $source, string $target): array
    {
        $predecessors = [];
        $costs = [$source => 0];
        $open = [['value' => $source, 'cost' => 0]];
        while ($open !== []) {
            $closest = array_shift($open);
            foreach ($graph[$closest['value']] ?? [] as $vertex => $edgeCost) {
                $vertex = (string) $vertex;
                $cost = $closest['cost'] + $edgeCost;
                if (! array_key_exists($vertex, $costs) || $costs[$vertex] > $cost) {
                    $costs[$vertex] = $cost;
                    $open[] = ['value' => $vertex, 'cost' => $cost];
                    usort($open, fn (array $a, array $b): int => $a['cost'] <=> $b['cost']);
                    $predecessors[$vertex] = $closest['value'];
                }
            }
        }
        if (! array_key_exists($target, $costs)) {
            throw new InvalidArgumentException("Could not find a path from {$source} to {$target}.");
        }

        $nodes = [];
        for ($vertex = $target; $vertex !== null; $vertex = $predecessors[$vertex] ?? null) {
            $nodes[] = $vertex;
        }

        return array_reverse($nodes);
    }

    private static function segmentBits(int $length, string $mode): int
    {
        return match ($mode) {
            self::NUMERIC => 10 * intdiv($length, 3) + ($length % 3 ? ($length % 3) * 3 + 1 : 0),
            self::ALPHANUMERIC => 11 * intdiv($length, 2) + 6 * ($length % 2),
            default => $length * 8,
        };
    }

    private static function charCountBits(string $mode, int $version): int
    {
        return self::CHAR_COUNT_BITS[$mode][$version < 10 ? 0 : ($version < 27 ? 1 : 2)];
    }

    // Versions and capacity (version.js)

    /** @param  list<array{data: string, mode: string}>  $segments */
    private static function bestVersion(array $segments, string $level): ?int
    {
        if ($segments === []) {
            return 1;
        }

        for ($version = 1; $version <= 40; $version++) {
            $dataBits = self::dataCodewords($version, $level) * 8;
            if (count($segments) > 1) {
                $total = 0;
                foreach ($segments as $segment) {
                    $total += self::charCountBits($segment['mode'], $version) + 4 + self::segmentBits(strlen($segment['data']), $segment['mode']);
                }
                if ($total <= $dataBits) {
                    return $version;
                }

                continue;
            }

            $mode = $segments[0]['mode'];
            $usable = $dataBits - (self::charCountBits($mode, $version) + 4);
            $capacity = match ($mode) {
                self::NUMERIC => (int) floor(($usable / 10) * 3),
                self::ALPHANUMERIC => (int) floor(($usable / 11) * 2),
                default => (int) floor($usable / 8),
            };
            if (strlen($segments[0]['data']) <= $capacity) {
                return $version;
            }
        }

        return null;
    }

    private static function dataCodewords(int $version, string $level): int
    {
        return self::CODEWORDS_COUNT[$version] - self::EC_CODEWORDS_TABLE[($version - 1) * 4 + self::LEVEL_COLUMN[$level]];
    }

    // Data and error correction (qrcode.js createData/createCodewords)

    /**
     * @param  list<array{data: string, mode: string}>  $segments
     * @return list<int>
     */
    private static function codewords(int $version, string $level, array $segments): array
    {
        $bits = [];
        $put = function (int $number, int $length) use (&$bits): void {
            for ($i = 0; $i < $length; $i++) {
                $bits[] = ($number >> ($length - $i - 1)) & 1;
            }
        };

        foreach ($segments as $segment) {
            $text = $segment['data'];
            $put(self::MODE_BITS[$segment['mode']], 4);
            $put(strlen($text), self::charCountBits($segment['mode'], $version));
            if ($segment['mode'] === self::NUMERIC) {
                for ($i = 0; $i + 3 <= strlen($text); $i += 3) {
                    $put((int) substr($text, $i, 3), 10);
                }
                $remaining = strlen($text) - $i;
                if ($remaining > 0) {
                    $put((int) substr($text, $i), $remaining * 3 + 1);
                }
            } elseif ($segment['mode'] === self::ALPHANUMERIC) {
                for ($i = 0; $i + 2 <= strlen($text); $i += 2) {
                    $put((int) strpos(self::ALPHANUMERIC_CHARS, $text[$i]) * 45 + (int) strpos(self::ALPHANUMERIC_CHARS, $text[$i + 1]), 11);
                }
                if (strlen($text) % 2) {
                    $put((int) strpos(self::ALPHANUMERIC_CHARS, $text[$i]), 6);
                }
            } else {
                foreach (str_split($text) as $byte) {
                    $put(ord($byte), 8);
                }
            }
        }

        $dataCodewords = self::dataCodewords($version, $level);
        $dataBits = $dataCodewords * 8;
        if (count($bits) + 4 <= $dataBits) {
            $put(0, 4);
        }
        while (count($bits) % 8 !== 0) {
            $bits[] = 0;
        }
        $remainingBytes = intdiv($dataBits - count($bits), 8);
        for ($i = 0; $i < $remainingBytes; $i++) {
            $put($i % 2 ? 0x11 : 0xEC, 8);
        }

        $buffer = [];
        foreach (array_chunk($bits, 8) as $byteBits) {
            $buffer[] = (int) bindec(implode('', $byteBits));
        }

        $totalCodewords = self::CODEWORDS_COUNT[$version];
        $blocks = self::EC_BLOCKS_TABLE[($version - 1) * 4 + self::LEVEL_COLUMN[$level]];
        $blocksInGroup2 = $totalCodewords % $blocks;
        $blocksInGroup1 = $blocks - $blocksInGroup2;
        $dataInGroup1 = intdiv($dataCodewords, $blocks);
        $ecCount = intdiv($totalCodewords, $blocks) - $dataInGroup1;
        $generator = self::generatorPolynomial($ecCount);

        $dataBlocks = [];
        $ecBlocks = [];
        $offset = 0;
        $maxDataSize = 0;
        for ($block = 0; $block < $blocks; $block++) {
            $dataSize = $block < $blocksInGroup1 ? $dataInGroup1 : $dataInGroup1 + 1;
            $dataBlocks[$block] = array_slice($buffer, $offset, $dataSize);
            $ecBlocks[$block] = self::reedSolomon($dataBlocks[$block], $generator, $ecCount);
            $offset += $dataSize;
            $maxDataSize = max($maxDataSize, $dataSize);
        }

        $codewords = [];
        for ($i = 0; $i < $maxDataSize; $i++) {
            for ($block = 0; $block < $blocks; $block++) {
                if ($i < count($dataBlocks[$block])) {
                    $codewords[] = $dataBlocks[$block][$i];
                }
            }
        }
        for ($i = 0; $i < $ecCount; $i++) {
            for ($block = 0; $block < $blocks; $block++) {
                $codewords[] = $ecBlocks[$block][$i];
            }
        }

        return $codewords;
    }

    private static function galoisTables(): void
    {
        if (self::$exp !== null) {
            return;
        }

        $exp = array_fill(0, 512, 0);
        $log = array_fill(0, 256, 0);
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            $exp[$i] = $x;
            $log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11D;
            }
        }
        for ($i = 255; $i < 512; $i++) {
            $exp[$i] = $exp[$i - 255];
        }
        self::$exp = $exp;
        self::$log = $log;
    }

    private static function galoisMultiply(int $x, int $y): int
    {
        return $x === 0 || $y === 0 ? 0 : (self::$exp[self::$log[$x] + self::$log[$y]] ?? 0);
    }

    /** @return array<int, int> */
    private static function generatorPolynomial(int $degree): array
    {
        self::galoisTables();
        $polynomial = [1];
        for ($i = 0; $i < $degree; $i++) {
            $factor = [1, self::$exp[$i] ?? 0];
            $product = array_fill(0, count($polynomial) + 1, 0);
            foreach ($polynomial as $a => $p) {
                foreach ($factor as $b => $f) {
                    $product[$a + $b] ^= self::galoisMultiply($p, $f);
                }
            }
            $polynomial = $product;
        }

        return $polynomial;
    }

    /**
     * @param  list<int>  $data
     * @param  array<int, int>  $generator
     * @return list<int>
     */
    private static function reedSolomon(array $data, array $generator, int $degree): array
    {
        $result = array_merge($data, array_fill(0, $degree, 0));
        while (count($result) - count($generator) >= 0) {
            $coefficient = $result[0];
            foreach ($generator as $i => $g) {
                $result[$i] ^= self::galoisMultiply($g, $coefficient);
            }
            $offset = 0;
            while ($offset < count($result) && $result[$offset] === 0) {
                $offset++;
            }
            $result = array_slice($result, $offset);
        }

        return array_merge(array_fill(0, max(0, $degree - count($result)), 0), $result);
    }

    // Function patterns and placement (qrcode.js setup*)

    private static function setupFinderPatterns(callable $set, int $size): void
    {
        foreach ([[0, 0], [$size - 7, 0], [0, $size - 7]] as [$row, $column]) {
            for ($r = -1; $r <= 7; $r++) {
                if ($row + $r <= -1 || $size <= $row + $r) {
                    continue;
                }
                for ($c = -1; $c <= 7; $c++) {
                    if ($column + $c <= -1 || $size <= $column + $c) {
                        continue;
                    }
                    $dark = ($r >= 0 && $r <= 6 && ($c === 0 || $c === 6))
                        || ($c >= 0 && $c <= 6 && ($r === 0 || $r === 6))
                        || ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4);
                    $set($row + $r, $column + $c, $dark ? 1 : 0, true);
                }
            }
        }
    }

    private static function setupAlignmentPatterns(callable $set, int $version): void
    {
        if ($version === 1) {
            return;
        }

        $count = intdiv($version, 7) + 2;
        $size = $version * 4 + 17;
        $interval = $size === 145 ? 26 : (int) ceil(($size - 13) / (2 * $count - 2)) * 2;
        $positions = [$size - 7];
        for ($i = 1; $i < $count - 1; $i++) {
            $positions[$i] = $positions[$i - 1] - $interval;
        }
        $positions[] = 6;
        $positions = array_reverse($positions);

        $last = count($positions) - 1;
        foreach ($positions as $i => $row) {
            foreach ($positions as $j => $column) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $last) || ($i === $last && $j === 0)) {
                    continue;
                }
                for ($r = -2; $r <= 2; $r++) {
                    for ($c = -2; $c <= 2; $c++) {
                        $dark = $r === -2 || $r === 2 || $c === -2 || $c === 2 || ($r === 0 && $c === 0);
                        $set($row + $r, $column + $c, $dark ? 1 : 0, true);
                    }
                }
            }
        }
    }

    private static function bchDigit(int $data): int
    {
        $digit = 0;
        while ($data !== 0) {
            $digit++;
            $data >>= 1;
        }

        return $digit;
    }

    private static function setupFormatInfo(callable $set, int $size, string $level, int $mask): void
    {
        $g15 = (1 << 10) | (1 << 8) | (1 << 5) | (1 << 4) | (1 << 2) | (1 << 1) | 1;
        $data = (self::LEVEL_BITS[$level] << 3) | $mask;
        $d = $data << 10;
        while (self::bchDigit($d) - self::bchDigit($g15) >= 0) {
            $d ^= $g15 << (self::bchDigit($d) - self::bchDigit($g15));
        }
        $bits = (($data << 10) | $d) ^ ((1 << 14) | (1 << 12) | (1 << 10) | (1 << 4) | (1 << 1));

        for ($i = 0; $i < 15; $i++) {
            $module = ($bits >> $i) & 1;
            if ($i < 6) {
                $set($i, 8, $module, true);
            } elseif ($i < 8) {
                $set($i + 1, 8, $module, true);
            } else {
                $set($size - 15 + $i, 8, $module, true);
            }
            if ($i < 8) {
                $set(8, $size - $i - 1, $module, true);
            } elseif ($i < 9) {
                $set(8, 15 - $i, $module, true);
            } else {
                $set(8, 15 - $i - 1, $module, true);
            }
        }
        $set($size - 8, 8, 1, true);
    }

    private static function setupVersionInfo(callable $set, int $size, int $version): void
    {
        $g18 = (1 << 12) | (1 << 11) | (1 << 10) | (1 << 9) | (1 << 8) | (1 << 5) | (1 << 2) | 1;
        $d = $version << 12;
        while (self::bchDigit($d) - self::bchDigit($g18) >= 0) {
            $d ^= $g18 << (self::bchDigit($d) - self::bchDigit($g18));
        }
        $bits = ($version << 12) | $d;

        for ($i = 0; $i < 18; $i++) {
            $row = intdiv($i, 3);
            $column = $i % 3 + $size - 8 - 3;
            $module = ($bits >> $i) & 1;
            $set($row, $column, $module, true);
            $set($column, $row, $module, true);
        }
    }

    /**
     * @param  array<int, int>  $modules
     * @param  array<int, bool>  $reserved
     * @param  list<int>  $codewords
     */
    private static function setupData(array &$modules, array $reserved, int $size, array $codewords): void
    {
        $increment = -1;
        $row = $size - 1;
        $bitIndex = 7;
        $byteIndex = 0;
        for ($column = $size - 1; $column > 0; $column -= 2) {
            if ($column === 6) {
                $column--;
            }
            while (true) {
                for ($c = 0; $c < 2; $c++) {
                    if (! $reserved[$row * $size + $column - $c]) {
                        $dark = $byteIndex < count($codewords) ? ($codewords[$byteIndex] >> $bitIndex) & 1 : 0;
                        $modules[$row * $size + $column - $c] = $dark;
                        $bitIndex--;
                        if ($bitIndex === -1) {
                            $byteIndex++;
                            $bitIndex = 7;
                        }
                    }
                }
                $row += $increment;
                if ($row < 0 || $size <= $row) {
                    $row -= $increment;
                    $increment = -$increment;
                    break;
                }
            }
        }
    }

    // Masks and penalties (mask-pattern.js)

    private static function maskAt(int $pattern, int $i, int $j): bool
    {
        return match ($pattern) {
            0 => ($i + $j) % 2 === 0,
            1 => $i % 2 === 0,
            2 => $j % 3 === 0,
            3 => ($i + $j) % 3 === 0,
            4 => (intdiv($i, 2) + intdiv($j, 3)) % 2 === 0,
            5 => ($i * $j) % 2 + ($i * $j) % 3 === 0,
            6 => (($i * $j) % 2 + ($i * $j) % 3) % 2 === 0,
            default => (($i * $j) % 3 + ($i + $j) % 2) % 2 === 0,
        };
    }

    /**
     * @param  array<int, int>  $modules
     * @param  array<int, bool>  $reserved
     */
    private static function applyMask(array &$modules, array $reserved, int $size, int $pattern): void
    {
        for ($column = 0; $column < $size; $column++) {
            for ($row = 0; $row < $size; $row++) {
                if (! $reserved[$row * $size + $column] && self::maskAt($pattern, $row, $column)) {
                    $modules[$row * $size + $column] ^= 1;
                }
            }
        }
    }

    /** @param  array<int, int>  $modules */
    private static function penaltyN1(array $modules, int $size): int
    {
        $points = 0;
        for ($row = 0; $row < $size; $row++) {
            $sameCountColumn = $sameCountRow = 0;
            $lastColumn = $lastRow = null;
            for ($column = 0; $column < $size; $column++) {
                $module = $modules[$row * $size + $column];
                if ($module === $lastColumn) {
                    $sameCountColumn++;
                } else {
                    if ($sameCountColumn >= 5) {
                        $points += 3 + ($sameCountColumn - 5);
                    }
                    $lastColumn = $module;
                    $sameCountColumn = 1;
                }
                $module = $modules[$column * $size + $row];
                if ($module === $lastRow) {
                    $sameCountRow++;
                } else {
                    if ($sameCountRow >= 5) {
                        $points += 3 + ($sameCountRow - 5);
                    }
                    $lastRow = $module;
                    $sameCountRow = 1;
                }
            }
            if ($sameCountColumn >= 5) {
                $points += 3 + ($sameCountColumn - 5);
            }
            if ($sameCountRow >= 5) {
                $points += 3 + ($sameCountRow - 5);
            }
        }

        return $points;
    }

    /** @param  array<int, int>  $modules */
    private static function penaltyN2(array $modules, int $size): int
    {
        $points = 0;
        for ($row = 0; $row < $size - 1; $row++) {
            for ($column = 0; $column < $size - 1; $column++) {
                $sum = $modules[$row * $size + $column] + $modules[$row * $size + $column + 1]
                    + $modules[($row + 1) * $size + $column] + $modules[($row + 1) * $size + $column + 1];
                if ($sum === 4 || $sum === 0) {
                    $points++;
                }
            }
        }

        return $points * 3;
    }

    /** @param  array<int, int>  $modules */
    private static function penaltyN3(array $modules, int $size): int
    {
        $points = 0;
        for ($row = 0; $row < $size; $row++) {
            $bitsColumn = $bitsRow = 0;
            for ($column = 0; $column < $size; $column++) {
                $bitsColumn = (($bitsColumn << 1) & 0x7FF) | $modules[$row * $size + $column];
                if ($column >= 10 && ($bitsColumn === 0x5D0 || $bitsColumn === 0x05D)) {
                    $points++;
                }
                $bitsRow = (($bitsRow << 1) & 0x7FF) | $modules[$column * $size + $row];
                if ($column >= 10 && ($bitsRow === 0x5D0 || $bitsRow === 0x05D)) {
                    $points++;
                }
            }
        }

        return $points * 40;
    }

    /** @param  array<int, int>  $modules */
    private static function penaltyN4(array $modules): int
    {
        $count = count($modules);

        return (int) abs(ceil((array_sum($modules) * 100 / $count) / 5) - 10) * 10;
    }
}
