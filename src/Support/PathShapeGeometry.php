<?php

declare(strict_types=1);

namespace Certigniter\CertificateRenderer\Support;

/**
 * Geometry for a library shape (`shapeType: 'path'`).
 *
 * A path shape stores `pathParts` (`d` in absolute M/L/C/Z over the 0..1
 * box, plus a `color` index and an optional `rule`) and `pathColors`. Each
 * part is stretched to the element box inset by half the stroke, and every
 * sharp corner (a turn of 12 degrees or more) is rounded by the radius: both
 * sides are cut back by it, never more than half their length, and joined by
 * a curve whose control point is the old corner.
 */
final class PathShapeGeometry
{
    private const CORNER_DEGREES = 12;

    /**
     * The parts to draw: every part except hidden ones, meaning
     * `hidden: true` on the part itself or on any vector group it sits in
     * (`pathGroups`, each `{id, parent?, hidden?}`, and the part's innermost
     * `group`). Groups only organise the parts; the parts stay one flat list
     * in paint order. `outlines()` itself stays unfiltered, so a part's
     * index always refers to the same outline.
     *
     * @param  array<int, mixed>  $parts
     * @param  array<int, mixed>  $groups
     * @return list<mixed>
     */
    public static function drawableParts(array $parts, array $groups = []): array
    {
        $parentOf = [];
        $hidden = [];
        foreach ($groups as $group) {
            if (is_array($group) && is_string($group['id'] ?? null)) {
                $parentOf[$group['id']] = is_string($group['parent'] ?? null) ? $group['parent'] : null;
                $hidden[$group['id']] = ($group['hidden'] ?? false) === true;
            }
        }
        $groupHidden = function (?string $id) use ($parentOf, $hidden): bool {
            // A corrupt project could nest groups in a loop; each group is visited once.
            $seen = [];
            while ($id !== null && array_key_exists($id, $parentOf) && ! isset($seen[$id])) {
                if ($hidden[$id]) {
                    return true;
                }
                $seen[$id] = true;
                $id = $parentOf[$id];
            }

            return false;
        };

        return array_values(array_filter($parts, fn (mixed $part): bool => ! (is_array($part)
            && (($part['hidden'] ?? false) === true || $groupHidden(is_string($part['group'] ?? null) ? $part['group'] : null)))));
    }

    /**
     * @param  array<int, mixed>  $colors
     * @param  array<int, mixed>  $parts
     * @return list<array{subpaths: list<array{start: array{float, float}, segments: list<array{type: string, points: list<float>}>, closed: bool}>, color: string, rule: string}>
     */
    public static function outlines(array $colors, array $parts, float $width, float $height, float $stroke = 0.0, float $radius = 0.0): array
    {
        $inset = $stroke / 2;
        $innerWidth = max(0.0, $width - $stroke);
        $innerHeight = max(0.0, $height - $stroke);
        $place = fn (float $x, float $y): array => [$inset + $x * $innerWidth, $inset + $y * $innerHeight];

        $outlines = [];
        foreach ($parts as $part) {
            if (! is_array($part)) {
                continue;
            }
            $subpaths = [];
            foreach (self::parse((string) ($part['d'] ?? '')) as $subpath) {
                $placed = [
                    'start' => $place(...$subpath['start']),
                    'closed' => $subpath['closed'],
                    'segments' => array_map(function (array $segment) use ($place): array {
                        $points = [];
                        for ($i = 0; $i < count($segment['points']); $i += 2) {
                            array_push($points, ...$place($segment['points'][$i], $segment['points'][$i + 1]));
                        }

                        return ['type' => $segment['type'], 'points' => $points];
                    }, $subpath['segments']),
                ];
                $subpaths[] = self::roundSubpath($placed, $radius) ?? $placed;
            }
            $color = $colors[(int) ($part['color'] ?? -1)] ?? null;
            $outlines[] = [
                'subpaths' => $subpaths,
                'color' => is_string($color) ? $color : '#000000',
                'rule' => ($part['rule'] ?? null) === 'evenodd' ? 'evenodd' : 'nonzero',
            ];
        }

        return $outlines;
    }

