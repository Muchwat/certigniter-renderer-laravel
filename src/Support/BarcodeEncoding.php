<?php

declare(strict_types=1);

namespace Certigniter\CertificateRenderer\Support;

use InvalidArgumentException;

/**
 * A faithful PHP port of the JsBarcode 3.12 encoders the web Studio draws
 * barcodes with (canvasRendering.js's `barcodeEncoding()`: `margin: 0`,
 * `displayValue: true`), so a certificate's bars are module-for-module the
 * ones the designer saw: the same Code 128 code-set switching, the same
 * EAN-13/UPC-A quiet zones JsBarcode adds for its caption digits, the same
 * wide-bar ratio for ITF and the same Codabar start/stop handling. A generic
 * barcode library (picqer) disagrees with JsBarcode on all of those, which
 * changes the bar widths and positions on the page.
 *
 * Each result is the whole symbol as a string of modules ('1' = bar,
 * '0' = space) plus the caption Studio paints under it.
 */
class BarcodeEncoding
{
    /** Studio's `barcodeType` values. */
    public const TYPES = ['code128', 'code39', 'ean13', 'ean8', 'upcA', 'itf', 'codabar'];

    /**
     * @return array{modules: string, text: string}
     *
     * @throws InvalidArgumentException when the value can't be encoded in that symbology, as JsBarcode throws
     */
    public static function encode(string $data, string $barcodeType): array
    {
        return match ($barcodeType) {
            'code39' => self::code39($data),
            'ean13' => self::ean13($data),
            'ean8' => self::ean8($data),
            'upcA' => self::upcA($data),
            'itf' => self::itf($data),
            'codabar' => self::codabar($data),
            'code128' => self::code128($data),
            default => throw new InvalidArgumentException("Unsupported barcode format: {$barcodeType}"),
        };
    }

    private const CODE128_BARS = [
        '11011001100', '11001101100', '11001100110', '10010011000', '10010001100',
        '10001001100', '10011001000', '10011000100', '10001100100', '11001001000',
        '11001000100', '11000100100', '10110011100', '10011011100', '10011001110',
        '10111001100', '10011101100', '10011100110', '11001110010', '11001011100',
        '11001001110', '11011100100', '11001110100', '11101101110', '11101001100',
        '11100101100', '11100100110', '11101100100', '11100110100', '11100110010',
        '11011011000', '11011000110', '11000110110', '10100011000', '10001011000',
        '10001000110', '10110001000', '10001101000', '10001100010', '11010001000',
        '11000101000', '11000100010', '10110111000', '10110001110', '10001101110',
        '10111011000', '10111000110', '10001110110', '11101110110', '11010001110',
        '11000101110', '11011101000', '11011100010', '11011101110', '11101011000',
        '11101000110', '11100010110', '11101101000', '11101100010', '11100011010',
        '11101111010', '11001000010', '11110001010', '10100110000', '10100001100',
        '10010110000', '10010000110', '10000101100', '10000100110', '10110010000',
        '10110000100', '10011010000', '10011000010', '10000110100', '10000110010',
        '11000010010', '11001010000', '11110111010', '11000010100', '10001111010',
        '10100111100', '10010111100', '10010011110', '10111100100', '10011110100',
        '10011110010', '11110100100', '11110010100', '11110010010', '11011011110',
        '11011110110', '11110110110', '10101111000', '10100011110', '10001011110',
        '10111101000', '10111100010', '11110101000', '11110100010', '10111011110',
        '10111101110', '11101011110', '11110101110', '11010000100', '11010010000',
        '11010011100', '1100011101011',
    ];

    private const SET_A = 0;

    private const SET_B = 1;

    private const SET_C = 2;

