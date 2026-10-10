<?php

declare(strict_types=1);

namespace Modulento\Card\Qr;

/**
 * ISO/IEC 18004 QR Code encoder, byte mode only - every payload here is a
 * URL, so numeric/alphanumeric/kanji mode is not needed. Picks the smallest
 * version (1-40) that fits the input, preferring error-correction level M
 * and falling back to L only when the string is too long for M at version
 * 40. GF(256) tables and RS generator polynomials are computed once and
 * cached in static properties, not rebuilt per call.
 *
 * @see QrRenderer for turning the resulting matrix into SVG/PNG.
 */
final class QrEncoder
{
    public const EC_L = 0;
    public const EC_M = 1;
    public const EC_Q = 2;
    public const EC_H = 3;

    private const MODE_BYTE = 0b0100;

    private const FORMAT_GENERATOR = 0b10100110111;
    private const FORMAT_MASK = 0x5412;
    private const VERSION_GENERATOR = 0b1111100100101;

    /**
     * Table 9 (ISO/IEC 18004): per version (index = version-1) and EC level
     * (0=L,1=M,2=Q,3=H), [ecCodewordsPerBlock, blocks1, dataPerBlock1, blocks2, dataPerBlock2].
     * blocks2/dataPerBlock2 are 0 when the version/level has a single block group.
     */
    private const RS_BLOCKS = [
        [[7, 1, 19, 0, 0], [10, 1, 16, 0, 0], [13, 1, 13, 0, 0], [17, 1, 9, 0, 0]],
        [[10, 1, 34, 0, 0], [16, 1, 28, 0, 0], [22, 1, 22, 0, 0], [28, 1, 16, 0, 0]],
        [[15, 1, 55, 0, 0], [26, 1, 44, 0, 0], [18, 2, 17, 0, 0], [22, 2, 13, 0, 0]],
        [[20, 1, 80, 0, 0], [18, 2, 32, 0, 0], [26, 2, 24, 0, 0], [16, 4, 9, 0, 0]],
        [[26, 1, 108, 0, 0], [24, 2, 43, 0, 0], [18, 2, 15, 2, 16], [22, 2, 11, 2, 12]],
        [[18, 2, 68, 0, 0], [16, 4, 27, 0, 0], [24, 4, 19, 0, 0], [28, 4, 15, 0, 0]],
        [[20, 2, 78, 0, 0], [18, 4, 31, 0, 0], [18, 2, 14, 4, 15], [26, 4, 13, 1, 14]],
        [[24, 2, 97, 0, 0], [22, 2, 38, 2, 39], [22, 4, 18, 2, 19], [26, 4, 14, 2, 15]],
        [[30, 2, 116, 0, 0], [22, 3, 36, 2, 37], [20, 4, 16, 4, 17], [24, 4, 12, 4, 13]],
        [[18, 2, 68, 2, 69], [26, 4, 43, 1, 44], [24, 6, 19, 2, 20], [28, 6, 15, 2, 16]],
        [[20, 4, 81, 0, 0], [30, 1, 50, 4, 51], [28, 4, 22, 4, 23], [24, 3, 12, 8, 13]],
        [[24, 2, 92, 2, 93], [22, 6, 36, 2, 37], [26, 4, 20, 6, 21], [28, 7, 14, 4, 15]],
        [[26, 4, 107, 0, 0], [22, 8, 37, 1, 38], [24, 8, 20, 4, 21], [22, 12, 11, 4, 12]],
        [[30, 3, 115, 1, 116], [24, 4, 40, 5, 41], [20, 11, 16, 5, 17], [24, 11, 12, 5, 13]],
        [[22, 5, 87, 1, 88], [24, 5, 41, 5, 42], [30, 5, 24, 7, 25], [24, 11, 12, 7, 13]],
        [[24, 5, 98, 1, 99], [28, 7, 45, 3, 46], [24, 15, 19, 2, 20], [30, 3, 15, 13, 16]],
        [[28, 1, 107, 5, 108], [28, 10, 46, 1, 47], [28, 1, 22, 15, 23], [28, 2, 14, 17, 15]],
        [[30, 5, 120, 1, 121], [26, 9, 43, 4, 44], [28, 17, 22, 1, 23], [28, 2, 14, 19, 15]],
        [[28, 3, 113, 4, 114], [26, 3, 44, 11, 45], [26, 17, 21, 4, 22], [26, 9, 13, 16, 14]],
        [[28, 3, 107, 5, 108], [26, 3, 41, 13, 42], [30, 15, 24, 5, 25], [28, 15, 15, 10, 16]],
        [[28, 4, 116, 4, 117], [26, 17, 42, 0, 0], [28, 17, 22, 6, 23], [30, 19, 16, 6, 17]],
        [[28, 2, 111, 7, 112], [28, 17, 46, 0, 0], [30, 7, 24, 16, 25], [24, 34, 13, 0, 0]],
        [[30, 4, 121, 5, 122], [28, 4, 47, 14, 48], [30, 11, 24, 14, 25], [30, 16, 15, 14, 16]],
        [[30, 6, 117, 4, 118], [28, 6, 45, 14, 46], [30, 11, 24, 16, 25], [30, 30, 16, 2, 17]],
        [[26, 8, 106, 4, 107], [28, 8, 47, 13, 48], [30, 7, 24, 22, 25], [30, 22, 15, 13, 16]],
        [[28, 10, 114, 2, 115], [28, 19, 46, 4, 47], [28, 28, 22, 6, 23], [30, 33, 16, 4, 17]],
        [[30, 8, 122, 4, 123], [28, 22, 45, 3, 46], [30, 8, 23, 26, 24], [30, 12, 15, 28, 16]],
        [[30, 3, 117, 10, 118], [28, 3, 45, 23, 46], [30, 4, 24, 31, 25], [30, 11, 15, 31, 16]],
        [[30, 7, 116, 7, 117], [28, 21, 45, 7, 46], [30, 1, 23, 37, 24], [30, 19, 15, 26, 16]],
        [[30, 5, 115, 10, 116], [28, 19, 47, 10, 48], [30, 15, 24, 25, 25], [30, 23, 15, 25, 16]],
        [[30, 13, 115, 3, 116], [28, 2, 46, 29, 47], [30, 42, 24, 1, 25], [30, 23, 15, 28, 16]],
        [[30, 17, 115, 0, 0], [28, 10, 46, 23, 47], [30, 10, 24, 35, 25], [30, 19, 15, 35, 16]],
        [[30, 17, 115, 1, 116], [28, 14, 46, 21, 47], [30, 29, 24, 19, 25], [30, 11, 15, 46, 16]],
        [[30, 13, 115, 6, 116], [28, 14, 46, 23, 47], [30, 44, 24, 7, 25], [30, 59, 16, 1, 17]],
        [[30, 12, 121, 7, 122], [28, 12, 47, 26, 48], [30, 39, 24, 14, 25], [30, 22, 15, 41, 16]],
        [[30, 6, 121, 14, 122], [28, 6, 47, 34, 48], [30, 46, 24, 10, 25], [30, 2, 15, 64, 16]],
        [[30, 17, 122, 4, 123], [28, 29, 46, 14, 47], [30, 49, 24, 10, 25], [30, 24, 15, 46, 16]],
        [[30, 4, 122, 18, 123], [28, 13, 46, 32, 47], [30, 48, 24, 14, 25], [30, 42, 15, 32, 16]],
        [[30, 20, 117, 4, 118], [28, 40, 47, 7, 48], [30, 43, 24, 22, 25], [30, 10, 15, 67, 16]],
        [[30, 19, 118, 6, 119], [28, 18, 47, 31, 48], [30, 34, 24, 34, 25], [30, 20, 15, 61, 16]],
    ];

