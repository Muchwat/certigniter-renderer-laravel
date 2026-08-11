<?php

namespace Certigniter\CertificateRenderer\Support;

use Certigniter\CertificateRenderer\Data\DesignElement;

/**
 * Renders a `shape` element (rectangle/rounded-rectangle/four-sided
 * polygon) to an SVG data: URI, mirroring batch_pdf_generator.dart's `case
 * 'shape':` branch byte-for-byte in intent:
 *
 *  - The SVG is embedded as `<img src="data:image/svg+xml;base64,...">`,
 *    not inline `<svg>` markup in the HTML tree. Dompdf has no renderer
 *    for `<svg>` as an HTML element at all (confirmed against its source:
 *    `Svg\Document` is only ever reached from image loading, e.g.
 *    `Helpers::dompdf_getimagesize`/the image cache) - an inline `<svg>`
 *    tag silently paints nothing, verified by actually rasterizing a test
 *    PDF, not just checking it parses. Wrapping it as an image src is what
 *    makes dompdf's bundled php-svg-lib actually rasterize it.
 *  - `fillEnabled`/`borderEnabled` (default true) drop the fill/stroke
 *    entirely rather than relying on a fully-transparent color, matching
 *    the desktop app's explicit "No Fill"/"No Border" toggles.
 *  - `borderStyle` (`solid`/`dashed`/`dotted`) uses the exact same dash/gap
 *    ratios as design_element.dart's `_ShapePainter._dashedPath` and
 *    batch_pdf_generator.dart, so the border reads identically across the
 *    Studio canvas, the Flutter-issued PDF, and this renderer.
 *  - Corner radius is per-corner (`cornerRadiusTopLeft`/`TopRight`/
 *    `BottomRight`/`BottomLeft`), each falling back to the legacy uniform
 *    `cornerRadius` so pre-existing projects still render the same. A
 *    plain `<rect rx>` only takes one radius, so differing corners are
 *    emitted as a hand-built `<path>` with one arc per corner instead.
 *  - `stroke-linejoin` is always `miter` (SVG's own default). Certigniter
 *    used to hardcode `round` here, which visibly rounded off rectangle/
 *    polygon corners that were sharp in the Studio canvas - see the fix in
 *    batch_pdf_generator.dart for the same bug on the Flutter PDF side.
 *  - A drop shadow (`shadowEnabled`) is drawn as a second, translated,
 *    flat-filled copy of the same shape behind it - dompdf has no
 *    `box-shadow` or SVG `<filter>` support at all (confirmed against its
 *    source), so - exactly like package:pdf's SVG renderer - blur is not
 *    available and intentionally not attempted. Because an SVG viewBox
 *    clips at its own edges, the offset shadow needs extra room beyond the
 *    shape's own element bounds; [width]/[height]/[offsetX]/[offsetY]
 *    below describe that widened, re-anchored box for the caller to
 *    position instead of the element's raw x/y/width/height.
 */
