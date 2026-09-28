<?php

declare(strict_types=1);

namespace Certigniter\CertificateRenderer\Support;

use Certigniter\CertificateRenderer\Data\DesignElement;

/**
 * Renders a `shape` element (rectangle/rounded-rectangle/ellipse/polygon
 * of three or more points, or a library shape - `path`, see
 * PathShapeGeometry) to an SVG data: URI, mirroring batch_pdf_generator.dart's `case
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
 *  - A drop shadow (`shadowEnabled`) is drawn behind the shape in
 *    `shadowColor` (default black at 40%), filled whether or not the shape
 *    itself is, as the Studio fills it. Dompdf has no `box-shadow` or SVG
 *    `<filter>` support at all (confirmed against its source), so the
 *    Studio's blur (`shadowBlur`, default 3 mm) is approximated by faint
 *    copies spread over the same Gaussian (ShadowBlur). Because an SVG
 *    viewBox clips at its own edges, the shadow needs extra room beyond the
 *    shape's own element bounds; [width]/[height]/[offsetX]/[offsetY]
 *    below describe that widened, re-anchored box for the caller to
 *    position instead of the element's raw x/y/width/height.
 *  - A `gradient` fill ({colors, angle}) on a rectangle, ellipse or polygon
 *    is drawn as thin flat bands (GradientBands), since dompdf draws no SVG
 *    gradients.
 *  - Lines and arrows (`shapeType` `line`/`arrow`, ConnectorGeometry) are an
 *    open stroked path with filled arrowheads, never filled themselves.
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
    public static function render(DesignElement $element, string $colorFormat, float $pixelsPerUnit = 96 / 25.4): array
    {
        if ($element->property('shapeType') === 'path') {
            return self::renderPath($element, $colorFormat);
        }
        if (ConnectorGeometry::isConnector($element)) {
            return self::renderConnector($element, $colorFormat, $pixelsPerUnit);
        }
        $isPolygon = $element->property('shapeType') === 'polygon';
        $isEllipse = $element->property('shapeType') === 'ellipse';
        $strokeWidthRaw = max(0.0, (float) $element->property('strokeWidth', 0.5));
        $fillEnabled = $element->property('fillEnabled', true) !== false;
        $borderEnabled = $element->property('borderEnabled', true) !== false;
        $borderStyle = (string) $element->property('borderStyle', 'solid');
        $strokeWidth = $borderEnabled ? $strokeWidthRaw : 0.0;

        $gradient = $element->property('gradient');
        $fill = $fillEnabled
            ? self::paint('fill', ColorConverter::toRgba(
                GradientBands::applies($gradient) ? null : ($gradient['colors'][0] ?? $element->property('fillColor')),
                $colorFormat,
                '#FFFFFF',
            ))
            : 'fill="none"';
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

        // Figma-style stroke position for rectangles (`strokeAlign`): inside,
        // the default, strokes the same inset path it fills, exactly as before
        // the property existed. Center and outside fill the box itself and
        // stroke a separate outline on or around it, which can reach past the
        // box, so the SVG is padded to fit.
        $align = $isPolygon || $isEllipse || $strokeWidth <= 0 ? 'inside' : self::strokeAlign($element);
        $split = $align !== 'inside';
        $outset = match ($align) {
            'center' => $strokeWidth / 2,
            'outside' => $strokeWidth,
            default => 0.0,
        };

        $shapeMarkup = match (true) {
            $isPolygon => self::polygonMarkup($element),
            $isEllipse => self::ellipseMarkup($element, $strokeWidth / 2),
            $split => self::roundedRectMarkup($element, 0.0),
            default => self::roundedRectMarkup($element, $strokeWidth / 2),
        };
        $strokeMarkup = $split
            ? self::roundedRectMarkup($element, $align === 'outside' ? -$strokeWidth / 2 : 0.0)
            : $shapeMarkup;

        [$padLeft, $padTop, $padRight, $padBottom] = array_map(
            fn (float $pad): float => $pad + $outset,
            self::shadowPadding($element),
        );

        $shadowMarkup = self::shadowMarkup($element, $colorFormat, $shapeMarkup);

        $width = $element->width + $padLeft + $padRight;
        $height = $element->height + $padTop + $padBottom;

        $strokeAttributes = sprintf(
            'stroke="%s" stroke-width="%s"%s stroke-linecap="%s" stroke-linejoin="miter"',
            $stroke, $strokeWidth,
            $dashArray !== null ? sprintf(' stroke-dasharray="%s"', $dashArray) : '',
            $linecap,
        );
        if ($fillEnabled && GradientBands::applies($gradient)) {
            // The bands fill the same outline the flat fill would, then the border is stroked over them.
            $outline = match (true) {
                $isPolygon => self::polygonPoints($element),
                $isEllipse => self::ellipsePoints($element, $strokeWidth / 2),
                default => self::roundedRectPoints($element, $split ? 0.0 : $strokeWidth / 2),
            };
            $body = '';
            foreach (GradientBands::bands($outline, $gradient, $element->width, $element->height, $colorFormat) as $band) {
                $body .= sprintf('<polygon points="%s" %s />', self::svgPoints($band['points']), self::paint('fill', $band['color']));
            }
            $body .= $strokeWidth > 0 ? sprintf('<g fill="none" %s>%s</g>', $strokeAttributes, $strokeMarkup) : '';
        } else {
            $body = $split
                ? sprintf('<g %s stroke="none">%s</g><g fill="none" %s>%s</g>', $fill, $shapeMarkup, $strokeAttributes, $strokeMarkup)
                : sprintf('<g %s %s>%s</g>', $fill, $strokeAttributes, $shapeMarkup);
        }

        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %s %s">'
            .'<g transform="translate(%s,%s)">%s%s</g>'
            .'</svg>',
            $width, $height,
            $padLeft, $padTop,
            $shadowMarkup,
            $body,
        );

        return [
            'src' => 'data:image/svg+xml;base64,'.base64_encode($svg),
            'width' => $width,
            'height' => $height,
            'offsetX' => -$padLeft,
            'offsetY' => -$padTop,
        ];
    }

    /**
     * A library shape (`shapeType: 'path'`, see PathShapeGeometry): each part
     * is filled in its own `pathColors` entry and then stroked, in paint order,
     * so a later part covers an earlier one's border the way it covers its
     * fill - the same order the Studio canvas draws. Joins are round, as the
     * Studio draws them, so a thick border does not spike at sharp points.
     *
     * @return array{src: string, width: float, height: float, offsetX: float, offsetY: float}
     */
    private static function renderPath(DesignElement $element, string $colorFormat): array
    {
        $borderEnabled = $element->property('borderEnabled', true) !== false;
        $strokeWidth = $borderEnabled ? max(0.0, (float) $element->property('strokeWidth', 0.5)) : 0.0;
        $fillEnabled = $element->property('fillEnabled', true) !== false;
        $borderStyle = (string) $element->property('borderStyle', 'solid');
        $colors = $element->property('pathColors', []);
        $parts = $element->property('pathParts', []);
        $groups = $element->property('pathGroups', []);
        $outlines = PathShapeGeometry::outlines(
            is_array($colors) ? $colors : [],
            is_array($parts) ? PathShapeGeometry::drawableParts($parts, is_array($groups) ? $groups : []) : [],
            $element->width,
            $element->height,
            $strokeWidth,
            max(0.0, (float) $element->property('cornerRadius', 0.0)),
        );

        $strokeAttributes = '';
        if ($strokeWidth > 0) {
            $dashArray = match ($borderStyle) {
                'dashed' => sprintf(' stroke-dasharray="%s %s"', $strokeWidth * 2.5, $strokeWidth * 1.8),
                'dotted' => sprintf(' stroke-dasharray="0.01 %s"', $strokeWidth * 2.2),
                default => '',
            };
            $strokeAttributes = sprintf(
                ' stroke="%s" stroke-width="%s"%s stroke-linecap="%s" stroke-linejoin="round"',
                ColorConverter::toCss($element->property('strokeColor'), $colorFormat, '#000000'),
                $strokeWidth,
                $dashArray,
                $borderStyle === 'dotted' ? 'round' : 'butt',
            );
        }

        $body = '';
        $shadowBody = '';
        foreach ($outlines as $outline) {
            $d = PathShapeGeometry::toSvgPath($outline['subpaths']);
            $fill = $fillEnabled ? ColorConverter::toCss($outline['color'], $colorFormat, '#000000') : 'none';
            $body .= sprintf('<path d="%s" fill="%s" fill-rule="%s"%s />', $d, $fill, $outline['rule'], $strokeAttributes);
            $shadowBody .= sprintf('<path d="%s" fill-rule="%s" />', $d, $outline['rule']);
        }

        [$padLeft, $padTop, $padRight, $padBottom] = self::shadowPadding($element);
        // A generated ornament can carry megabytes of path data; 48 copies of that would bloat the PDF.
        $shadowMarkup = self::shadowMarkup($element, $colorFormat, $shadowBody, light: strlen($shadowBody) > 100_000);

        $width = $element->width + $padLeft + $padRight;
        $height = $element->height + $padTop + $padBottom;
        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %s %s"><g transform="translate(%s,%s)">%s%s</g></svg>',
            $width, $height, $padLeft, $padTop, $shadowMarkup, $body,
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
        return sprintf('<polygon points="%s" />', self::svgPoints(self::polygonPoints($element)));
    }

    /**
     * Any closed polygon from three points up - the Studio's drawing tools
     * store triangles, n-gons and stars here, not just the four-sided shape
     * the inspector originally edited.
     *
     * @return list<array{float, float}>
     */
    private static function polygonPoints(DesignElement $element): array
    {
        $points = $element->property('points');
        if (! is_array($points) || count($points) < 3) {
            $points = [['x' => 0.0, 'y' => 0.0], ['x' => 1.0, 'y' => 0.0], ['x' => 1.0, 'y' => 1.0], ['x' => 0.0, 'y' => 1.0]];
        }

        return array_map(
            fn (mixed $point): array => [
                max(0, min(1, (float) (is_array($point) ? ($point['x'] ?? 0) : 0))) * $element->width,
                max(0, min(1, (float) (is_array($point) ? ($point['y'] ?? 0) : 0))) * $element->height,
            ],
            array_values($points),
        );
    }

    /** @param  list<array{float, float}>  $points */
    private static function svgPoints(array $points): string
    {
        return implode(' ', array_map(fn (array $point): string => sprintf('%s,%s', $point[0], $point[1]), $points));
    }

    /**
     * The inset ellipse ellipseMarkup() draws, as a polygon fine enough to
     * clip gradient bands to.
     *
     * @return list<array{float, float}>
     */
    private static function ellipsePoints(DesignElement $element, float $inset): array
    {
        $rx = max(0.0, $element->width / 2 - $inset);
        $ry = max(0.0, $element->height / 2 - $inset);
        $points = [];
        for ($step = 0; $step < 128; $step++) {
            $angle = 2 * M_PI * $step / 128;
            $points[] = [$element->width / 2 + $rx * cos($angle), $element->height / 2 + $ry * sin($angle)];
        }

        return $points;
    }

    /**
     * The rounded rect roundedRectMarkup() draws for a non-negative inset,
     * as a polygon fine enough to clip gradient bands to.
     *
     * @return list<array{float, float}>
     */
    private static function roundedRectPoints(DesignElement $element, float $inset): array
    {
        $x0 = $inset;
        $y0 = $inset;
        $x1 = $element->width - $inset;
        $y1 = $element->height - $inset;
        [$tl, $tr, $br, $bl] = self::cornerRadii($element, max(0.0, $x1 - $x0), max(0.0, $y1 - $y0));
        $points = [];
        // Corner centres, each with the angle its quarter-circle starts at, clockwise from the top left.
        foreach ([[$x0 + $tl, $y0 + $tl, $tl, M_PI], [$x1 - $tr, $y0 + $tr, $tr, 1.5 * M_PI], [$x1 - $br, $y1 - $br, $br, 0.0], [$x0 + $bl, $y1 - $bl, $bl, 0.5 * M_PI]] as [$cx, $cy, $radius, $start]) {
            $steps = $radius > 0 ? 16 : 0;
            for ($step = 0; $step <= $steps; $step++) {
                $angle = $start + 0.5 * M_PI * $step / max(1, $steps);
                $points[] = [$cx + $radius * cos($angle), $cy + $radius * sin($angle)];
            }
        }

        return $points;
    }

    /**
     * The element's ellipse, inset by half the stroke so the border stays
     * inside the box - the same radii the Studio canvas draws with
     * (canvasRendering.js's `context.ellipse`). An `<ellipse>` element rather
     * than a pair of `A` arcs, which dompdf can flatten into chords.
     */
    private static function ellipseMarkup(DesignElement $element, float $inset): string
    {
        return sprintf(
            '<ellipse cx="%s" cy="%s" rx="%s" ry="%s" />',
            $element->width / 2,
            $element->height / 2,
            max(0.0, $element->width / 2 - $inset),
            max(0.0, $element->height / 2 - $inset),
        );
    }

    /**
     * (topLeft, topRight, bottomRight, bottomLeft), each clamped
     * independently to half the shorter side - not scaled down together
     * when adjacent radii don't fit. Mirrors the Studio editor's own
     * per-corner clamp (design_element.dart's `_ShapePainter._cornerRadius`
     * / the web Studio's `normalizedCornerRadii`), so what the designer
     * sees while editing matches what gets rendered here: a proportional
     * "shrink everything together" scale (the CSS border-radius rule this
     * used to follow) made one corner's radius visibly affect the others.
     *
     * @return array{float, float, float, float}
     */
    private static function cornerRadii(DesignElement $element, float $width, float $height): array
    {
        $legacy = max(0.0, (float) $element->property('cornerRadius', 0.0));
        $maximum = max(0.0, min($width, $height) / 2);
        $corner = fn (string $key) => min($maximum, max(0.0, (float) $element->property($key, $legacy)));

        return [
            $corner('cornerRadiusTopLeft'),
            $corner('cornerRadiusTopRight'),
            $corner('cornerRadiusBottomRight'),
            $corner('cornerRadiusBottomLeft'),
        ];
    }

    /**
     * `strokeAlign` for a rectangle: 'inside' (the default), 'center' or
     * 'outside'. Mirrors the Studio's strokeAlign() (canvasRendering.js).
     */
    public static function strokeAlign(DesignElement $element): string
    {
        $align = $element->property('strokeAlign');

        return in_array($align, ['inside', 'center', 'outside'], true) ? $align : 'inside';
    }

    /**
     * The element's rounded rect, inset by `$inset` on every side (negative
     * grows it). Radii are clamped against the box shrunk by any positive
     * inset, as the Studio does; a grown rect's rounded corners grow by the
     * same amount so an outside border stays concentric with the box's
     * corners, and square corners stay square.
     */
    private static function roundedRectMarkup(DesignElement $element, float $inset): string
    {
        $x0 = $inset;
        $y0 = $inset;
        $w = max(0.0, $element->width - 2 * $inset);
        $h = max(0.0, $element->height - 2 * $inset);
        $base = max(0.0, $inset);
        $grow = max(0.0, -$inset);
        [$tl, $tr, $br, $bl] = array_map(
            fn (float $radius): float => $radius > 0 ? $radius + $grow : 0.0,
            self::cornerRadii($element, $element->width - 2 * $base, $element->height - 2 * $base),
        );

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
     * clips at its own edges, so without this the shadow's offset and blur
     * would simply be cut off.
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private static function shadowPadding(DesignElement $element, float $spread = 0.0): array
    {
        if ($element->property('shadowEnabled') !== true) {
            return [0.0, 0.0, 0.0, 0.0];
        }

        $dx = (float) $element->property('shadowOffsetX', 2);
        $dy = (float) $element->property('shadowOffsetY', 2);
        $reach = ShadowBlur::reach(self::shadowSigma($element)) + $spread;

        return [max(0.0, $reach - $dx), max(0.0, $reach - $dy), max(0.0, $reach + $dx), max(0.0, $reach + $dy)];
    }

    /** The Studio's shadow blur: CSS `blur()` of `shadowBlur / 2` (a standard deviation), default 3 mm. */
    private static function shadowSigma(DesignElement $element): float
    {
        return max(0.0, (float) $element->property('shadowBlur', 3)) / 2;
    }

    /**
     * The shadow behind a shape: `$body` (unpainted shapes) filled in
     * `shadowColor`, moved by the shadow offset and spread by its blur.
     */
    private static function shadowMarkup(DesignElement $element, string $colorFormat, string $body, string $paintAttributes = '', bool $light = false): string
    {
        if ($element->property('shadowEnabled') !== true || $body === '') {
            return '';
        }

        $color = ColorConverter::toRgba($element->property('shadowColor'), $colorFormat, '#00000066');
        $offsets = ShadowBlur::offsets(self::shadowSigma($element), $light);
        $color['a'] = ShadowBlur::copyAlpha($color['a'], count($offsets));
        $dx = (float) $element->property('shadowOffsetX', 2);
        $dy = (float) $element->property('shadowOffsetY', 2);

        $copies = '';
        foreach ($offsets as [$ox, $oy]) {
            $copies .= sprintf('<g transform="translate(%s,%s)">%s</g>', $dx + $ox, $dy + $oy, $body);
        }

        return sprintf(
            '<g %s %s>%s</g>',
            self::paint('fill', $color),
            $paintAttributes === '' ? 'stroke="none"' : str_replace('%s', sprintf('stroke="#%02x%02x%02x" stroke-opacity="%s"', $color['r'], $color['g'], $color['b'], $color['a']), $paintAttributes),
            $copies,
        );
    }

    /**
     * A line or arrow (ConnectorGeometry): an open stroked path, arrowheads
     * filled in the stroke colour, and nothing drawn at all without a stroke.
     *
     * @return array{src: string, width: float, height: float, offsetX: float, offsetY: float}
     */
    private static function renderConnector(DesignElement $element, string $colorFormat, float $pixelsPerUnit): array
    {
        $strokeWidth = ConnectorGeometry::strokeWidth($element);
        $borderStyle = (string) $element->property('borderStyle', 'solid');
        $layout = ConnectorGeometry::layout($element, $pixelsPerUnit);

        $path = 'M '.implode(' L ', array_map(fn (array $point): string => $point[0].' '.$point[1], $layout['points']));
        $arrows = '';
        foreach ($layout['arrows'] as $arrow) {
            $arrows .= sprintf('<polygon points="%s" stroke="none" />', self::svgPoints($arrow));
        }
        $lineAttributes = sprintf(
            'stroke-width="%s" stroke-linecap="%s" stroke-linejoin="miter"',
            $strokeWidth,
            $borderStyle === 'dotted' ? 'round' : 'butt',
        );
        $dashArray = match ($borderStyle) {
            'dashed' => sprintf(' stroke-dasharray="%s %s"', $strokeWidth * 2.5, $strokeWidth * 1.8),
            'dotted' => sprintf(' stroke-dasharray="0.01 %s"', $strokeWidth * 2.2),
            default => '',
        };

        $body = '';
        $shadowMarkup = '';
        if ($strokeWidth > 0) {
            $stroke = ColorConverter::toRgba($element->property('strokeColor'), $colorFormat, '#000000');
            $body = sprintf(
                '<g %s %s><path d="%s" fill="none" %s%s />%s</g>',
                self::paint('fill', $stroke), self::paint('stroke', $stroke), $path, $lineAttributes, $dashArray, $arrows,
            );
            // The Studio strokes the shadow before setting the dash, so it is always solid.
            $shadowMarkup = self::shadowMarkup(
                $element, $colorFormat,
                sprintf('<path d="%s" fill="none" %s />%s', $path, $lineAttributes, $arrows),
                '%s',
            );
        }

        $overhang = ConnectorGeometry::overhang($element, $pixelsPerUnit);
        [$padLeft, $padTop, $padRight, $padBottom] = array_map(
            fn (float $pad): float => max($pad, $overhang),
            self::shadowPadding($element, $overhang),
        );
        $width = $element->width + $padLeft + $padRight;
        $height = $element->height + $padTop + $padBottom;
        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %s %s"><g transform="translate(%s,%s)">%s%s</g></svg>',
            $width, $height, $padLeft, $padTop, $shadowMarkup, $body,
        );

        return [
            'src' => 'data:image/svg+xml;base64,'.base64_encode($svg),
            'width' => $width,
            'height' => $height,
            'offsetX' => -$padLeft,
            'offsetY' => -$padTop,
        ];
    }

    /** @param  array{r: int, g: int, b: int, a: float}  $rgba */
    private static function paint(string $attribute, array $rgba): string
    {
        return sprintf('%1$s="#%2$02x%3$02x%4$02x" %1$s-opacity="%5$s"', $attribute, $rgba['r'], $rgba['g'], $rgba['b'], $rgba['a']);
    }
}
