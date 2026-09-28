<?php

declare(strict_types=1);

namespace Certigniter\CertificateRenderer\Support;

use Certigniter\CertificateRenderer\Data\DesignElement;

/**
 * Lays out text on a circular arc.
 *
 * A text element's `curveRadius` is the signed radius, in mm, of the circle
 * the middle of its line box follows: positive arches the text over the top
 * of the circle, negative bends it along the bottom, 0 or absent is straight.
 * The text runs on one line (line breaks read as spaces). In the element's own
 * box (w x h, y down) the circle's centre is (w / 2, L / 2 + R) for an arch and
 * (w / 2, h - L / 2 - R) for a dip, L the line height, so the line's apex
 * touches the top (or bottom) edge. Each glyph's line box is centred on the
 * circle at its advance's midpoint and turned to stand upright on it.
 */
final class CurvedTextLayout
{
    /** The element's stored radius in mm, or 0 for straight text. */
    public static function radius(DesignElement $element): float
    {
        $radius = $element->property('curveRadius');

        return is_numeric($radius) ? (float) $radius : 0.0;
    }

    /** The text as the one line a curve carries. */
    public static function line(string $text): string
    {
        return (string) preg_replace('/\s*(?:\r\n?|\n)\s*/u', ' ', $text);
    }

    /**
     * Each character with its advance along the line, measured as prefixes of
     * the whole line so kerning is kept.
     *
     * @param  callable(string): float  $measure
     * @return list<array{text: string, start: float, end: float}>
     */
    public static function glyphs(string $line, callable $measure): array
    {
        $characters = function_exists('grapheme_str_split') ? grapheme_str_split($line) : mb_str_split($line);
        $glyphs = [];
        $prefix = '';
        $start = 0.0;
        foreach ($characters === false ? [] : $characters as $character) {
            $prefix .= $character;
            $end = (float) $measure($prefix);
            $glyphs[] = ['text' => $character, 'start' => $start, 'end' => $end];
            $start = $end;
        }

        return $glyphs;
    }

    /**
     * Where each glyph's line box is centred in the element's box, and its
     * clockwise turn in radians. Every length in one unit.
     *
     * @param  list<array{text: string, start: float, end: float}>  $glyphs
     * @return list<array{text: string, start: float, end: float, x: float, y: float, angle: float}>
     */
    public static function place(array $glyphs, float $radius, float $lineHeight, float $width, float $height): array
    {
        $total = $glyphs === [] ? 0.0 : $glyphs[count($glyphs) - 1]['end'];
        $r = abs($radius);
        $arch = $radius > 0;
        $centreX = $width / 2;
        $centreY = $arch ? $lineHeight / 2 + $r : $height - $lineHeight / 2 - $r;

        return array_map(function (array $glyph) use ($total, $r, $arch, $centreX, $centreY): array {
            $angle = (($glyph['start'] + $glyph['end']) / 2 - $total / 2) / $r;

            return $glyph + [
                'x' => $centreX + $r * sin($angle),
                'y' => $arch ? $centreY - $r * cos($angle) : $centreY + $r * cos($angle),
                'angle' => $arch ? $angle : -$angle,
            ];
        }, $glyphs);
    }
}