class ShapeRenderer
{
    /**
     * @return array{
     *     src: string,
     *     width: float,
     *     height: float,
     *     offsetX: float,
     *     offsetY: float,
     * } `src` is a ready-to-use `data:image/svg+xml;base64,...` URI for an
     *   `<img>` tag; `offsetX`/`offsetY` are how far to shift the wrapper's
     *   left/top from the element's own x/y (zero, or negative, when a
     *   shadow needs room on the left/top).
     */
    public static function render(DesignElement $element, string $colorFormat): array
    {
        $isPolygon = $element->property('shapeType') === 'polygon';
        $strokeWidthRaw = max(0.0, (float) $element->property('strokeWidth', 0.5));
        $fillEnabled = $element->property('fillEnabled', true) !== false;
        $borderEnabled = $element->property('borderEnabled', true) !== false;
        $borderStyle = (string) $element->property('borderStyle', 'solid');
        $strokeWidth = $borderEnabled ? $strokeWidthRaw : 0.0;

        $fill = $fillEnabled
            ? ColorConverter::toCss($element->property('fillColor'), $colorFormat, '#FFFFFF')
            : 'none';
        $stroke = $strokeWidth > 0
            ? ColorConverter::toCss($element->property('strokeColor'), $colorFormat, '#000000')
            : 'none';

        // Dash lengths mirror _ShapePainter._dashedPath / batch_pdf_generator.dart
        // exactly. 'dotted' pairs a near-zero dash with a round cap so each
        // dash paints as a round dot instead of a short line.
        $dashArray = match ($borderStyle) {
            'dashed' => sprintf('%s %s', $strokeWidth * 2.5, $strokeWidth * 1.8),
            'dotted' => sprintf('0.01 %s', $strokeWidth * 2.2),
            default => null,
        };
        $linecap = $borderStyle === 'dotted' ? 'round' : 'butt';

        $shapeMarkup = $isPolygon
            ? self::polygonMarkup($element)
            : self::roundedRectMarkup($element, $strokeWidth);

        [$padLeft, $padTop, $padRight, $padBottom] = self::shadowPadding($element);

        $shadowMarkup = '';
        if ($element->property('shadowEnabled') === true) {
            $shadowColor = ColorConverter::toCss($element->property('shadowColor'), $colorFormat, '#66000000');
            $dx = (float) $element->property('shadowOffsetX', 2);
            $dy = (float) $element->property('shadowOffsetY', 2);
            $shadowMarkup = sprintf(
                '<g transform="translate(%s,%s)" fill="%s">%s</g>',
                $dx, $dy, $shadowColor, $shapeMarkup,
            );
        }

        $width = $element->width + $padLeft + $padRight;
        $height = $element->height + $padTop + $padBottom;

        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %s %s">'
            .'<g transform="translate(%s,%s)">%s<g fill="%s" stroke="%s" stroke-width="%s"%s stroke-linecap="%s" stroke-linejoin="miter">%s</g></g>'
            .'</svg>',
            $width, $height,
            $padLeft, $padTop,
            $shadowMarkup,
            $fill, $stroke, $strokeWidth,
            $dashArray !== null ? sprintf(' stroke-dasharray="%s"', $dashArray) : '',
            $linecap,
            $shapeMarkup,
        );