    /**
     * @param  list<array{start: array{float, float}, segments: list<array{type: string, points: list<float>}>, closed: bool}>  $subpaths
     */
    public static function toSvgPath(array $subpaths): string
    {
        $format = fn (float $value): string => rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.') ?: '0';
        $commands = [];
        foreach ($subpaths as $subpath) {
            $commands[] = 'M '.$format($subpath['start'][0]).' '.$format($subpath['start'][1]);
            foreach ($subpath['segments'] as $segment) {
                $commands[] = $segment['type'].' '.implode(' ', array_map($format, $segment['points']));
            }
            if ($subpath['closed']) {
                $commands[] = 'Z';
            }
        }

        return implode(' ', $commands);
    }

    /**
     * Stored path data (absolute M, L, C and Z only) to subpaths.
     *
     * @return list<array{start: array{float, float}, segments: list<array{type: string, points: list<float>}>, closed: bool}>
     */
    public static function parse(string $d): array
    {
        preg_match_all('/[MLCZ]|[-+]?(?:\d*\.\d+|\d+\.?)(?:[eE][-+]?\d+)?/', $d, $matches);
        $tokens = $matches[0];
        $subpaths = [];
        // The subpath being drawn; a Z closes it, and anything drawn after a Z before the next M is ignored.
        $open = null;
        $command = null;
        $index = 0;
        $count = count($tokens);
        $finish = function (?array $subpath) use (&$subpaths): void {
            if ($subpath !== null && $subpath['segments'] !== []) {
                $subpaths[] = $subpath;
            }
        };

        while ($index < $count) {
            if (in_array($tokens[$index], ['M', 'L', 'C', 'Z'], true)) {
                $command = $tokens[$index++];
            } elseif ($command === null) {
                break;
            }
            if ($command === 'Z') {
                if ($open !== null) {
                    $open['closed'] = true;
                }
                $finish($open);
                $open = null;

                continue;
            }
            $needed = $command === 'C' ? 6 : 2;
            if ($index + $needed > $count) {
                break;
            }
            $values = array_values(array_map('floatval', array_slice($tokens, $index, $needed)));
            $index += $needed;
            if ($command === 'M') {
                $finish($open);
                $open = ['start' => [$values[0], $values[1]], 'segments' => [], 'closed' => false];
                $command = 'L';

                continue;
            }
            if ($open !== null) {
                $open['segments'][] = ['type' => $command, 'points' => $values];
            }
        }
        $finish($open);

        /** @var list<array{start: array{float, float}, segments: list<array{type: string, points: list<float>}>, closed: bool}> $subpaths */
        return $subpaths;
    }

    /**
     * @param  array{start: array{float, float}, segments: list<array{type: string, points: list<float>}>, closed: bool}  $subpath
     * @return array{start: array{float, float}, segments: list<array{type: string, points: list<float>}>, closed: bool}|null
     */
    private static function roundSubpath(array $subpath, float $radius): ?array
    {
        $segments = [];
        $end = $subpath['start'];
        foreach ($subpath['segments'] as $segment) {
            $p = $segment['points'];
            $points = $segment['type'] === 'C'
                ? [$end, [$p[0], $p[1]], [$p[2], $p[3]], [$p[4], $p[5]]]
                : [$end, [$p[0], $p[1]]];
            $end = $points[count($points) - 1];
            if (self::distance($points[0], $end) > 1e-9 || count($points) === 4) {
                $segments[] = $points;
            }
        }
        if (self::distance($end, $subpath['start']) > 1e-9) {
            $segments[] = [$end, $subpath['start']];
        }
        $total = count($segments);
        if (! $subpath['closed'] || $radius <= 0 || $total < 2) {
            return null;
        }

        $lengths = array_map([self::class, 'segmentLength'], $segments);
        $threshold = cos(deg2rad(self::CORNER_DEGREES));
        $cuts = [];
        foreach ($segments as $i => $points) {
            $previous = ($i + $total - 1) % $total;
            $incoming = self::tangent($segments[$previous], true);
            $outgoing = self::tangent($points, false);
            $cuts[$i] = ($incoming === null || $outgoing === null || $threshold < $incoming[0] * $outgoing[0] + $incoming[1] * $outgoing[1])
                ? 0.0
                : min($radius, $lengths[$previous] / 2, $lengths[$i] / 2);
        }
        $trimmed = [];
        foreach ($segments as $i => $points) {
            $length = $lengths[$i] ?: 1.0;
            $trimmed[$i] = self::subSegment($points, $cuts[$i] / $length, 1 - $cuts[($i + 1) % $total] / $length);
        }

        $result = ['start' => $trimmed[0][0], 'segments' => [], 'closed' => true];
        foreach ($trimmed as $i => $points) {
            $result['segments'][] = count($points) === 2
                ? ['type' => 'L', 'points' => $points[1]]
                : ['type' => 'C', 'points' => [...$points[1], ...$points[2], ...$points[3]]];
            $next = ($i + 1) % $total;
            if ($cuts[$next] > 0) {
                $corner = $segments[$next][0];
                $from = $points[count($points) - 1];
                $to = $trimmed[$next][0];
                $result['segments'][] = ['type' => 'C', 'points' => [
                    ...self::lerp($from, $corner, 2 / 3), ...self::lerp($to, $corner, 2 / 3), ...$to,
                ]];
            }
        }

        return $result;
    }

