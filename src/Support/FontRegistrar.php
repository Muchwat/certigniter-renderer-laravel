<?php

namespace Certigniter\CertificateRenderer\Support;

use Dompdf\Dompdf;
use FontLib\Font;

/**
 * Resolves a project's fonts against the three places its bytes can come
 * from, in the order this class registers them:
 *
 *  1. `$fonts` - our own copy of the families Certigniter bundles, from
 *     this package's resources/fonts. Always available, never depends on
 *     what the designer's machine had installed.
 *  2. `$embeddedFonts` - the font bytes carried inside the .igniter file
 *     itself, decoded and written to `$fontCachePath`. Certigniter always
 *     embeds a *system* font this way, because a family name alone cannot
 *     be reproduced on another machine; it embeds a bundled family too when
 *     the designer exports a self-contained file. Registered second, so an
 *     embedded copy of a family we also bundle wins - harmless, since both
 *     sides ship byte-identical files.
 *  3. Inter, the fallback, for a family that is in neither - a system font
 *     from a project saved before embedding, which a server genuinely has
 *     no way to reproduce.
 *
 * A variant map may legitimately carry only `normal`: Certigniter drops a
 * `bold` that is byte-identical to it (a system font is located as a single
 * file, and Inter's two cuts are the same variable font). Both weights are
 * still registered, from the one payload, so dompdf emboldens synthetically
 * rather than falling through to Inter.
 */
class FontRegistrar
{
    /** @var array<string, true> */
    private array $registeredEmbeddedFamilies = [];

    /** @var array<string, array{ascent: float, descent: float}> resolved family => ascent/|descent|, each as a fraction of the font's own em size */
    private array $metrics = [];

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
            $normalPath = $this->basePath.'/'.($variants['normal'] ?? reset($variants));
            $this->cacheMetrics($family, $normalPath);
        }

        foreach ($this->embeddedFonts as $family => $variants) {
            $registered = false;
            $normalPath = null;
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
                if ($weight === 'normal') {
                    $normalPath = $path;
                }
                $normalPath ??= $path;
            }
            if ($registered) {
                $this->registeredEmbeddedFamilies[$family] = true;
                if ($normalPath !== null) {
                    $this->cacheMetrics($family, $normalPath);
                }
            }
        }

        $fallbackPath = $this->basePath.'/'.$this->fallbackRelativePath;
        // No separate bold cut is bundled for the fallback - dompdf will
        // synthetically embolden it, same degradation Certigniter's own
        // export accepts for any font it only has one weight for.
        $metrics->registerFont(['family' => 'Inter', 'weight' => 'normal', 'style' => 'normal'], $fallbackPath);
        $metrics->registerFont(['family' => 'Inter', 'weight' => 'bold', 'style' => 'normal'], $fallbackPath);
        $this->cacheMetrics('Inter', $fallbackPath);
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

    private function cacheMetrics(string $family, string $path): void
    {
        try {
            $font = Font::load($path);
            $font->parse();
            $unitsPerEm = (float) $font->getData('head', 'unitsPerEm');
            $ascent = (float) $font->getData('hhea', 'ascent');
            $descent = (float) $font->getData('hhea', 'descent');
            $font->close();
        } catch (\Throwable) {
            // Vertical-centering accuracy is a refinement on top of a font
            // actually rendering at all - a font this package can't parse
            // for metrics but dompdf can still embed just falls back to the
            // flat reference correction in baselineCorrectionRatio(), same
            // as before this class tracked metrics at all.
            return;
        }

        if ($unitsPerEm <= 0) {
            return;
        }

        $this->metrics[$family] = ['ascent' => $ascent / $unitsPerEm, 'descent' => abs($descent) / $unitsPerEm];
    }

    /**
     * How much to shift a text box's vertical centering to compensate for
     * dompdf's CSS line-box (built from the font's own fixed ascent/descent)
     * centering text differently than package:pdf's tight-ink-bbox centering
     * does - see TextElementStyle's use of this and
     * batch_pdf_generator.dart's `_textBaselineCorrectionFactor` doc comment
     * for the Flutter side of the same problem.
     *
     * A flat ratio (the previous implementation) only holds for fonts whose
     * ascent/descent split resembles whatever font it was tuned against -
     * it drifts badly for fonts with an unusual split, e.g. a blackletter
     * face's tall, shallow-descender letterforms. This scales the
     * correction by how far the resolved font's own real ascent/descent
     * split (read from its TTF `hhea` table) deviates from Roboto's, using
     * a slope fit from two real measurements: Roboto/AlbertSans-Regular
     * (ascent ratio ~0.7917, matches the legacy flat 0.0875) and Old
     * English Text MT (ascent ratio ~0.8613, needs ~-0.0263) - see
     * FontRegistrarTest and CHANGELOG.md for the derivation. It's a
     * measured fit, not a closed-form formula - package:pdf's own
     * correction is itself a single flat, font-agnostic constant, so there
     * is no exact target to solve for; this meaningfully narrows the gap
     * for unusual fonts while leaving "normal" ones unchanged.
     */
    public function baselineCorrectionRatio(string $resolvedFamily): float
    {
        return self::baselineCorrectionRatioForMetrics($this->metrics[$resolvedFamily] ?? null);
    }

    /** @param array{ascent: float, descent: float}|null $metrics */
    public static function baselineCorrectionRatioForMetrics(?array $metrics): float
    {
        $referenceAscentRatio = 0.791672;
        $referenceCorrectionRatio = 0.0875;
        $slope = -1.634;

        if ($metrics === null || ($metrics['ascent'] + $metrics['descent']) <= 0) {
            return $referenceCorrectionRatio;
        }

        $ascentRatio = $metrics['ascent'] / ($metrics['ascent'] + $metrics['descent']);

        return $referenceCorrectionRatio + $slope * ($ascentRatio - $referenceAscentRatio);
    }
}
