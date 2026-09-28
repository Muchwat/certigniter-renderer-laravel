<?php

declare(strict_types=1);

namespace Certigniter\CertificateRenderer\Support;

use Certigniter\CertificateRenderer\Data\DesignElement;

/**
 * Lines and arrows (`shapeType` `line` / `arrow`), laid out as the web Studio
 * lays them out (canvasRendering.js's `canvasShapeGeometry()`), in the
 * element's own box and unit:
 *
 *  - two stored `points` (fractions of the box) are the ends as they are;
 *  - without them, a straight connector runs bottom-left to top-right and an
 *    elbow one (`connectorStyle: 'elbow'`) steps across at half the width,
 *    both inset by half the stroke;
 *  - an arrowhead is a filled triangle max(4 x stroke, 8px) long and 0.96 of
 *    that wide at the base, at the start with `arrowStart`, and at the end
 *    with `arrowEnd` (default: on for `arrow`, off for `line`);
 *  - nothing is filled, and a connector with no stroke draws nothing.
 */
class ConnectorGeometry
{
    public static function isConnector(DesignElement $element): bool
    {
        return in_array($element->property('shapeType'), ['line', 'arrow'], true);
    }

    public static function strokeWidth(DesignElement $element): float
    {
        return $element->property('borderEnabled', true) === false ? 0.0 : max(0.0, (float) $element->property('strokeWidth', 0.5));
    }

    /**
     * @param  float  $pixelsPerUnit  Studio px (1/96 in) in one of the project's units
     * @return array{points: list<array{float, float}>, arrows: list<list<array{float, float}>>}
     */
    public static function layout(DesignElement $element, float $pixelsPerUnit): array
    {
        $width = max(0.0, $element->width);
        $height = max(0.0, $element->height);
        $stroke = self::strokeWidth($element);
        $inset = $stroke / 2;
        $stored = $element->property('points');
        $clamp = fn (mixed $value): float => max(0.0, min(1.0, is_numeric($value) ? (float) $value : 0.0));

        if (is_array($stored) && count($stored) === 2) {
            $points = array_map(
                fn (mixed $point): array => [$clamp(is_array($point) ? ($point['x'] ?? 0) : 0) * $width, $clamp(is_array($point) ? ($point['y'] ?? 0) : 0) * $height],
                array_values($stored),
            );
        } elseif ($element->property('connectorStyle') === 'elbow') {
            $points = [[$inset, $height - $inset], [$width / 2, $height - $inset], [$width / 2, $inset], [$width - $inset, $inset]];
        } else {
            $points = [[$inset, $height - $inset], [$width - $inset, $inset]];
        }

        $arrows = [];
        if ($stroke > 0) {
            $size = max($stroke * 4, 8 / $pixelsPerUnit);
            /**
             * @param  array{float, float}  $tip
             * @param  array{float, float}  $previous
             * @return list<array{float, float}>|null
             */
            $arrow = function (array $tip, array $previous) use ($size): ?array {
                $length = hypot($tip[0] - $previous[0], $tip[1] - $previous[1]);
                if ($length == 0.0) {
                    return null;
                }
                $dx = ($tip[0] - $previous[0]) / $length;
                $dy = ($tip[1] - $previous[1]) / $length;
                $half = $size * 0.48;
                $bx = $tip[0] - $dx * $size;
                $by = $tip[1] - $dy * $size;

                return [[$tip[0], $tip[1]], [$bx - $dy * $half, $by + $dx * $half], [$bx + $dy * $half, $by - $dx * $half]];
            };
            if ($element->property('arrowStart') && ($head = $arrow($points[0], $points[1])) !== null) {
                $arrows[] = $head;
            }
            if (($element->property('arrowEnd') ?? $element->property('shapeType') === 'arrow')
                && ($head = $arrow($points[count($points) - 1], $points[count($points) - 2])) !== null) {
                $arrows[] = $head;
            }
        }

        return ['points' => $points, 'arrows' => $arrows];
    }

    /**
     * How far past its box a connector can paint: half a stroke round every
     * point, a mitred elbow's corner, and an arrowhead's wings.
     */
    public static function overhang(DesignElement $element, float $pixelsPerUnit): float
    {
        $stroke = self::strokeWidth($element);

        return $stroke * M_SQRT2 / 2 + max($stroke * 4, 8 / $pixelsPerUnit) * 0.48;
    }
}
