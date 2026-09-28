<?php

declare(strict_types=1);

namespace Certigniter\CertificateRenderer\Support;

use Certigniter\CertificateRenderer\Data\DesignElement;

/**
 * Renders a `shape` element (rectangle/rounded-rectangle/ellipse/polygon
 * of three or more points, or a library shape - `path`, see
 * PathShapeGeometry) to an SVG data: URI:
 *
 *  - The SVG is embedded as `<img src="data:image/svg+xml;base64,...">`,
 *    not inline `<svg>` markup in the HTML tree. Dompdf renders SVG only
 *    through its image loader (php-svg-lib); an inline `<svg>` element
 *    silently paints nothing.
 *  - `fillEnabled`/`borderEnabled` (default true) drop the fill/stroke
 *    entirely rather than relying on a fully transparent colour, so "No
 *    fill" and "No border" leave nothing in the PDF.
 *  - `borderStyle` (`solid`/`dashed`/`dotted`) sets dash and gap lengths
 *    proportional to the stroke width, so the pattern keeps its rhythm at
 *    any border thickness.
 *  - Corner radius is per-corner (`cornerRadiusTopLeft`/`TopRight`/
 *    `BottomRight`/`BottomLeft`), each falling back to the legacy uniform
 *    `cornerRadius` so pre-existing projects still render the same. A
 *    plain `<rect rx>` only takes one radius, so differing corners are
 *    emitted as a hand-built `<path>` with one arc per corner instead.
 *  - `stroke-linejoin` is `miter` (SVG's default) for rectangles, ellipses
 *    and polygons, so their sharp corners stay sharp.
 *  - A drop shadow (`shadowEnabled`) is drawn behind the shape in
 *    `shadowColor` (default black at 40%), filled whether or not the shape
 *    itself is. Dompdf has no `box-shadow` or SVG `<filter>` support at
 *    all, so the blur (`shadowBlur`, default 3 mm) is approximated by faint
 *    copies spread over the same Gaussian (ShadowBlur). Because an SVG
 *    viewBox clips at its own edges, the shadow needs extra room beyond the
 *    shape's own element bounds; `width`/`height`/`offsetX`/`offsetY`
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
        $shapeType = (string) $element->property('shapeType');
        $strokeWidth = self::strokeWidth($element);

        // Figma-style stroke position for rectangles (`strokeAlign`): inside,
        // the default, strokes the same inset path it fills, exactly as before
        // the property existed. Center and outside fill the box itself and
        // stroke a separate outline on or around it, which can reach past the
        // box, so the SVG is padded to fit.
        $align = in_array($shapeType, ['polygon', 'ellipse'], true) || $strokeWidth <= 0 ? 'inside' : self::strokeAlign($element);
        $split = $align !== 'inside';
        $inset = $split ? 0.0 : $strokeWidth / 2;

        $shapeMarkup = match ($shapeType) {
            'polygon' => self::polygonMarkup($element),
            'ellipse' => self::ellipseMarkup($element, $inset),
            default => self::roundedRectMarkup($element, $inset),
        };
        $strokeMarkup = $split
            ? self::roundedRectMarkup($element, $align === 'outside' ? -$strokeWidth / 2 : 0.0)
            : $shapeMarkup;
        $strokeAttributes = self::strokeAttributes($element, $colorFormat, $strokeWidth, 'miter');

        $gradient = $element->property('gradient');
        if ($element->property('fillEnabled', true) !== false && GradientBands::applies($gradient)) {
            // The bands fill the same outline the flat fill would, then the border is stroked over them.
            $body = self::gradientBands($element, $colorFormat, self::outlinePoints($element, $shapeType, $inset), $gradient)
                .($strokeWidth > 0 ? sprintf('<g fill="none" %s>%s</g>', $strokeAttributes, $strokeMarkup) : '');
        } else {
            $fill = self::flatFill($element, $colorFormat, $gradient);
            $body = $split
                ? sprintf('<g %s stroke="none">%s</g><g fill="none" %s>%s</g>', $fill, $shapeMarkup, $strokeAttributes, $strokeMarkup)
                : sprintf('<g %s %s>%s</g>', $fill, $strokeAttributes, $shapeMarkup);
        }

        $outset = self::strokeOutset($align, $strokeWidth);
        $padding = array_map(fn (float $pad): float => $pad + $outset, self::shadowPadding($element));

        return self::svgImage($element, $padding, self::shadowMarkup($element, $colorFormat, $shapeMarkup).$body);
    }

    /** How far a `strokeAlign` border reaches past the element's box. */
    private static function strokeOutset(string $align, float $strokeWidth): float
    {
        return match ($align) {
            'center' => $strokeWidth / 2,
            'outside' => $strokeWidth,
            default => 0.0,
        };
    }

    /**
     * The filled outline as a polygon, for gradient banding.
     *
     * @return list<array{0: float, 1: float}>
     */
    private static function outlinePoints(DesignElement $element, string $shapeType, float $inset): array
    {
        return match ($shapeType) {
            'polygon' => self::polygonPoints($element),
            'ellipse' => self::ellipsePoints($element, $inset),
            default => self::roundedRectPoints($element, $inset),
        };
    }

    /** The border width, or zero when the border is switched off (`borderEnabled`). */
    private static function strokeWidth(DesignElement $element): float
    {
        return $element->property('borderEnabled', true) !== false
            ? max(0.0, (float) $element->property('strokeWidth', 0.5))
            : 0.0;
    }

    /**
     * Stroke paint, width, dash pattern and caps for a border. Dash and gap
     * lengths are multiples of the stroke width; 'dotted' pairs a near-zero
     * dash with a round cap so each dash paints as a round dot.
     */
    private static function strokeAttributes(DesignElement $element, string $colorFormat, float $strokeWidth, string $linejoin): string
    {
        $borderStyle = (string) $element->property('borderStyle', 'solid');

        return sprintf(
            'stroke="%s" stroke-width="%s"%s stroke-linecap="%s" stroke-linejoin="%s"',
            $strokeWidth > 0 ? ColorConverter::toCss($element->property('strokeColor'), $colorFormat, '#000000') : 'none',
            $strokeWidth,
            self::dashAttribute($borderStyle, $strokeWidth),
            $borderStyle === 'dotted' ? 'round' : 'butt',
            $linejoin,
        );
    }

    private static function dashAttribute(string $borderStyle, float $strokeWidth): string
    {
        return match ($borderStyle) {
            'dashed' => sprintf(' stroke-dasharray="%s %s"', $strokeWidth * 2.5, $strokeWidth * 1.8),
            'dotted' => sprintf(' stroke-dasharray="0.01 %s"', $strokeWidth * 2.2),
            default => '',
        };
    }

    /** A solid fill: the gradient's first colour when it has too few stops to band, else `fillColor`. */
    private static function flatFill(DesignElement $element, string $colorFormat, mixed $gradient): string
    {
        if ($element->property('fillEnabled', true) === false) {
            return 'fill="none"';
        }

        return self::paint('fill', ColorConverter::toRgba(
            GradientBands::applies($gradient) ? null : ($gradient['colors'][0] ?? $element->property('fillColor')),
            $colorFormat,
            '#FFFFFF',
        ));
    }

    /**
     * @param  list<array{0: float, 1: float}>  $outline
     * @param  array{colors: list<string>, angle?: float|int|null}  $gradient
     */
    private static function gradientBands(DesignElement $element, string $colorFormat, array $outline, array $gradient): string
    {
        $bands = '';
        foreach (GradientBands::bands($outline, $gradient, $element->width, $element->height, $colorFormat) as $band) {
            $bands .= sprintf('<polygon points="%s" %s />', self::svgPoints($band['points']), self::paint('fill', $band['color']));
        }

        return $bands;
    }

    /**
     * Wrap drawn content in an SVG widened by `$padding` (left, top, right,
     * bottom) and re-anchored so the element's own box stays at its x/y.
     *
     * @param  array{0: float, 1: float, 2: float, 3: float}  $padding
     * @return array{src: string, width: float, height: float, offsetX: float, offsetY: float}
     */
    private static function svgImage(DesignElement $element, array $padding, string $content): array
    {
        [$padLeft, $padTop, $padRight, $padBottom] = $padding;
        $width = $element->width + $padLeft + $padRight;
        $height = $element->height + $padTop + $padBottom;
        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %s %s"><g transform="translate(%s,%s)">%s</g></svg>',
            $width, $height, $padLeft, $padTop, $content,
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
     * fill. Joins are round, so a thick border does not spike at sharp
     * points.
     *
     * @return array{src: string, width: float, height: float, offsetX: float, offsetY: float}
     */
    private static function renderPath(DesignElement $element, string $colorFormat): array
    {
        $strokeWidth = self::strokeWidth($element);
        $fillEnabled = $element->property('fillEnabled', true) !== false;
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

        $strokeAttributes = $strokeWidth > 0 ? ' '.self::strokeAttributes($element, $colorFormat, $strokeWidth, 'round') : '';

        $body = '';
        $shadowBody = '';
        foreach ($outlines as $outline) {
            $d = PathShapeGeometry::toSvgPath($outline['subpaths']);
            $fill = $fillEnabled ? ColorConverter::toCss($outline['color'], $colorFormat, '#000000') : 'none';
            $body .= sprintf('<path d="%s" fill="%s" fill-rule="%s"%s />', $d, $fill, $outline['rule'], $strokeAttributes);
            $shadowBody .= sprintf('<path d="%s" fill-rule="%s" />', $d, $outline['rule']);
        }

        // A generated ornament can carry megabytes of path data; 48 copies of that would bloat the PDF.
        $shadowMarkup = self::shadowMarkup($element, $colorFormat, $shadowBody, light: strlen($shadowBody) > 100_000);

        return self::svgImage($element, self::shadowPadding($element), $shadowMarkup.$body);
    }

    private static function polygonMarkup(DesignElement $element): string
    {
        return sprintf('<polygon points="%s" />', self::svgPoints(self::polygonPoints($element)));
    }

    /**
     * Any closed polygon of three or more points: triangles, n-gons and
     * stars as well as four-sided shapes.
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
     * inside the box. Drawn as an `<ellipse>` element rather than a pair of
     * `A` arcs, which dompdf can flatten into chords.
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
     * when adjacent radii don't fit, so one corner's radius never changes
     * another's (unlike the proportional scaling of CSS `border-radius`).
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
     * 'outside'. Anything else reads as 'inside'.
     */
    public static function strokeAlign(DesignElement $element): string
    {
        $align = $element->property('strokeAlign');

        return in_array($align, ['inside', 'center', 'outside'], true) ? $align : 'inside';
    }

    /**
     * The element's rounded rect, inset by `$inset` on every side (negative
     * grows it). Radii are clamped against the box shrunk by any positive
     * inset; a grown rect's rounded corners grow by the
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

    /** The shadow's Gaussian standard deviation: `shadowBlur / 2`, with `shadowBlur` defaulting to 3 mm. */
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
        $dashArray = self::dashAttribute($borderStyle, $strokeWidth);

        $body = '';
        $shadowMarkup = '';
        if ($strokeWidth > 0) {
            $stroke = ColorConverter::toRgba($element->property('strokeColor'), $colorFormat, '#000000');
            $body = sprintf(
                '<g %s %s><path d="%s" fill="none" %s%s />%s</g>',
                self::paint('fill', $stroke), self::paint('stroke', $stroke), $path, $lineAttributes, $dashArray, $arrows,
            );
            // A connector's shadow is always solid, even when the connector itself is dashed.
            $shadowMarkup = self::shadowMarkup(
                $element, $colorFormat,
                sprintf('<path d="%s" fill="none" %s />%s', $path, $lineAttributes, $arrows),
                '%s',
            );
        }

        $overhang = ConnectorGeometry::overhang($element, $pixelsPerUnit);
        $padding = array_map(fn (float $pad): float => max($pad, $overhang), self::shadowPadding($element, $overhang));

        return self::svgImage($element, $padding, $shadowMarkup.$body);
    }

    /** @param  array{r: int, g: int, b: int, a: float}  $rgba */
    private static function paint(string $attribute, array $rgba): string
    {
        return sprintf('%1$s="#%2$02x%3$02x%4$02x" %1$s-opacity="%5$s"', $attribute, $rgba['r'], $rgba['g'], $rgba['b'], $rgba['a']);
    }
}
