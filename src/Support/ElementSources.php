<?php

declare(strict_types=1);

namespace Certigniter\CertificateRenderer\Support;

use Certigniter\CertificateRenderer\Data\CertificateProject;
use Certigniter\CertificateRenderer\Data\DesignElement;
use Throwable;

/**
 * Precomputes the data: URIs the view embeds for image, QR code and barcode
 * elements. An element that can't be resolved is skipped and explained in
 * warnings() instead of aborting the render.
 */
class ElementSources
{
    /** @var string[] */
    private array $warnings = [];

    /** @return string[] */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * @param  DesignElement[]  $elements
     * @return array<string, array{src: string, aspectRatio: ?float}>
     */
    public function images(array $elements): array
    {
        $sources = [];

        foreach ($elements as $element) {
            if ($element->type !== 'image') {
                continue;
            }

            $imageData = $element->property('imageData');

            if (is_string($imageData) && $imageData !== '') {
                $clean = str_contains($imageData, ',') ? substr($imageData, strpos($imageData, ',') + 1) : $imageData;
                $mime = self::sniffImageMime($clean) ?? 'image/png';
                $bytes = base64_decode($clean, true);
                $size = $bytes === false ? false : @getimagesizefromstring($bytes);
                $sources[$element->id] = [
                    'src' => "data:{$mime};base64,{$clean}",
                    'aspectRatio' => is_array($size) && $size[1] > 0 ? $size[0] / $size[1] : null,
                ];

                continue;
            }

            $path = $element->property('path');

            if (is_string($path) && $path !== '') {
                $this->warnings[] = sprintf(
                    "Element %s: image only has a local 'path' (%s) from the machine that created it - .igniter "
                    ."files don't carry font/image bytes for 'path'-only images, so this can't be resolved on a "
                    .'server and was skipped. Re-save the project so its images are embedded (imageData), or '
                    .'supply the file yourself.',
                    $element->id,
                    $path,
                );
            }
        }

        return $sources;
    }

    /**
     * QR/barcode generation is precomputed here (rather than inline in the
     * Blade view) specifically so a single malformed element - most often
     * a barcode symbology that rejects characters left over from an
     * unresolved {{token}}/<token> (e.g. Code39 can't encode most of what
     * Code128 can) - degrades to a skip-and-warn instead of aborting the
     * entire render, the same way an unresolvable image does.
     *
     * @param  DesignElement[]  $elements
     * @return array<string, array{src: string, caption?: string|null, fontSize?: float, color?: string}> element id => data: URI, plus a barcode's caption
     */
    public function codes(array $elements, CertificateProject $project): array
    {
        $sources = [];

        foreach ($elements as $element) {
            if (! $element->isCode()) {
                continue;
            }

            $data = (string) $element->property('data', '');
            $unresolved = UnresolvedCode::warning($element, $data);

            if ($unresolved !== null) {
                $this->warnings[] = $unresolved;

                continue;
            }

            $foreground = ColorConverter::toRgba($element->property('color'), $project->colorFormat, '#000000');
            $background = ColorConverter::toRgba($element->property('backgroundColor'), $project->colorFormat, '#FFFFFF');
            $pixelsPerUnit = Units::pixelsPer($project->unit);
            if ($data === '') {
                // An empty static code encodes placeholder content, so the template still previews meaningfully.
                $data = $element->type === 'qrcode'
                    ? 'certigniter_placeholder'
                    : BarcodeRenderer::sampleData((string) $element->property('barcodeType'));
            }

            try {
                if ($element->type === 'qrcode') {
                    $sources[$element->id] = [
                        'src' => 'data:image/svg+xml;base64,'.base64_encode(
                            QrCodeRenderer::svg($element, $data, $foreground, $background, $pixelsPerUnit),
                        ),
                    ];
                } else {
                    $layout = BarcodeRenderer::layout($element, $data, $pixelsPerUnit);
                    $sources[$element->id] = [
                        'src' => 'data:image/svg+xml;base64,'.base64_encode(
                            BarcodeRenderer::svg($element, $layout, $foreground, $background),
                        ),
                        'caption' => $layout['showText'] ? $layout['text'] : null,
                        'fontSize' => $layout['fontSize'],
                        'color' => ColorConverter::toCss($element->property('color'), $project->colorFormat, '#000000'),
                    ];
                }
            } catch (Throwable $e) {
                $this->warnings[] = sprintf(
                    'Element %s (%s): could not generate this code (%s) - it was skipped. This usually means the '
                    .'data contains characters its symbology/format can\'t encode (often a leftover, unresolved '
                    .'{{token}}/<token> when no matching recipient value was supplied).',
                    $element->id,
                    $element->type,
                    $e->getMessage(),
                );
            }
        }

        return $sources;
    }

    private static function sniffImageMime(string $base64): ?string
    {
        $binary = base64_decode($base64, true);

        if ($binary === false || strlen($binary) < 12) {
            return null;
        }

        return match (true) {
            str_starts_with($binary, "\x89PNG\r\n\x1a\n") => 'image/png',
            str_starts_with($binary, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($binary, 'GIF87a') || str_starts_with($binary, 'GIF89a') => 'image/gif',
            str_starts_with($binary, 'RIFF') && str_contains(substr($binary, 8, 4), 'WEBP') => 'image/webp',
            default => null,
        };
    }
}
