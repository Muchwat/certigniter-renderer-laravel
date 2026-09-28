<?php

declare(strict_types=1);

namespace Certigniter\CertificateRenderer\Support;

/**
 * A shape's linear `gradient` fill ({colors, angle}): evenly spaced stops
 * along the line from
 * ((1 + cos a) / 2, (1 + sin a) / 2) to ((1 - cos a) / 2, (1 - sin a) / 2) of
 * the box, with the end colours carried on past both ends.
 *
 * Dompdf's SVG renderer (php-svg-lib) draws neither gradients nor clip paths,
 * so the fill is cut into thin bands across the gradient, each a flat colour
 * sampled at its middle. Each band is the shape's outline clipped to that
 * strip, so the bands tile the shape exactly without needing a clip. At no
 * more than 0.25 mm apart (and 256 bands at most) the steps are finer than a
 * printed gradient can show.
 */
class GradientBands
{
    /**
     * Whether `$gradient` is a real gradient (two or more colours). With
     * fewer, the shape is filled with its single colour, or with the flat
     * fill colour.
     */
    public static function applies(mixed $gradient): bool
    {
        return is_array($gradient) && is_array($gradient['colors'] ?? null) && count($gradient['colors']) >= 2;
    }

    /**
     * @param  list<array{float, float}>  $outline  the filled shape, as a polygon in the box's own coordinates
     * @param  array{colors: list<string>, angle?: float|int|null}  $gradient
     * @return list<array{points: list<array{float, float}>, color: array{r: int, g: int, b: int, a: float}}>
     */
    public static function bands(array $outline, array $gradient, float $width, float $height, string $colorFormat): array
    {
        $radians = deg2rad(is_numeric($gradient['angle'] ?? null) ? (float) $gradient['angle'] : 45.0);
        $x1 = (1 + cos($radians)) / 2 * $width;
        $y1 = (1 + sin($radians)) / 2 * $height;
        $x2 = (1 - cos($radians)) / 2 * $width;
        $y2 = (1 - sin($radians)) / 2 * $height;
        $dx = $x2 - $x1;
        $dy = $y2 - $y1;
        $length = hypot($dx, $dy);
        $stops = array_map(fn (mixed $color): array => ColorConverter::toRgba(is_string($color) ? $color : null, $colorFormat), array_values($gradient['colors']));

        if ($length == 0.0 || count($outline) < 3) {
            return [['points' => $outline, 'color' => $stops[0]]];
        }

        $count = (int) max(32, min(256, ceil($length / 0.25)));
        // Along the gradient, t = ((p - p1) . d) / |d|^2 runs 0 at the first stop to 1 at the last.
        $along = fn (array $point): float => (($point[0] - $x1) * $dx + ($point[1] - $y1) * $dy) / ($length * $length);
        $opaque = array_reduce($stops, fn (bool $carry, array $stop): bool => $carry && $stop['a'] >= 1.0, true);
        // Opaque bands overlap their neighbour by a hair so no seam of page shows between them.
        $overlap = $opaque ? 0.5 / $count : 0.0;

        $bands = [];
        for ($band = 0; $band < $count; $band++) {
            $from = $band === 0 ? -INF : $band / $count;
            $to = $band === $count - 1 ? INF : ($band + 1) / $count + $overlap;
            $points = $outline;
            if (is_finite($from)) {
                $points = self::clip($points, fn (array $point): float => $along($point) - $from);
            }
            if (is_finite($to)) {
                $points = self::clip($points, fn (array $point): float => $to - $along($point));
            }
            if (count($points) >= 3) {
                $bands[] = ['points' => $points, 'color' => self::colorAt($stops, ($band + 0.5) / $count)];
            }
        }

        return $bands;
    }

    /**
     * Sutherland-Hodgman against one half-plane (`$side` >= 0 is kept). The
     * outline may be concave; the strip it is cut to is convex, which is all
     * the method needs.
     *
     * @param  list<array{float, float}>  $points
     * @param  callable(array{float, float}): float  $side
     * @return list<array{float, float}>
     */
    private static function clip(array $points, callable $side): array
    {
        $kept = [];
        $count = count($points);
        for ($index = 0; $index < $count; $index++) {
            $current = $points[$index];
            $next = $points[($index + 1) % $count];
            $a = $side($current);
            $b = $side($next);
            if ($a >= 0) {
                $kept[] = $current;
            }
            if (($a >= 0) !== ($b >= 0)) {
                $t = $a / ($a - $b);
                $kept[] = [$current[0] + ($next[0] - $current[0]) * $t, $current[1] + ($next[1] - $current[1]) * $t];
            }
        }

        return $kept;
    }

    /**
     * @param  list<array{r: int, g: int, b: int, a: float}>  $stops
     * @return array{r: int, g: int, b: int, a: float}
     */
    private static function colorAt(array $stops, float $t): array
    {
        $position = max(0.0, min(1.0, $t)) * (count($stops) - 1);
        $index = min(count($stops) - 2, (int) floor($position));
        $local = $position - $index;
        $mix = fn (float $a, float $b): float => $a + ($b - $a) * $local;
        [$from, $to] = [$stops[$index], $stops[$index + 1]];

        return [
            'r' => (int) round($mix($from['r'], $to['r'])),
            'g' => (int) round($mix($from['g'], $to['g'])),
            'b' => (int) round($mix($from['b'], $to['b'])),
            'a' => round($mix($from['a'], $to['a']), 4),
        ];
    }
}
