<?php

namespace Certigniter\CertificateRenderer\Support;

use Dompdf\Dompdf;

/**
 * Certigniter itself only guarantees pixel-identical output for the 5 font
 * families it bundles actual .ttf files for (batch_pdf_generator.dart) -
 * any other fontFamily in a project is whatever's installed on the machine
 * that happened to render it, which a server has no way to reproduce (the
 * .igniter file only ever stores a family *name*, never font bytes). This
 * class registers our copy of those same 5 families with dompdf, and maps
 * everything else to the same Inter fallback Certigniter itself uses.
 */
class FontRegistrar
{
    /** @var array<string, true> */
    private array $registeredEmbeddedFamilies = [];

    /** @param array<string, array{normal: string, bold: string}> $fonts family name => relative paths under $basePath */
    public function __construct(
        private readonly array $fonts,
        private readonly string $fallbackRelativePath,
        private readonly string $basePath,
        private readonly array $embeddedFonts = [],
        private readonly string $fontCachePath = '',
    ) {}

    public function registerAll(Dompdf $dompdf): void
    {
        $metrics = $dompdf->getFontMetrics();

        foreach ($this->fonts as $family => $variants) {
            foreach ($variants as $weight => $relativePath) {
                $metrics->registerFont(
                    ['family' => $family, 'weight' => $weight, 'style' => 'normal'],
                    $this->basePath.'/'.$relativePath,
                );
            }
        }

        foreach ($this->embeddedFonts as $family => $variants) {
            $registered = false;
            foreach (['normal', 'bold'] as $weight) {
                $encoded = $variants[$weight] ?? $variants['normal'] ?? null;
                if (! is_string($encoded)) {
                    continue;
                }
                $clean = str_contains($encoded, ',') ? substr($encoded, strpos($encoded, ',') + 1) : $encoded;
                $bytes = base64_decode($clean, true);
                if ($bytes === false || $bytes === '') {
                    continue;
                }
                $path = $this->fontCachePath.'/source-'.hash('sha256', $bytes).'.ttf';
                if (! is_file($path) && file_put_contents($path, $bytes, LOCK_EX) === false) {
                    continue;
                }
                $registered = $metrics->registerFont(
                    ['family' => $family, 'weight' => $weight, 'style' => 'normal'],
                    $path,
                ) || $registered;
            }
            if ($registered) {
                $this->registeredEmbeddedFamilies[$family] = true;
            }
        }

        $fallbackPath = $this->basePath.'/'.$this->fallbackRelativePath;
        // No separate bold cut is bundled for the fallback - dompdf will
        // synthetically embolden it, same degradation Certigniter's own
        // export accepts for any font it only has one weight for.
        $metrics->registerFont(['family' => 'Inter', 'weight' => 'normal', 'style' => 'normal'], $fallbackPath);
        $metrics->registerFont(['family' => 'Inter', 'weight' => 'bold', 'style' => 'normal'], $fallbackPath);
    }

    /** The family name to actually put in generated CSS - $requestedFamily verbatim if it's one we bundle, else the fallback. */
    public function resolveFamily(?string $requestedFamily): string
    {
        if ($requestedFamily !== null &&
            (isset($this->registeredEmbeddedFamilies[$requestedFamily]) || array_key_exists($requestedFamily, $this->fonts))) {
            return $requestedFamily;
        }

        return 'Inter';
    }
}