    /**
     * @param  array{float, float}  $a
     * @param  array{float, float}  $b
     */
    private static function distance(array $a, array $b): float
    {
        return hypot($b[0] - $a[0], $b[1] - $a[1]);
    }

    /**
     * @param  array{float, float}  $a
     * @param  array{float, float}  $b
     * @return array{float, float}
     */
    private static function lerp(array $a, array $b, float $t): array
    {
        return [$a[0] + ($b[0] - $a[0]) * $t, $a[1] + ($b[1] - $a[1]) * $t];
    }

    private static function cubicAt(float $p0, float $c1, float $c2, float $p3, float $t): float
    {
        $u = 1 - $t;

        return $u * $u * $u * $p0 + 3 * $u * $u * $t * $c1 + 3 * $u * $t * $t * $c2 + $t * $t * $t * $p3;
    }

    /**
     * @param  list<array{float, float}>  $points
     */
    private static function segmentLength(array $points): float
    {
        if (count($points) === 2) {
            return self::distance($points[0], $points[1]);
        }
        $length = 0.0;
        $previous = $points[0];
        for ($i = 1; $i <= 8; $i++) {
            $t = $i / 8;
            $current = [
                self::cubicAt($points[0][0], $points[1][0], $points[2][0], $points[3][0], $t),
                self::cubicAt($points[0][1], $points[1][1], $points[2][1], $points[3][1], $t),
            ];
            $length += self::distance($previous, $current);
            $previous = $current;
        }

        return $length;
    }

    /**
     * Unit direction leaving the segment's start, or arriving at its end.
     *
     * @param  list<array{float, float}>  $points
     * @return array{float, float}|null
     */
    private static function tangent(array $points, bool $atEnd): ?array
    {
        $ordered = $atEnd ? array_reverse($points) : $points;
        for ($i = 1; $i < count($ordered); $i++) {
            $length = self::distance($ordered[0], $ordered[$i]);
            if ($length > 1e-9) {
                $direction = [($ordered[$i][0] - $ordered[0][0]) / $length, ($ordered[$i][1] - $ordered[0][1]) / $length];

                return $atEnd ? [-$direction[0], -$direction[1]] : $direction;
            }
        }

        return null;
    }

    /**
     * @param  list<array{float, float}>  $points
     * @return list<array{float, float}>
     */
    private static function subSegment(array $points, float $t0, float $t1): array
    {
        if (count($points) === 2) {
            return [self::lerp($points[0], $points[1], $t0), self::lerp($points[0], $points[1], $t1)];
        }
        $split = function (array $p, float $t): array {
            $a = self::lerp($p[0], $p[1], $t);
            $b = self::lerp($p[1], $p[2], $t);
            $c = self::lerp($p[2], $p[3], $t);
            $d = self::lerp($a, $b, $t);
            $e = self::lerp($b, $c, $t);
            $f = self::lerp($d, $e, $t);

            return [[$p[0], $a, $d, $f], [$f, $e, $c, $p[3]]];
        };
        $curve = $points;
        if ($t1 < 1) {
            $curve = $split($curve, $t1)[0];
        }
        if ($t0 > 0) {
            $curve = $split($curve, $t0 / $t1)[1];
        }

        return $curve;
    }
}