    /**
     * JsBarcode's CODE128 auto mode: auto.js picks the code sets, CODE128.js encodes them.
     *
     * @return array{modules: string, text: string}
     */
    private static function code128(string $data): array
    {
        if (preg_match('/^[\x00-\x7F]+$/', $data) !== 1) {
            throw new InvalidArgumentException('Code 128 can only encode ASCII characters.');
        }

        $bytes = array_map('ord', str_split(self::code128AutoSelect($data)));
        $startIndex = array_shift($bytes) - 105;
        $set = [103 => self::SET_A, 104 => self::SET_B, 105 => self::SET_C][$startIndex];

        $result = '';
        $checksum = 0;
        $position = 1;
        while ($bytes !== []) {
            if ($bytes[0] >= 200) {
                $index = array_shift($bytes) - 105;
                $swap = [101 => self::SET_A, 100 => self::SET_B, 99 => self::SET_C][$index] ?? null;
                if ($swap !== null) {
                    $set = $swap;
                } elseif ($index === 98 && $set !== self::SET_C && $bytes !== []) {
                    $bytes[0] = $set === self::SET_A
                        ? ($bytes[0] > 95 ? $bytes[0] - 96 : $bytes[0])
                        : ($bytes[0] < 32 ? $bytes[0] + 96 : $bytes[0]);
                }
            } elseif ($set === self::SET_A) {
                $code = array_shift($bytes);
                $index = $code < 32 ? $code + 64 : $code - 32;
            } elseif ($set === self::SET_B) {
                $index = array_shift($bytes) - 32;
            } else {
                $index = (array_shift($bytes) - 48) * 10 + array_shift($bytes) - 48;
            }
            $result .= self::CODE128_BARS[$index] ?? '';
            $checksum += $index * $position;
            $position++;
        }

        return [
            'modules' => self::CODE128_BARS[$startIndex]
                .$result
                .self::CODE128_BARS[($checksum + $startIndex) % 103]
                .self::CODE128_BARS[106],
            'text' => (string) preg_replace('/[^\x20-\x7E]/', '', $data),
        ];
    }

    private const A_CHARS = '[\x00-\x5F\xC8-\xCF]';

    private const B_CHARS = '[\x20-\x7F\xC8-\xCF]';

    private const C_CHARS = '(\xCF*[0-9]{2}\xCF*)';

    private static function code128AutoSelect(string $string): string
    {
        $cLength = strlen(self::leading(self::C_CHARS, $string));
        if ($cLength >= 2) {
            $selected = chr(210).self::code128FromC($string);
        } else {
            $isA = strlen(self::leading(self::A_CHARS, $string)) > strlen(self::leading(self::B_CHARS, $string));
            $selected = chr($isA ? 208 : 209).self::code128FromAB($string, $isA);
        }

        // A single character between two set swaps becomes a SHIFT instead.
        return (string) preg_replace_callback('/[\xCD\xCE]([\x00-\xFF])[\xCD\xCE]/', fn (array $match): string => chr(203).$match[1], $selected, 1);
    }

    private static function code128FromAB(string $string, bool $isA): string
    {
        $ranges = $isA ? self::A_CHARS : self::B_CHARS;
        if (preg_match('/^('.$ranges.'+?)(([0-9]{2}){2,})([^0-9]|$)/', $string, $untilC) === 1) {
            return $untilC[1].chr(204).self::code128FromC(substr($string, strlen($untilC[1])));
        }

        $chars = self::leading($ranges, $string);
        if ($chars === '') {
            throw new InvalidArgumentException('Code 128 can not encode this value.');
        }
        if (strlen($chars) === strlen($string)) {
            return $string;
        }

        return $chars.chr($isA ? 205 : 206).self::code128FromAB(substr($string, strlen($chars)), ! $isA);
    }

    private static function code128FromC(string $string): string
    {
        $cMatch = self::leading(self::C_CHARS, $string);
        if (strlen($cMatch) === strlen($string)) {
            return $string;
        }

        $string = substr($string, strlen($cMatch));
        $isA = strlen(self::leading(self::A_CHARS, $string)) >= strlen(self::leading(self::B_CHARS, $string));

        return $cMatch.chr($isA ? 206 : 205).self::code128FromAB($string, $isA);
    }