    /** Alignment pattern center coordinates per version (index = version-1); empty for version 1. */
    private const ALIGNMENT = [
        [], [6, 18], [6, 22], [6, 26], [6, 30], [6, 34], [6, 22, 38], [6, 24, 42], [6, 26, 46], [6, 28, 50],
        [6, 30, 54], [6, 32, 58], [6, 34, 62], [6, 26, 46, 66], [6, 26, 48, 70], [6, 26, 50, 74], [6, 30, 54, 78],
        [6, 30, 56, 82], [6, 30, 58, 86], [6, 34, 62, 90], [6, 28, 50, 72, 94], [6, 26, 50, 74, 98],
        [6, 30, 54, 78, 102], [6, 28, 54, 80, 106], [6, 32, 58, 84, 110], [6, 30, 58, 86, 114],
        [6, 34, 62, 90, 118], [6, 26, 50, 74, 98, 122], [6, 30, 54, 78, 102, 126], [6, 26, 52, 78, 104, 130],
        [6, 30, 56, 82, 108, 134], [6, 34, 60, 86, 112, 138], [6, 30, 58, 86, 114, 142], [6, 34, 62, 90, 118, 146],
        [6, 30, 54, 78, 102, 126, 150], [6, 24, 50, 76, 102, 128, 154], [6, 28, 54, 80, 106, 132, 158],
        [6, 32, 58, 84, 110, 136, 162], [6, 26, 54, 82, 110, 138, 166], [6, 30, 58, 86, 114, 142, 170],
    ];

