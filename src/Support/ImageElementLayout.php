<?php

declare(strict_types=1);

namespace Certigniter\CertificateRenderer\Support;

use Certigniter\CertificateRenderer\Data\DesignElement;

/**
 * Computes an `image` element's fitted inner box (for `fit: contain`'s
 * aspect-ratio-preserving placement) and its mask CSS, extracted verbatim
 * from what used to be an inline `@php` block in certificate.blade.php -
 * see TextElementStyle's docblock for why.
 */
class ImageElementLayout
{
    /** @return array{width: float, height: float, left: float, top: float} */
    public static function fit(DesignElement $element, ?float $imageAspectRatio): array
    {
        $fit = $element->property('fit') === 'fill' ? 'fill' : 'contain';

        if ($fit === 'fill') {
            return ['width' => $element->width, 'height' => $element->height, 'left' => 0.0, 'top' => 0.0];
        }

        $boxRatio = $element->height > 0 ? $element->width / $element->height : null;

        if ($imageAspectRatio && $boxRatio && $imageAspectRatio > $boxRatio) {
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

    public static function maskCss(DesignElement $element, string $unit): string
    {
        return match ((string) $element->property('maskShape', 'none')) {
            'circle' => ' overflow: hidden; border-radius: 50%;',
            'roundedRectangle' => sprintf(
                ' overflow: hidden; border-radius: %s%s;',
                max(0, (float) $element->property('maskCornerRadius', 4.0)),
                $unit,
            ),
            default => '',
        };
    }
}
