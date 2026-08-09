<?php

namespace Certigniter\CertificateRenderer\Support;

/**
 * Certigniter writes colors in two different byte orders depending on the
 * file's `color_format`:
 *
 *  - `"css-hex"` (current app versions): `#RRGGBB` or `#RRGGBBAA` - alpha
 *    LAST, standard CSS order. certificate_project.dart's toJson() walks
 *    the whole JSON tree and rewrites every string value under a key whose
 *    name contains "color"/"swatch" into this form before saving.
 *  - Absent (older files, or hand-edited JSON): Flutter's native
 *    `#AARRGGBB` - alpha FIRST. 6-digit opaque colors are identical either
 *    way, so this only matters for translucent colors.
 *
 * Every color read out of a project's `properties` must go through here
 * rather than being parsed ad hoc, or translucent colors from older files
 * will come out with the wrong alpha (or a swapped-in red/blue channel, if
 * genuinely misread as the other order).
 */
class ColorConverter
{
    /** @return array{r: int, g: int, b: int, a: float} */
    public static function toRgba(?string $hex, string $colorFormat, string $fallbackHex = '#000000'): array
    {
        $digits = self::normalizeDigits($hex) ?? self::normalizeDigits($fallbackHex);

        if ($digits === null) {
            return ['r' => 0, 'g' => 0, 'b' => 0, 'a' => 1.0];
        }

        if (strlen($digits) === 6) {
            return [
                'r' => hexdec(substr($digits, 0, 2)),
                'g' => hexdec(substr($digits, 2, 2)),
                'b' => hexdec(substr($digits, 4, 2)),
                'a' => 1.0,
            ];
        }

        // 8 digits: order depends on colorFormat.
        if ($colorFormat === 'css-hex') {
            // RRGGBBAA
            return [
                'r' => hexdec(substr($digits, 0, 2)),
                'g' => hexdec(substr($digits, 2, 2)),
                'b' => hexdec(substr($digits, 4, 2)),
                'a' => round(hexdec(substr($digits, 6, 2)) / 255, 4),
            ];
        }

        // AARRGGBB (Flutter native / legacy files)
        return [
            'r' => hexdec(substr($digits, 2, 2)),
            'g' => hexdec(substr($digits, 4, 2)),
            'b' => hexdec(substr($digits, 6, 2)),
            'a' => round(hexdec(substr($digits, 0, 2)) / 255, 4),
        ];
    }

    /** CSS-ready color string - `#rrggbb` when fully opaque (keeps generated HTML readable), `rgba(...)` otherwise. */
    public static function toCss(?string $hex, string $colorFormat, string $fallbackHex = '#000000'): string
    {
        $rgba = self::toRgba($hex, $colorFormat, $fallbackHex);

        if ($rgba['a'] >= 1.0) {
            return sprintf('#%02x%02x%02x', $rgba['r'], $rgba['g'], $rgba['b']);
        }

        return sprintf('rgba(%d, %d, %d, %s)', $rgba['r'], $rgba['g'], $rgba['b'], $rgba['a']);
    }

    private static function normalizeDigits(?string $hex): ?string
    {
        if ($hex === null || $hex === '') {
            return null;
        }

        $digits = ltrim($hex, '#');

        if (strlen($digits) === 3) {
            // Short form RGB -> RRGGBB.
            $digits = $digits[0].$digits[0].$digits[1].$digits[1].$digits[2].$digits[2];
        }

        if (!in_array(strlen($digits), [6, 8], true) || !ctype_xdigit($digits)) {
            return null;
        }

        return $digits;
    }
}