        return [
            'src' => 'data:image/svg+xml;base64,'.base64_encode($svg),
            'width' => $width,
            'height' => $height,
            'offsetX' => -$padLeft,
            'offsetY' => -$padTop,
        ];
    }

    private static function polygonMarkup(DesignElement $element): string
    {
        $points = $element->property('points', [
            ['x' => 0.0, 'y' => 0.0], ['x' => 1.0, 'y' => 0.0],
            ['x' => 1.0, 'y' => 1.0], ['x' => 0.0, 'y' => 1.0],
        ]);

        if (! is_array($points) || count($points) !== 4) {
            $points = [
                ['x' => 0.0, 'y' => 0.0], ['x' => 1.0, 'y' => 0.0],
                ['x' => 1.0, 'y' => 1.0], ['x' => 0.0, 'y' => 1.0],
            ];
        }

        $svgPoints = implode(' ', array_map(
            fn ($point) => sprintf(
                '%s,%s',
                max(0, min(1, (float) ($point['x'] ?? 0))) * $element->width,
                max(0, min(1, (float) ($point['y'] ?? 0))) * $element->height,
            ),
            $points,
        ));

        return sprintf('<polygon points="%s" />', $svgPoints);
    }

    /**
     * (topLeft, topRight, bottomRight, bottomLeft), proportionally scaled
     * when adjacent radii do not fit. Flutter's RRect uses the same global
     * scale, preserving asymmetric corner proportions.
     */
    private static function cornerRadii(DesignElement $element, float $width, float $height): array
    {
        $legacy = max(0.0, (float) $element->property('cornerRadius', 0.0));
        $corner = fn (string $key) => max(0.0, (float) $element->property($key, $legacy));
        $tl = $corner('cornerRadiusTopLeft');
        $tr = $corner('cornerRadiusTopRight');
        $br = $corner('cornerRadiusBottomRight');
        $bl = $corner('cornerRadiusBottomLeft');
        $scale = 1.0;
        $constrain = static function (float $available, float $requested) use (&$scale): void {
            if ($requested > 0) {
                $scale = min($scale, max(0.0, $available) / $requested);
            }
        };

        $constrain($width, $tl + $tr);
        $constrain($width, $bl + $br);
        $constrain($height, $tl + $bl);
        $constrain($height, $tr + $br);

        return [
            $tl * $scale,
            $tr * $scale,
            $br * $scale,
            $bl * $scale,
        ];
    }

    private static function roundedRectMarkup(DesignElement $element, float $strokeWidth): string
    {
        $x0 = $strokeWidth / 2;
        $y0 = $strokeWidth / 2;
        $w = max(0.0, $element->width - $strokeWidth);
        $h = max(0.0, $element->height - $strokeWidth);
        [$tl, $tr, $br, $bl] = self::cornerRadii($element, $w, $h);

        if ($tl === $tr && $tr === $br && $br === $bl) {
            return sprintf('<rect x="%s" y="%s" width="%s" height="%s" rx="%s" />', $x0, $y0, $w, $h, $tl);
        }

        // A plain <rect rx> only takes one uniform radius. Use cubic Bezier
        // quarter-circles for differing corners instead of SVG `A` arcs:
        // dompdf/php-svg-lib intermittently flattens consecutive arc commands
        // into chords, producing bevelled alternating corners in the PDF.
        // Cubics are supported reliably and approximate the same circles to
        // substantially better than screen/PDF raster resolution.
        $kappa = 0.5522847498307936;

        return '<path d="'
            .'M '.($x0 + $tl).' '.$y0.' '
            .'L '.($x0 + $w - $tr).' '.$y0.' '
            .'C '.($x0 + $w - $tr + $kappa * $tr).' '.$y0.' '
                .($x0 + $w).' '.($y0 + $tr - $kappa * $tr).' '
                .($x0 + $w).' '.($y0 + $tr).' '
            .'L '.($x0 + $w).' '.($y0 + $h - $br).' '
            .'C '.($x0 + $w).' '.($y0 + $h - $br + $kappa * $br).' '
                .($x0 + $w - $br + $kappa * $br).' '.($y0 + $h).' '
                .($x0 + $w - $br).' '.($y0 + $h).' '
            .'L '.($x0 + $bl).' '.($y0 + $h).' '
            .'C '.($x0 + $bl - $kappa * $bl).' '.($y0 + $h).' '
                .$x0.' '.($y0 + $h - $bl + $kappa * $bl).' '
                .$x0.' '.($y0 + $h - $bl).' '
            ."L {$x0} ".($y0 + $tl).' '
            .'C '.$x0.' '.($y0 + $tl - $kappa * $tl).' '
                .($x0 + $tl - $kappa * $tl).' '.$y0.' '
                .($x0 + $tl).' '.$y0.' '
            .'Z" />';
    }

    /**
     * (left, top, right, bottom) extra room, in the project's own unit, a
     * shape's shadow needs beyond its own element bounds - an SVG viewBox
     * clips at its own edges, so without this the shadow's offset portion
     * would simply be cut off.
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private static function shadowPadding(DesignElement $element): array
    {
        if ($element->property('shadowEnabled') !== true) {
            return [0.0, 0.0, 0.0, 0.0];
        }

        $dx = (float) $element->property('shadowOffsetX', 2);
        $dy = (float) $element->property('shadowOffsetY', 2);

        return [max(0.0, -$dx), max(0.0, -$dy), max(0.0, $dx), max(0.0, $dy)];
    }
}
