<?php

declare(strict_types=1);

namespace Certigniter\CertificateRenderer\Support;

/**
 * The web Studio blurs a shape's shadow (canvas `filter: blur()` with a
 * standard deviation of `shadowBlur / 2`, default 3 mm) and a text shadow
 * (`shadow.blur` px). Neither PDF engine can blur vectors, so the shadow is
 * drawn as several faint copies of the shape spread over the same Gaussian:
 * four rings of twelve, at the radii that split a 2-D Gaussian's mass into
 * equal quarters (each ring at the middle of its quarter). Each copy is translucent enough that all of them stacked
 * reach the shadow colour's own alpha, so the centre keeps the Studio's
 * strength and the edge fades over the Studio's distance.
 *
 * A blur of zero is one copy at full strength, the unblurred shadow.
 */
class ShadowBlur
{
    /** Rings (as fractions of the Gaussian's mass) and copies per ring. */
    private const RINGS = [1 / 8, 3 / 8, 5 / 8, 7 / 8];

    private const PER_RING = 12;

    /**
     * @param  float  $sigma  the blur's standard deviation, in the drawing's unit
     * @param  bool  $light  fewer copies, for shapes heavy enough that 48 copies would bloat the PDF
     * @return list<array{float, float}> offsets from the shadow's own position
     */
    public static function offsets(float $sigma, bool $light = false): array
    {
        if ($sigma <= 0) {
            return [[0.0, 0.0]];
        }

        $rings = $light ? [1 / 2] : self::RINGS;
        $perRing = $light ? 8 : self::PER_RING;
        $offsets = [];
        foreach ($rings as $index => $mass) {
            $radius = $sigma * sqrt(-2 * log(1 - $mass));
            for ($copy = 0; $copy < $perRing; $copy++) {
                // Each ring is turned half a step from the last so the copies interleave.
                $angle = 2 * M_PI * ($copy + ($index % 2) / 2) / $perRing;
                $offsets[] = [$radius * cos($angle), $radius * sin($angle)];
            }
        }

        return $offsets;
    }

    /** The alpha each of `$copies` stacked copies needs so all of them together reach `$alpha`. */
    public static function copyAlpha(float $alpha, int $copies): float
    {
        $alpha = max(0.0, min(1.0, $alpha));

        return $copies <= 1 ? $alpha : 1 - (1 - $alpha) ** (1 / $copies);
    }

    /** How far the copies spread past the unblurred shadow. */
    public static function reach(float $sigma): float
    {
        return $sigma <= 0 ? 0.0 : $sigma * sqrt(-2 * log(1 - self::RINGS[count(self::RINGS) - 1]));
    }
}