    private static ?array $expTable = null;
    private static ?array $logTable = null;
    /** @var array<int, list<int>> generator polynomial cache, keyed by EC codeword count */
    private static array $generatorCache = [];

    /** @return list<list<bool>> square module matrix, true = dark */
    public static function encode(string $data): array
    {
        $length = strlen($data);
        $bytes = [];
        for ($i = 0; $i < $length; $i++) {
            $bytes[] = ord($data[$i]);
        }

        [$version, $ecLevel] = self::selectVersion($length);
        $dataCodewords = self::buildDataCodewords($bytes, $version, $ecLevel);
        $allCodewords = self::interleave($dataCodewords, $version, $ecLevel);

        return self::buildMatrix($allCodewords, $version, $ecLevel);
    }

    // --- version/capacity selection -------------------------------------------------

    /** @return array{0: int, 1: int} [version, ecLevel] */
    private static function selectVersion(int $byteLength): array
    {
        foreach ([self::EC_M, self::EC_L] as $level) {
            for ($version = 1; $version <= 40; $version++) {
                if ($byteLength <= self::capacityBytes($version, $level)) {
                    return [$version, $level];
                }
            }
        }

        throw new \RuntimeException(sprintf('QrEncoder: %d bytes exceed the maximum QR capacity', $byteLength));
    }

    private static function capacityBytes(int $version, int $ecLevel): int
    {
        $countBits = $version <= 9 ? 8 : 16;
        $bits = self::totalDataCodewords($version, $ecLevel) * 8 - 4 - $countBits;

        return $bits > 0 ? intdiv($bits, 8) : 0;
    }

    private static function totalDataCodewords(int $version, int $ecLevel): int
    {
        [, $b1, $d1, $b2, $d2] = self::RS_BLOCKS[$version - 1][$ecLevel];

        return $b1 * $d1 + $b2 * $d2;
    }

    // --- data encoding ----------------------------------------------------------------

    /** @param list<int> $bytes @return list<int> data codewords, padded to capacity */
    private static function buildDataCodewords(array $bytes, int $version, int $ecLevel): array
    {
        $capacity = self::totalDataCodewords($version, $ecLevel);
        $countBits = $version <= 9 ? 8 : 16;

        $bits = [];
        self::appendBits($bits, self::MODE_BYTE, 4);
        self::appendBits($bits, count($bytes), $countBits);
        foreach ($bytes as $byte) {
            self::appendBits($bits, $byte, 8);
        }

        $terminatorLength = max(0, min(4, $capacity * 8 - count($bits)));
        if ($terminatorLength > 0) {
            self::appendBits($bits, 0, $terminatorLength);
        }
        while (count($bits) % 8 !== 0) {
            $bits[] = 0;
        }

        $codewords = [];
        $bitCount = count($bits);
        for ($i = 0; $i < $bitCount; $i += 8) {
            $byte = 0;
            for ($j = 0; $j < 8; $j++) {
                $byte = ($byte << 1) | $bits[$i + $j];
            }
            $codewords[] = $byte;
        }

        $pad = [0xEC, 0x11];
        $p = 0;
        while (count($codewords) < $capacity) {
            $codewords[] = $pad[$p % 2];
            $p++;
        }

        return $codewords;
    }