    /** The longest run of `$pattern` at the start of `$string` (JavaScript's `^pattern*`). */
    private static function leading(string $pattern, string $string): string
    {
        return preg_match('/^'.$pattern.'*/', $string, $match) === 1 ? $match[0] : '';
    }

    private const CODE39_CHARACTERS = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ-. $/+%*';

    private const CODE39_ENCODINGS = [
        20957, 29783, 23639, 30485, 20951, 29813, 23669, 20855, 29789, 23645, 29975,
        23831, 30533, 22295, 30149, 24005, 21623, 29981, 23837, 22301, 30023, 23879,
        30545, 22343, 30161, 24017, 21959, 30065, 23921, 22385, 29015, 18263, 29141,
        17879, 29045, 18293, 17783, 29021, 18269, 17477, 17489, 17681, 20753, 35770,
    ];

    /** @return array{modules: string, text: string} */
    private static function code39(string $data): array
    {
        $data = strtoupper($data);
        if (preg_match('/^[0-9A-Z\-. $\/+%]+$/', $data) !== 1) {
            throw new InvalidArgumentException('Code 39 can only encode 0-9, A-Z and - . $ / + % space.');
        }

        $character = fn (string $char): string => decbin(self::CODE39_ENCODINGS[strpos(self::CODE39_CHARACTERS, $char)]);
        $modules = $character('*');
        foreach (str_split($data) as $char) {
            $modules .= $character($char).'0';
        }

        return ['modules' => $modules.$character('*'), 'text' => $data];
    }

    private const EAN_BINARIES = [
        'L' => ['0001101', '0011001', '0010011', '0111101', '0100011', '0110001', '0101111', '0111011', '0110111', '0001011'],
        'G' => ['0100111', '0110011', '0011011', '0100001', '0011101', '0111001', '0000101', '0010001', '0001001', '0010111'],
        'R' => ['1110010', '1100110', '1101100', '1000010', '1011100', '1001110', '1010000', '1000100', '1001000', '1110100'],
    ];

    private const EAN13_STRUCTURE = ['LLLLLL', 'LLGLGG', 'LLGGLG', 'LLGGGL', 'LGLLGG', 'LGGLLG', 'LGGGLL', 'LGLGLG', 'LGLGGL', 'LGGLGL'];

    private static function eanDigits(string $digits, string $structure): string
    {
        $encoded = '';
        foreach (str_split($digits) as $index => $digit) {
            $encoded .= self::EAN_BINARIES[$structure[$index]][(int) $digit];
        }

        return $encoded;
    }

    /** `$weightOdd` weights digits at even indexes (0, 2, ...), EAN-8/UPC style; otherwise odd ones, EAN-13 style. */
    private static function eanChecksum(string $digits, bool $weightEven): int
    {
        $sum = 0;
        foreach (str_split($digits) as $index => $digit) {
            $sum += (int) $digit * (($index % 2 === 0) === $weightEven ? 3 : 1);
        }

        return (10 - $sum % 10) % 10;
    }

    /** @return array{modules: string, text: string} */
    private static function ean13(string $data): array
    {
        if (preg_match('/^[0-9]{12}$/', $data) === 1) {
            $data .= self::eanChecksum($data, false);
        }
        if (preg_match('/^[0-9]{13}$/', $data) !== 1 || (int) $data[12] !== self::eanChecksum(substr($data, 0, 12), false)) {
            throw new InvalidArgumentException('EAN-13 needs 12 digits, or 13 with a valid check digit.');
        }

        return [
            // 12 blank modules on the left: the room JsBarcode leaves for the first digit.
            'modules' => '000000000000101'
                .self::eanDigits(substr($data, 1, 6), self::EAN13_STRUCTURE[(int) $data[0]])
                .'01010'
                .self::eanDigits(substr($data, 7, 6), 'RRRRRR')
                .'101',
            'text' => $data,
        ];
    }

