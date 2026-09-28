<?php

declare(strict_types=1);

namespace Certigniter\CertificateRenderer\Support;

use Certigniter\CertificateRenderer\Data\DesignElement;

/**
 * Computes an `image` element's fitted inner box (`fit: contain`/`cover`'s
 * aspect-ratio-preserving placement), its crop and its mask, as the Studio's
 * imagePlacement()/canvasImageMask() do - see TextElementStyle's docblock for
 * why this lives outside certificate.blade.php.
 */
class ImageElementLayout
{
    private const MIN_CROP_FRACTION = 0.002;

    /**
     * The element's non-destructive crop - `cropX`/`cropY`/`cropWidth`/
     * `cropHeight`, fractions (0-1) of the original picture - or null when it
     * has none: absent, partial, non-numeric, or covering the whole picture.
     * Mirrors the Studio's imageCropRect() (resources/js/utils/imageCrop.js).
     *
     * @return array{x: float, y: float, width: float, height: float}|null
     */
    public static function crop(DesignElement $element): ?array
    {
        $values = [];
        foreach (['cropX', 'cropY', 'cropWidth', 'cropHeight'] as $key) {
            $value = $element->property($key);
            if (! is_int($value) && ! is_float($value)) {
                return null;
            }
            $values[] = (float) $value;
        }

        [$x, $y, $width, $height] = $values;
        $width = min(1.0, max(self::MIN_CROP_FRACTION, $width));
        $height = min(1.0, max(self::MIN_CROP_FRACTION, $height));
        $x = min(1.0 - $width, max(0.0, $x));
        $y = min(1.0 - $height, max(0.0, $y));

        if ($x < 1e-6 && $y < 1e-6 && $width > 1 - 1e-6 && $height > 1 - 1e-6) {
            return null;
        }

        return ['x' => $x, 'y' => $y, 'width' => $width, 'height' => $height];
    }

    /**
     * Where the whole picture has to be drawn, relative to the fitted box, for
     * that box to show only the cropped region - null for an uncropped image.
     * The box itself is fit() over the cropped region's aspect ratio.
     *
     * @param  array{width: float, height: float, left: float, top: float}  $fitted
     * @return array{width: float, height: float, left: float, top: float}|null
     */
    public static function cropPlacement(DesignElement $element, array $fitted): ?array
    {
        $crop = self::crop($element);
        if ($crop === null) {
            return null;
        }

        $width = $fitted['width'] / $crop['width'];
        $height = $fitted['height'] / $crop['height'];

        return [
            'width' => $width,
            'height' => $height,
            'left' => -$crop['x'] * $width,
            'top' => -$crop['y'] * $height,
        ];
    }

    /**
     * The fitted box of the picture - or, for a cropped one, of the region it
     * shows, so `contain` letterboxes by the crop's shape, not the source's.
     *
     * @return array{width: float, height: float, left: float, top: float}
     */
    public static function fit(DesignElement $element, ?float $imageAspectRatio): array
    {
        $fit = in_array($element->property('fit'), ['fill', 'cover'], true) ? $element->property('fit') : 'contain';

        $crop = self::crop($element);
        if ($crop !== null && $imageAspectRatio) {
            $imageAspectRatio *= $crop['width'] / $crop['height'];
        }

        if ($fit === 'fill') {
            return ['width' => $element->width, 'height' => $element->height, 'left' => 0.0, 'top' => 0.0];
        }

        $boxRatio = $element->height > 0 ? $element->width / $element->height : null;

        // Contain fits the picture's longer side to the box; cover (which the
        // Studio's imagePlacement() draws too) fits its shorter side and lets
        // the rest run past the box, which clips it.
        if ($imageAspectRatio && $boxRatio && ($imageAspectRatio > $boxRatio) !== ($fit === 'cover')) {
            $width = $element->width;
            $height = $element->width / $imageAspectRatio;
        } elseif ($imageAspectRatio) {
            $height = $element->height;
            $width = $element->height * $imageAspectRatio;
        } else {
            $width = $element->width;
            $height = $element->height;
        }

        [$contentX, $contentY] = $element->contentAlignmentFactors();

        return [
            'width' => $width,
            'height' => $height,
            'left' => ($element->width - $width) * $contentX,
            'top' => ($element->height - $height) * $contentY,
        ];
    }

    /**
     * The image's mask in its own box, as the Studio's canvasImageMask()
     * works it out, or null for none (`maskEnabled: false`, or no or an
     * unknown `maskShape`): a circle of min(width, height) / 2 x
     * `maskRadiusFactor`, or a rounded rectangle of `maskWidthFactor` x
     * `maskHeightFactor` of the box (each clamped to 0.05-1) with corners of
     * `maskCornerRadius` (default 4), both centred.
     *
     * @return array{shape: 'circle'|'roundedRectangle', left: float, top: float, width: float, height: float, radius: float}|null
     */
    public static function mask(DesignElement $element): ?array
    {
        $shape = $element->property('maskShape');
        if ($element->property('maskEnabled') === false || ! in_array($shape, ['circle', 'roundedRectangle'], true)) {
            return null;
        }

        $factor = fn (string $key): float => max(0.05, min(1.0, is_numeric($element->property($key)) ? (float) $element->property($key) : 1.0));

        if ($shape === 'circle') {
            $radius = min($element->width, $element->height) / 2 * $factor('maskRadiusFactor');

            return [
                'shape' => 'circle',
                'left' => $element->width / 2 - $radius,
                'top' => $element->height / 2 - $radius,
                'width' => 2 * $radius,
                'height' => 2 * $radius,
                'radius' => $radius,
            ];
        }

        $width = $element->width * $factor('maskWidthFactor');
        $height = $element->height * $factor('maskHeightFactor');
        $cornerRadius = is_numeric($element->property('maskCornerRadius')) ? max(0.0, (float) $element->property('maskCornerRadius')) : 4.0;

        return [
            'shape' => 'roundedRectangle',
            'left' => ($element->width - $width) / 2,
            'top' => ($element->height - $height) / 2,
            'width' => $width,
            'height' => $height,
            'radius' => min($cornerRadius, $width / 2, $height / 2),
        ];
    }
}