    /** @param list<int> $bits */
    private static function appendBits(array &$bits, int $value, int $length): void
    {
        for ($i = $length - 1; $i >= 0; $i--) {
            $bits[] = ($value >> $i) & 1;
        }
    }

    // --- block interleaving + Reed-Solomon ---------------------------------------------

    /** @return list<array{0: int, 1: int}> list of [dataCount, ecCount] per block, in placement order */
    private static function blockLayout(int $version, int $ecLevel): array
    {
        [$ec, $b1, $d1, $b2, $d2] = self::RS_BLOCKS[$version - 1][$ecLevel];
        $blocks = [];
        for ($i = 0; $i < $b1; $i++) {
            $blocks[] = [$d1, $ec];
        }
        for ($i = 0; $i < $b2; $i++) {
            $blocks[] = [$d2, $ec];
        }

        return $blocks;
    }

    /** @param list<int> $dataCodewords @return list<int> interleaved data+EC codewords */
    private static function interleave(array $dataCodewords, int $version, int $ecLevel): array
    {
        $layout = self::blockLayout($version, $ecLevel);
        $blocks = [];
        $offset = 0;
        foreach ($layout as [$dataCount, $ecCount]) {
            $block = array_slice($dataCodewords, $offset, $dataCount);
            $offset += $dataCount;
            $blocks[] = ['data' => $block, 'ec' => self::rsEncode($block, $ecCount)];
        }

        $result = [];
        $maxData = max(array_map(static fn(array $b) => count($b['data']), $blocks));
        for ($col = 0; $col < $maxData; $col++) {
            foreach ($blocks as $block) {
                if ($col < count($block['data'])) {
                    $result[] = $block['data'][$col];
                }
            }
        }
        $ecCount = count($blocks[0]['ec']);
        for ($col = 0; $col < $ecCount; $col++) {
            foreach ($blocks as $block) {
                $result[] = $block['ec'][$col];
            }
        }

        return $result;
    }

    /** @return array{0: list<int>, 1: list<int>} [exp, log] tables, GF(256), poly 0x11D */
    private static function gfTables(): array
    {
        if (self::$expTable !== null) {
            return [self::$expTable, self::$logTable];
        }

        $exp = array_fill(0, 256, 0);
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
        self::$expTable = $exp;
        self::$logTable = $log;

        return [$exp, $log];
    }

    private static function gfMul(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }
        [$exp, $log] = self::gfTables();