    /** @return array{modules: string, text: string} */
    private static function ean8(string $data): array
    {
        if (preg_match('/^[0-9]{7}$/', $data) === 1) {
            $data .= self::eanChecksum($data, true);
        }
        if (preg_match('/^[0-9]{8}$/', $data) !== 1 || (int) $data[7] !== self::eanChecksum(substr($data, 0, 7), true)) {
            throw new InvalidArgumentException('EAN-8 needs 7 digits, or 8 with a valid check digit.');
        }

        return [
            'modules' => '101'.self::eanDigits(substr($data, 0, 4), 'LLLL').'01010'.self::eanDigits(substr($data, 4, 4), 'RRRR').'101',
            'text' => $data,
        ];
    }

    /** @return array{modules: string, text: string} */
    private static function upcA(string $data): array
    {
        if (preg_match('/^[0-9]{11}$/', $data) === 1) {
            $data .= self::eanChecksum($data, true);
        }
        if (preg_match('/^[0-9]{12}$/', $data) !== 1 || (int) $data[11] !== self::eanChecksum(substr($data, 0, 11), true)) {
            throw new InvalidArgumentException('UPC-A needs 11 digits, or 12 with a valid check digit.');
        }

        return [
            // 8 blank modules either side: the room JsBarcode leaves for the outer digits.
            'modules' => '00000000101'
                .self::eanDigits(substr($data, 0, 6), 'LLLLLL')
                .'01010'
                .self::eanDigits(substr($data, 6, 6), 'RRRRRR')
                .'10100000000',
            'text' => $data,
        ];
    }

    private const ITF_BINARIES = ['00110', '10001', '01001', '11000', '00101', '10100', '01100', '00011', '10010', '01010'];

    /** @return array{modules: string, text: string} */
    private static function itf(string $data): array
    {
        if (preg_match('/^([0-9]{2})+$/', $data) !== 1) {
            throw new InvalidArgumentException('ITF needs an even number of digits.');
        }

        $modules = '1010';
        foreach (str_split($data, 2) as $pair) {
            $first = self::ITF_BINARIES[(int) $pair[0]];
            $second = self::ITF_BINARIES[(int) $pair[1]];
            for ($index = 0; $index < 5; $index++) {
                $modules .= ($first[$index] === '1' ? '111' : '1').($second[$index] === '1' ? '000' : '0');
            }
        }

        return ['modules' => $modules.'11101', 'text' => $data];
    }

    private const CODABAR_ENCODINGS = [
        '0' => '101010011', '1' => '101011001', '2' => '101001011', '3' => '110010101',
        '4' => '101101001', '5' => '110101001', '6' => '100101011', '7' => '100101101',
        '8' => '100110101', '9' => '110100101', '-' => '101001101', '$' => '101100101',
        ':' => '1101011011', '/' => '1101101011', '.' => '1101101101', '+' => '1011011011',
        'A' => '1011001001', 'B' => '1001001011', 'C' => '1010010011', 'D' => '1010011001',
    ];

    /** @return array{modules: string, text: string} */
    private static function codabar(string $data): array
    {
        if (preg_match('/^[0-9\-$:.+\/]+$/', $data) === 1) {
            $data = 'A'.$data.'A';
        }
        $data = strtoupper($data);
        if (preg_match('/^[A-D][0-9\-$:.+\/]+[A-D]$/', $data) !== 1) {
            throw new InvalidArgumentException('Codabar needs digits and - $ : / . + between A-D start/stop characters.');
        }

        return [
            'modules' => implode('0', array_map(fn (string $char): string => self::CODABAR_ENCODINGS[$char], str_split($data))),
            'text' => (string) preg_replace('/[A-D]/', '', $data),
        ];
    }
}
