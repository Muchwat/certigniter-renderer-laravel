<?php

declare(strict_types=1);

namespace Certigniter\CertificateRenderer\Support;

use Dompdf\Dompdf;
use Dompdf\FontMetrics;
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
 * {@see self::FALLBACK_FAMILY} covers what remains unresolvable: a family a
 * project names but carries no bytes for. It is Dompdf's own bundled DejaVu
 * Sans, so the fallback costs this package nothing to ship and is always
 * available.
 *
 * A variant map may legitimately carry only `normal`: a `bold` variant
 * byte-identical to `normal` is omitted (a single-file system font, or a
 * variable font whose two cuts are the same bytes). Both weights are
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

    /** Dompdf's own font measurement, kept from registerAll() so text is measured exactly as Dompdf will lay it out. */
    private ?FontMetrics $fontMetrics = null;

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
        $this->fontMetrics = $metrics;

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
     * How wide `$text` is set in a resolved family, in points, as Dompdf
     * measures it: letter spacing after every character included. Before
     * registerAll() (or for a family Dompdf can't load) it falls back to half
     * an em per character, which only keeps a layout from collapsing.
     */
    public function textWidthPt(string $resolvedFamily, bool $bold, float $sizePt, float $letterSpacingPt, string $text): float
    {
        $font = $this->fontMetrics?->getFont($resolvedFamily, $bold ? 'bold' : 'normal');
        if ($font === null) {
            return mb_strlen($text) * ($sizePt / 2 + $letterSpacingPt);
        }

        return $this->fontMetrics->getTextWidth($text, $font, $sizePt, 0.0, $letterSpacingPt);
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
     * How much to shift a text box's vertical centring, as a fraction of
     * the font size. Dompdf centres a CSS line box built from the font's
     * fixed ascent and descent, which sits text lower than centring on the
     * glyphs themselves would; TextElementStyle applies this correction.
     *
     * The correction depends on the font's ascent/descent split (read from
     * its TTF `hhea` table): a flat ratio drifts badly for faces with an
     * unusual split, such as a blackletter's tall ascenders and shallow
     * descenders. It is a linear fit through two measured fonts, a
     * Roboto-like sans (ascent ratio ~0.7917, correction 0.0875) and Old
     * English Text MT (ascent ratio ~0.8613, correction ~-0.0263), so fonts
     * with a typical split get the reference correction and unusual ones
     * are pulled towards their measured position. FontRegistrarTest pins
     * both points.
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
