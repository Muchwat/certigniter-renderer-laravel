<?php

declare(strict_types=1);

namespace Certigniter\CertificateRenderer\Support;

use Dompdf\Dompdf;
use Dompdf\Options;
use FontLib\Font;

/**
 * Registers a project's own fonts with Dompdf.
 *
 * This package ships no font files. Every family a certificate uses travels
 * inside the `.igniter` itself, as raw members of the archive's
 * `assets/fonts/` tree, and IgniterPackage has already turned those into the
 * `embedded_fonts` map this class receives. That is what makes a `.igniter`
 * self-contained: whether a family renders depends on the file, not on what
 * the machine doing the rendering happens to have installed next to it.
 *
 * It used to work the other way around: Certigniter kept a list of families
 * it bundled on both sides and left their bytes out of the file to keep it
 * small. That coupling broke quietly whenever the two lists drifted - a
 * family the designer had and this package didn't rendered as the fallback
 * with the file still perfectly valid - and the saving it bought disappeared
 * once fonts moved into the ZIP as compressed binary members instead of
 * base64 inside the encrypted manifest.
 *
 * [FALLBACK_FAMILY] covers what remains genuinely unresolvable: a family a
 * project names but carries no bytes for. It is Dompdf's own bundled DejaVu
 * Sans, so the fallback costs this package nothing to ship and is always
 * available.
 *
 * A variant map may legitimately carry only `normal`: Certigniter drops a
 * `bold` that is byte-identical to it (a system font is located as a single
 * file, and a variable font's two cuts are the same bytes). Both weights are
 * still registered, from the one payload, so Dompdf emboldens synthetically
 * rather than falling through to the fallback.
 */
class FontRegistrar
{
    /**
     * Dompdf ships this family, so it needs no bytes from us. Registered
     * under Dompdf's own name for it - do not rename without checking it
     * still resolves in lib/fonts/installed-fonts.dist.json.
     */
    public const FALLBACK_FAMILY = 'DejaVu Sans';

    private const FALLBACK_FONT_FILE = 'DejaVuSans.ttf';

    /** @var array<string, true> */
    private array $registeredEmbeddedFamilies = [];

    /** @var array<string, array{ascent: float, descent: float}> resolved family => ascent/|descent|, each as a fraction of the font's own em size */
    private array $metrics = [];

    /** @param array<string, array{normal?: string, bold?: string}> $embeddedFonts family name => base64 font bytes per variant */
    public function __construct(
        private readonly array $embeddedFonts = [],
        private readonly string $fontCachePath = '',
    ) {}

    /** Absolute path to the directory Dompdf keeps its own built-in fonts in. */
    public static function builtInFontDirectory(Options $options): string
    {
        return $options->getRootDir().'/lib/fonts';
    }

    public function registerAll(Dompdf $dompdf): void
    {
        $metrics = $dompdf->getFontMetrics();

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

        // Dompdf already knows the fallback family - it only needs measuring,
        // for the same vertical-centering correction every other family gets.
        $fallbackPath = self::builtInFontDirectory($dompdf->getOptions()).'/'.self::FALLBACK_FONT_FILE;
        if (is_file($fallbackPath)) {
            $this->cacheMetrics(self::FALLBACK_FAMILY, $fallbackPath);
        }
    }

    /** The family name to actually put in generated CSS - $requestedFamily verbatim if the project carried its bytes, else the fallback. */
    public function resolveFamily(?string $requestedFamily): string
    {
        if ($requestedFamily !== null && isset($this->registeredEmbeddedFamilies[$requestedFamily])) {
            return $requestedFamily;
        }

        return self::FALLBACK_FAMILY;
    }

    /**
     * Families the current project actually carried bytes for, in the order they were registered.
     *
     * @return string[]
     */
    public function embeddedFamilies(): array
    {
        return array_keys($this->registeredEmbeddedFamilies);
    }

    private function cacheMetrics(string $family, string $path): void
    {
        try {
            $font = Font::load($path);
            if ($font === null) {
                return;
            }
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
