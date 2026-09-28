<?php

declare(strict_types=1);

namespace Certigniter\CertificateRenderer\Support;

use Certigniter\CertificateRenderer\Data\DesignElement;

/**
 * Draws a QR code element as an SVG in the element's own box and unit:
 *
 *  - a background square of the box's shorter side, centred in the box;
 *  - `padding` (CSS pixels, default 2) of quiet zone inside that square;
 *  - square or round data modules (`dataModuleShape`) and finder eyes
 *    (`eyeShape`).
 *
 * `errorCorrectionLevel` is stored as the level's two-bit format indicator
 * from ISO/IEC 18004, which is not in alphabetical order: L=1, M=0, Q=3,
 * H=2. A letter is accepted too, and a missing level means L.
 *
 * The module grid itself comes from QrEncoder.
 */
class QrCodeRenderer
{
    private const LEVEL_FROM_INDICATOR = [1 => 'L', 0 => 'M', 3 => 'Q', 2 => 'H'];

    public static function levelLetter(mixed $level): string
    {
        if (is_string($level) && in_array(strtoupper($level), ['L', 'M', 'Q', 'H'], true)) {
            return strtoupper($level);
        }

        return is_numeric($level) ? (self::LEVEL_FROM_INDICATOR[(int) $level] ?? 'L') : 'L';
    }

    /**
     * @return list<list<bool>> rows of modules, true = dark
     */
    public static function matrix(string $data, mixed $level): array
    {
        return QrEncoder::matrix($data, self::levelLetter($level));
    }

    /**
     * @param  array{r: int, g: int, b: int, a: float}  $foreground
     * @param  array{r: int, g: int, b: int, a: float}  $background
     * @param  float  $pixelsPerUnit  CSS pixels (1/96 in) in one of the project's units
     */
    public static function svg(DesignElement $element, string $data, array $foreground, array $background, float $pixelsPerUnit): string
    {
        $matrix = self::matrix($data, $element->property('errorCorrectionLevel'));
        $size = count($matrix);
        $width = max(0.0, $element->width);
        $height = max(0.0, $element->height);
        $side = min($width, $height);
        $padding = min($side / 2, max(0.0, (float) $element->property('padding', 2)) / $pixelsPerUnit);
        $module = $size > 0 ? ($side - $padding * 2) / $size : 0.0;
        $x = ($width - $side) / 2;
        $y = ($height - $side) / 2;
        $roundModules = $element->property('dataModuleShape') === 'circle';
        $roundEyes = $element->property('eyeShape') === 'circle';

        $eyes = [[0, 0], [$size - 7, 0], [0, $size - 7]];
        $inEye = function (int $column, int $row) use ($eyes): bool {
            foreach ($eyes as [$ex, $ey]) {
                if ($column >= $ex && $column < $ex + 7 && $row >= $ey && $row < $ey + 7) {
                    return true;
                }
            }

            return false;
        };

        // Square modules are one path of horizontal runs, so neighbouring
        // modules share edges instead of leaving anti-aliased seams.
        $squares = '';
        $circles = '';
        foreach ($matrix as $row => $cells) {
            for ($column = 0; $column < $size; $column++) {
                if (! $cells[$column] || $inEye($column, $row)) {
                    continue;
                }
                if ($roundModules) {
                    $circles .= sprintf('<circle cx="%s" cy="%s" r="0.5" />', $column + 0.5, $row + 0.5);

                    continue;
                }
                $end = $column;
                while ($end + 1 < $size && $cells[$end + 1] && ! $inEye($end + 1, $row)) {
                    $end++;
                }
                $squares .= self::rectPath($column, $row, $end + 1 - $column, 1);
                $column = $end;
            }
        }

        $eyeMarkup = '';
        foreach ($eyes as [$ex, $ey]) {
            if ($roundEyes) {
                // A round eye's ring spans radii 2.5 to 3.5: a 1-wide stroke at radius 3.
                $eyeMarkup .= sprintf(
                    '<circle cx="%1$s" cy="%2$s" r="3" fill="none" stroke-width="1" %3$s /><circle cx="%1$s" cy="%2$s" r="1.5" />',
                    $ex + 3.5, $ey + 3.5, self::paint('stroke', $foreground),
                );
            } else {
                // The ring's hole runs the other way round, so the nonzero rule leaves it empty.
                $squares .= self::rectPath($ex, $ey, 7, 7)
                    .sprintf('M %s %s L %s %s L %s %s L %s %s Z ', $ex + 1, $ey + 1, $ex + 1, $ey + 6, $ex + 6, $ey + 6, $ex + 6, $ey + 1)
                    .self::rectPath($ex + 2, $ey + 2, 3, 3);
            }
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%1$s" height="%2$s" viewBox="0 0 %1$s %2$s">'
            .'<rect x="%3$s" y="%4$s" width="%5$s" height="%5$s" %6$s />'
            .'<g transform="translate(%7$s,%8$s) scale(%9$s)" %10$s>%11$s%12$s%13$s</g>'
            .'</svg>',
            $width, $height,
            $x, $y, $side, self::paint('fill', $background),
            $x + $padding, $y + $padding, $module, self::paint('fill', $foreground),
            $squares === '' ? '' : sprintf('<path d="%s" />', trim($squares)),
            $circles,
            $eyeMarkup,
        );
    }

    private static function rectPath(float $x, float $y, float $width, float $height): string
    {
        return sprintf('M %s %s L %s %s L %s %s L %s %s Z ', $x, $y, $x + $width, $y, $x + $width, $y + $height, $x, $y + $height);
    }

    /** @param  array{r: int, g: int, b: int, a: float}  $rgba */
    private static function paint(string $attribute, array $rgba): string
    {
        return sprintf('%1$s="#%2$02x%3$02x%4$02x" %1$s-opacity="%5$s"', $attribute, $rgba['r'], $rgba['g'], $rgba['b'], $rgba['a']);
    }
}
