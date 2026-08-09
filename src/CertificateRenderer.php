<?php

namespace Certigniter\CertificateRenderer;

use Certigniter\CertificateRenderer\Data\CertificateProject;
use Certigniter\CertificateRenderer\Data\DesignElement;
use Certigniter\CertificateRenderer\Support\BarcodeRenderer;
use Certigniter\CertificateRenderer\Support\ColorConverter;
use Certigniter\CertificateRenderer\Support\Encryption;
use Certigniter\CertificateRenderer\Support\FontRegistrar;
use Certigniter\CertificateRenderer\Support\GroupComposer;
use Certigniter\CertificateRenderer\Support\QrCodeRenderer;
use Certigniter\CertificateRenderer\Support\RecipientMerge;
use Dompdf\Dompdf;
use Dompdf\Options;
use RuntimeException;
use Throwable;

class CertificateRenderer
{
    /** @var string[] non-fatal issues from the most recent render (e.g. an image that couldn't be resolved server-side) */
    private array $warnings = [];

    /** @param array<string, array{normal: string, bold: string}> $fonts */
    public function __construct(
        private readonly string $encryptionKey,
        private readonly array $fonts,
        private readonly string $fallbackFontRelativePath,
        private readonly string $fontsBasePath,
        private readonly bool $composeGroupTransforms,
    ) {
    }

    /**
     * Decrypt an .igniter file's raw content and render it straight to PDF
     * bytes. This is the one-call entry point most callers want.
     *
     * @param array<string, string>|null $recipient a single row from a
     *  recipient CSV (see RecipientMerge) - null for a static, single-copy
     *  certificate with no merge fields resolved (any variableName/{{token}}
     *  elements render literally empty/unsubstituted).
     */
    public function renderIgniterToPdf(string $encryptedIgniterContent, ?array $recipient = null, ?string $encryptionKey = null): string
    {
        $project = $this->parseIgniter($encryptedIgniterContent, $encryptionKey);

        return $this->renderProjectToPdf($project, $recipient);
    }

    public function parseIgniter(string $encryptedIgniterContent, ?string $encryptionKey = null): CertificateProject
    {
        $json = Encryption::decrypt($encryptedIgniterContent, $encryptionKey ?? $this->encryptionKey);
        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            throw new RuntimeException('Decrypted .igniter payload is not valid JSON.');
        }

        return CertificateProject::fromArray($decoded);
    }

    /** @param array<string, string>|null $recipient */
    public function renderProjectToPdf(CertificateProject $project, ?array $recipient = null): string
    {
        $this->warnings = [];

        $elements = GroupComposer::resolve($project, $this->composeGroupTransforms);
        $elements = array_values(array_filter($elements, fn (DesignElement $e) => $e->isVisible()));

        if ($recipient !== null) {
            $elements = array_map(fn (DesignElement $e) => RecipientMerge::apply($e, $recipient), $elements);
        }

        $imageSources = $this->resolveImageSources($elements);
        $codeSources = $this->resolveCodeSources($elements, $project);
        $fonts = new FontRegistrar($this->fonts, $this->fallbackFontRelativePath, $this->fontsBasePath);

        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('Inter');
        $dompdf = new Dompdf($options);
        $fonts->registerAll($dompdf);

        $html = view('certigniter::certificate', [
            'project' => $project,
            'elements' => $elements,
            'fonts' => $fonts,
            'imageSources' => $imageSources,
            'codeSources' => $codeSources,
        ])->render();

        $dompdf->loadHtml($html);
        // Always pass the *literal* [x1,y1,x2,y2] rect with orientation
        // 'portrait' (dompdf's default) - Dompdf::getPaperSize() swaps
        // width/height whenever orientation is 'landscape', even for an
        // already-explicit custom array, which would double-swap a rect
        // that's already correctly proportioned and silently transpose the
        // page (caught via a real rendered-PDF visual check, not just unit
        // tests: a 200x150mm project was coming out 150x200mm).
        $dompdf->setPaper($this->paperSizePoints($project), 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    /** @return string[] */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /** @param DesignElement[] $elements @return array<string, string> element id => data: URI */
    private function resolveImageSources(array $elements): array
    {
        $sources = [];

        foreach ($elements as $element) {
            if ($element->type !== 'image') {
                continue;
            }

            $imageData = $element->property('imageData');

            if (is_string($imageData) && $imageData !== '') {
                $clean = str_contains($imageData, ',') ? substr($imageData, strpos($imageData, ',') + 1) : $imageData;
                $mime = $this->sniffImageMime($clean) ?? 'image/png';
                $sources[$element->id] = "data:{$mime};base64,{$clean}";

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
     * @param DesignElement[] $elements
     * @return array<string, string> element id => data: URI
     */
    private function resolveCodeSources(array $elements, CertificateProject $project): array
    {
        $sources = [];

        foreach ($elements as $element) {
            if (!in_array($element->type, ['qrcode', 'barcode'], true)) {
                continue;
            }

            $foreground = ColorConverter::toRgba($element->property('color'), $project->colorFormat, '#000000');

            try {
                if ($element->type === 'qrcode') {
                    $background = ColorConverter::toRgba($element->property('backgroundColor'), $project->colorFormat, '#FFFFFF');
                    $data = (string) $element->property('data', '');
                    $sizePx = (int) round(min($element->width, $element->height) * 3.78);

                    $sources[$element->id] = QrCodeRenderer::pngDataUri(
                        $data !== '' ? $data : 'certigniter',
                        $sizePx,
                        $foreground,
                        $background,
                        (int) $element->property('errorCorrectionLevel', 0),
                    );
                } else {
                    $data = (string) $element->property('data', '');

                    $sources[$element->id] = BarcodeRenderer::pngDataUri(
                        $data !== '' ? $data : '123456789',
                        (string) $element->property('barcodeType', 'code128'),
                        (int) round($element->width * 3.78),
                        (int) round($element->height * 3.78),
                        $foreground,
                    );
                }
            } catch (Throwable $e) {
                $this->warnings[] = sprintf(
                    "Element %s (%s): could not generate this code (%s) - it was skipped. This usually means the "
                    .'data contains characters its symbology/format can\'t encode (often a leftover, unresolved '
                    ."{{token}}/<token> when no matching recipient value was supplied).",
                    $element->id,
                    $element->type,
                    $e->getMessage(),
                );
            }
        }

        return $sources;
    }

    private function sniffImageMime(string $base64): ?string
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

    /** @return array{0: float, 1: float, 2: float, 3: float} dompdf's custom-paper-size shape: [x1, y1, x2, y2] in points */
    private function paperSizePoints(CertificateProject $project): array
    {
        $factor = match (strtolower($project->unit)) {
            'mm' => 2.83464567,
            'cm' => 28.3464567,
            'in' => 72.0,
            default => 1.0, // already points
        };

        return [0, 0, $project->width * $factor, $project->height * $factor];
    }
}