        return $exp[($log[$a] + $log[$b]) % 255];
    }

    /** @return list<int> monic generator polynomial of the given degree, high-degree coefficient first */
    private static function generatorPoly(int $ecCount): array
    {
        if (isset(self::$generatorCache[$ecCount])) {
            return self::$generatorCache[$ecCount];
        }

        [$exp] = self::gfTables();
        $poly = [1];
        for ($i = 0; $i < $ecCount; $i++) {
            $root = $exp[$i];
            $result = array_fill(0, count($poly) + 1, 0);
            foreach ($poly as $j => $coeff) {
                $result[$j] ^= $coeff;
                $result[$j + 1] ^= self::gfMul($coeff, $root);
            }
            $poly = $result;
        }

        return self::$generatorCache[$ecCount] = $poly;
    }

    /** @param list<int> $data @return list<int> EC codewords */
    private static function rsEncode(array $data, int $ecCount): array
    {
        $gen = self::generatorPoly($ecCount);
        $remainder = array_fill(0, $ecCount, 0);
        foreach ($data as $byte) {
            $factor = $byte ^ $remainder[0];
            array_shift($remainder);
            $remainder[] = 0;
            for ($i = 0; $i < $ecCount; $i++) {
                $remainder[$i] ^= self::gfMul($gen[$i + 1], $factor);
            }
        }

        return $remainder;
    }

    // --- BCH format/version info -------------------------------------------------------

    /** Generic GF(2) polynomial long division, returning the degree-bit remainder. */
    private static function bchDivide(int $value, int $generator, int $degree, int $totalBits): int
    {
        for ($bit = $totalBits - 1; $bit >= $degree; $bit--) {
            if (($value >> $bit) & 1) {
                $value ^= $generator << ($bit - $degree);
            }
        }

        return $value & ((1 << $degree) - 1);
    }

    private static function formatBits(int $ecLevel, int $mask): int
    {
        $ecIndicator = [self::EC_L => 0b01, self::EC_M => 0b00, self::EC_Q => 0b11, self::EC_H => 0b10][$ecLevel];
        $data = ($ecIndicator << 3) | $mask;
        $remainder = self::bchDivide($data << 10, self::FORMAT_GENERATOR, 10, 15);

        return (($data << 10) | $remainder) ^ self::FORMAT_MASK;
    }

    private static function versionBits(int $version): int
    {
        $remainder = self::bchDivide($version << 12, self::VERSION_GENERATOR, 12, 18);

        return ($version << 12) | $remainder;
    }

    // --- matrix construction ------------------------------------------------------------

    /** @param list<int> $codewords @return list<list<bool>> */
    private static function buildMatrix(array $codewords, int $version, int $ecLevel): array
    {
        $size = ($version - 1) * 4 + 21;
        $modules = array_fill(0, $size, array_fill(0, $size, false));
        $isFunction = array_fill(0, $size, array_fill(0, $size, false));

        self::placeFinder($modules, $isFunction, 0, 0);
        self::placeFinder($modules, $isFunction, 0, $size - 7);
        self::placeFinder($modules, $isFunction, $size - 7, 0);

        self::placeTiming($modules, $isFunction, $size);

        foreach (self::alignmentCenters($version) as [$row, $col]) {
            self::placeAlignment($modules, $isFunction, $row, $col);
        }

        $modules[$size - 8][8] = true;
        $isFunction[$size - 8][8] = true;

        self::reserveFormatAreas($isFunction, $size);

        if ($version >= 7) {
            self::placeVersionInfo($modules, $isFunction, $version, $size);
        }

        self::placeData($modules, $isFunction, $size, $codewords);

        $bestScore = null;
        $bestModules = null;
        for ($mask = 0; $mask < 8; $mask++) {
            $trial = self::applyMask($modules, $isFunction, $size, $mask);
            self::writeFormatBits($trial, $size, $ecLevel, $mask);
            $score = self::penaltyScore($trial, $size);
            if ($bestScore === null || $score < $bestScore) {
                $bestScore = $score;
                $bestModules = $trial;
            }
        }

        return $bestModules;
    }

    private static function placeFinder(array &$modules, array &$isFunction, int $topRow, int $topCol): void
    {
        $size = count($modules);
        for ($r = -1; $r <= 7; $r++) {
            for ($c = -1; $c <= 7; $c++) {
                $row = $topRow + $r;
                $col = $topCol + $c;
                if ($row < 0 || $row >= $size || $col < 0 || $col >= $size) {
                    continue;
                }
                $isFunction[$row][$col] = true;
                if ($r < 0 || $r > 6 || $c < 0 || $c > 6) {
                    $modules[$row][$col] = false;
                    continue;
                }
                $modules[$row][$col] = ($r === 0 || $r === 6 || $c === 0 || $c === 6)
                    || ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4);
            }
        }
    }

    private static function placeTiming(array &$modules, array &$isFunction, int $size): void
    {
        for ($i = 8; $i <= $size - 9; $i++) {
            $dark = $i % 2 === 0;
            $modules[6][$i] = $dark;
            $isFunction[6][$i] = true;
            $modules[$i][6] = $dark;
            $isFunction[$i][6] = true;
        }
    }

    /** @return list<array{0: int, 1: int}> */
    private static function alignmentCenters(int $version): array
    {
        $positions = self::ALIGNMENT[$version - 1];
        $n = count($positions);
        if ($n === 0) {
            return [];
        }

        $result = [];
        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $n - 1) || ($i === $n - 1 && $j === 0)) {
                    continue;
                }
                $result[] = [$positions[$i], $positions[$j]];
            }
        }

        return $result;
    }

    private static function placeAlignment(array &$modules, array &$isFunction, int $row, int $col): void
    {
        for ($dr = -2; $dr <= 2; $dr++) {
            for ($dc = -2; $dc <= 2; $dc++) {
                $r = $row + $dr;
                $c = $col + $dc;
                $isFunction[$r][$c] = true;
                $modules[$r][$c] = (abs($dr) === 2 || abs($dc) === 2) || ($dr === 0 && $dc === 0);
            }
        }
    }

    private static function reserveFormatAreas(array &$isFunction, int $size): void
    {
        for ($i = 0; $i <= 5; $i++) {
            $isFunction[8][$i] = true;
            $isFunction[$i][8] = true;
        }
        $isFunction[8][7] = true;
        $isFunction[8][8] = true;
        $isFunction[7][8] = true;
        for ($i = 0; $i < 7; $i++) {
            $isFunction[$size - 1 - $i][8] = true;
        }
        for ($i = 0; $i < 8; $i++) {
            $isFunction[8][$size - 8 + $i] = true;
        }
    }

    private static function placeVersionInfo(array &$modules, array &$isFunction, int $version, int $size): void
    {
        $bits = self::versionBits($version);
        for ($i = 0; $i < 18; $i++) {
            $bit = (bool) (($bits >> $i) & 1);
            $a = $size - 11 + $i % 3;
            $b = intdiv($i, 3);
            $modules[$b][$a] = $bit;
            $isFunction[$b][$a] = true;
            $modules[$a][$b] = $bit;
            $isFunction[$a][$b] = true;
        }
    }

    private static function writeFormatBits(array &$modules, int $size, int $ecLevel, int $mask): void
    {
        $bits = self::formatBits($ecLevel, $mask);

        // First copy, wrapped around the top-left finder: bits 0-5 run vertically
        // down column 8 (rows 0-5), bits 9-14 run horizontally along row 8
        // (columns 5-0, i.e. module[8][i] = bit[14-i]), and bits 6-8 fill the
        // three modules adjacent to the finder's bottom-right corner.
        for ($i = 0; $i <= 5; $i++) {
            $modules[$i][8] = (bool) (($bits >> $i) & 1);
            $modules[8][$i] = (bool) (($bits >> (14 - $i)) & 1);
        }
        $modules[7][8] = (bool) (($bits >> 6) & 1);
        $modules[8][8] = (bool) (($bits >> 7) & 1);
        $modules[8][7] = (bool) (($bits >> 8) & 1);

        // Second copy: bits 0-7 run horizontally along row 8 (columns size-1
        // down to size-8), bits 8-14 run vertically down column 8 (rows
        // size-7 to size-1). The always-dark module at (size-8, 8) is set
        // separately by the caller.
        for ($i = 0; $i < 8; $i++) {
            $modules[8][$size - 1 - $i] = (bool) (($bits >> $i) & 1);
        }
        for ($i = 0; $i < 7; $i++) {
            $modules[$size - 7 + $i][8] = (bool) (($bits >> (8 + $i)) & 1);
        }
    }

    /** @param list<int> $codewords */
    private static function placeData(array &$modules, array $isFunction, int $size, array $codewords): void
    {
        $bitIndex = 0;
        $totalBits = count($codewords) * 8;

        for ($right = $size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            for ($vert = 0; $vert < $size; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $col = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $row = $upward ? ($size - 1 - $vert) : $vert;
                    if (!$isFunction[$row][$col] && $bitIndex < $totalBits) {
                        $byte = $codewords[$bitIndex >> 3];
                        $bit = ($byte >> (7 - ($bitIndex & 7))) & 1;
                        $modules[$row][$col] = (bool) $bit;
                        $bitIndex++;
                    }
                }
            }
        }
    }

    /** @return list<list<bool>> a masked copy; function modules are left untouched */
    private static function applyMask(array $modules, array $isFunction, int $size, int $mask): array
    {
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if ($isFunction[$y][$x]) {
                    continue;
                }
                $invert = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => (($x * $y) % 2 + ($x * $y) % 3) === 0,
                    6 => ((($x * $y) % 2 + ($x * $y) % 3) % 2) === 0,
                    7 => ((($x + $y) % 2 + ($x * $y) % 3) % 2) === 0,
                    default => false,
                };
                if ($invert) {
                    $modules[$y][$x] = !$modules[$y][$x];
                }
            }
        }

        return $modules;
    }

    // --- mask penalty scoring ------------------------------------------------------------

    private static function penaltyScore(array $modules, int $size): int
    {
        return self::penaltyRule1($modules, $size)
            + self::penaltyRule2($modules, $size)
            + self::penaltyRule3($modules, $size)
            + self::penaltyRule4($modules, $size);
    }

    private static function penaltyRule1(array $modules, int $size): int
    {
        $total = 0;
        for ($y = 0; $y < $size; $y++) {
            $total += self::runPenalty($modules[$y]);
        }
        for ($x = 0; $x < $size; $x++) {
            $col = [];
            for ($y = 0; $y < $size; $y++) {
                $col[] = $modules[$y][$x];
            }
            $total += self::runPenalty($col);
        }

        return $total;
    }

    /** @param list<bool> $line */
    private static function runPenalty(array $line): int
    {
        $total = 0;
        $runColor = null;
        $runLength = 0;
        foreach ($line as $cell) {
            if ($cell === $runColor) {
                $runLength++;
            } else {
                if ($runLength >= 5) {
                    $total += 3 + ($runLength - 5);
                }
                $runColor = $cell;
                $runLength = 1;
            }
        }
        if ($runLength >= 5) {
            $total += 3 + ($runLength - 5);
        }

        return $total;
    }

    private static function penaltyRule2(array $modules, int $size): int
    {
        $total = 0;
        for ($y = 0; $y < $size - 1; $y++) {
            for ($x = 0; $x < $size - 1; $x++) {
                $c = $modules[$y][$x];
                if ($modules[$y][$x + 1] === $c && $modules[$y + 1][$x] === $c && $modules[$y + 1][$x + 1] === $c) {
                    $total += 3;
                }
            }
        }

        return $total;
    }

    private static function penaltyRule3(array $modules, int $size): int
    {
        $patternA = [true, false, true, true, true, false, true, false, false, false, false];
        $patternB = [false, false, false, false, true, false, true, true, true, false, true];
        $total = 0;

        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x <= $size - 11; $x++) {
                $window = [];
                for ($k = 0; $k < 11; $k++) {
                    $window[] = $modules[$y][$x + $k];
                }
                if ($window === $patternA || $window === $patternB) {
                    $total += 40;
                }
            }
        }
        for ($x = 0; $x < $size; $x++) {
            for ($y = 0; $y <= $size - 11; $y++) {
                $window = [];
                for ($k = 0; $k < 11; $k++) {
                    $window[] = $modules[$y + $k][$x];
                }
                if ($window === $patternA || $window === $patternB) {
                    $total += 40;
                }
            }
        }

        return $total;
    }

    private static function penaltyRule4(array $modules, int $size): int
    {
        $dark = 0;
        foreach ($modules as $row) {
            $dark += count(array_filter($row));
        }
        $total = $size * $size;
        $percent = intdiv($dark * 100, $total);
        $prev = intdiv($percent, 5) * 5;
        $next = $prev + 5;
        $a = intdiv(abs($prev - 50), 5);
        $b = intdiv(abs($next - 50), 5);

        return min($a, $b) * 10;
    }
}
