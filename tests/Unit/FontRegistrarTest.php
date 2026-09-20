<?php

declare(strict_types=1);

namespace Certigniter\CertificateRenderer\Tests\Unit;

use Certigniter\CertificateRenderer\Support\FontRegistrar;
use Dompdf\Dompdf;
use Dompdf\Options;
use PHPUnit\Framework\TestCase;

class FontRegistrarTest extends TestCase
{
    private string $cachePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cachePath = sys_get_temp_dir().'/certigniter-font-registrar-test-'.uniqid();
        mkdir($this->cachePath, 0700, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->cachePath.'/*') ?: []);
        @rmdir($this->cachePath);
        parent::tearDown();
    }

    /** @param array<string, array{normal?: string, bold?: string}> $embeddedFonts */
    private function registrar(array $embeddedFonts = []): FontRegistrar
    {
        return new FontRegistrar($embeddedFonts, $this->cachePath);
    }

    /**
     * Mirrors how CertificateRenderer itself configures Dompdf's font paths -
     * a default `new Dompdf()`'s own bundled font cache directory isn't
     * guaranteed writable in a vendor install, which silently no-ops
     * registerFont() rather than throwing.
     */
    private function dompdf(): Dompdf
    {
        $options = new Options;
        $options->setFontDir($this->cachePath);
        $options->setFontCache($this->cachePath);
        $options->setTempDir($this->cachePath);
        $options->setChroot([$this->cachePath, FontRegistrar::builtInFontDirectory($options)]);

        return new Dompdf($options);
    }

    /**
     * Any real TTF will do as a stand-in for "a font the project carried".
     * Dompdf's own bundled copy is used so this package needs no font
     * fixtures of its own - which is the whole point of the change these
     * tests cover.
     */
    private function embeddableFontBytes(): string
    {
        $path = FontRegistrar::builtInFontDirectory(new Options).'/DejaVuSans.ttf';
        $bytes = file_get_contents($path);
        $this->assertNotFalse($bytes, "expected Dompdf to ship a readable {$path}");

        return $bytes;
    }

    public function test_resolve_family_falls_back_for_a_family_the_project_carried_no_bytes_for(): void
    {
        $this->assertSame(FontRegistrar::FALLBACK_FAMILY, $this->registrar()->resolveFamily('Some Random System Font'));
        $this->assertSame(FontRegistrar::FALLBACK_FAMILY, $this->registrar()->resolveFamily(null));
    }

    /**
     * The package ships no font files, so a family is only ever honoured
     * because the .igniter carried its bytes - there is no longer any
     * "bundled family" path that could resolve one without them.
     */
    public function test_resolve_family_recognizes_a_registered_embedded_family_only_after_register_all_runs(): void
    {
        $fontBytes = $this->embeddableFontBytes();
        $registrar = $this->registrar([
            'Custom Project Font' => ['normal' => base64_encode($fontBytes), 'bold' => base64_encode($fontBytes)],
        ]);

        $this->assertSame(FontRegistrar::FALLBACK_FAMILY, $registrar->resolveFamily('Custom Project Font'), 'not registered yet');

        $registrar->registerAll($this->dompdf());

        $this->assertSame('Custom Project Font', $registrar->resolveFamily('Custom Project Font'));
        $this->assertSame(['Custom Project Font'], $registrar->embeddedFamilies());
    }

    public function test_register_all_writes_a_cached_ttf_file_for_an_embedded_font(): void
    {
        $fontBytes = $this->embeddableFontBytes();
        $registrar = $this->registrar([
            'Custom Project Font' => ['normal' => 'data:font/ttf;base64,'.base64_encode($fontBytes)],
        ]);

        $registrar->registerAll($this->dompdf());

        $cachedFiles = glob($this->cachePath.'/source-*.ttf') ?: [];
        $this->assertNotEmpty($cachedFiles, 'expected an embedded font to be decoded to a cache file');
        $this->assertSame($fontBytes, file_get_contents($cachedFiles[0]));
    }

    public function test_register_all_skips_an_embedded_font_with_invalid_base64_without_throwing(): void
    {
        $registrar = $this->registrar([
            'Broken Font' => ['normal' => 'not valid base64!!!'],
        ]);

        $registrar->registerAll($this->dompdf());

        $this->assertSame(FontRegistrar::FALLBACK_FAMILY, $registrar->resolveFamily('Broken Font'));
        $this->assertSame([], $registrar->embeddedFamilies());
    }

    public function test_baseline_correction_ratio_is_the_reference_value_for_a_family_with_no_cached_metrics(): void
    {
        // registerAll() never ran, so nothing has been parsed yet.
        $this->assertSame(0.0875, $this->registrar()->baselineCorrectionRatio('Roboto'));
        $this->assertSame(0.0875, $this->registrar()->baselineCorrectionRatio('Unknown Family'));
    }

    /**
     * The correction has to come from the embedded file's own hhea table,
     * not from the flat reference constant - otherwise a project's font
     * would render at whatever vertical offset suited some other font.
     * DejaVu Sans' ascent ratio (~0.7974) is close to, but measurably
     * distinct from, the 0.791672 reference point.
     */
    public function test_baseline_correction_ratio_is_derived_from_a_registered_embedded_font_file(): void
    {
        $registrar = $this->registrar([
            'Custom Project Font' => ['normal' => base64_encode($this->embeddableFontBytes())],
        ]);
        $registrar->registerAll($this->dompdf());

        $ratio = $registrar->baselineCorrectionRatio('Custom Project Font');
        $this->assertEqualsWithDelta(0.0781, $ratio, 0.001);
        $this->assertNotEquals(0.0875, $ratio, 'expected real metrics, not the no-metrics fallback');
    }

    public function test_register_all_measures_the_fallback_family_from_dompdfs_own_bundled_font(): void
    {
        $registrar = $this->registrar();
        $registrar->registerAll($this->dompdf());

        // The fallback needs real metrics for the same reason every other
        // family does, and gets them without this package shipping a file.
        $this->assertEqualsWithDelta(
            0.0781,
            $registrar->baselineCorrectionRatio(FontRegistrar::FALLBACK_FAMILY),
            0.001,
        );
    }

    public function test_baseline_correction_ratio_for_metrics_reproduces_the_measured_old_english_text_mt_correction(): void
    {
        // Real hhea ascent/descent for Old English Text MT (1764/-284 at
        // 2048 units/em), the font that surfaced this bug: the title and
        // recipient-name elements of a real production certificate
        // (Strathmore CIPIT template) rendered visibly higher on the page
        // in the dompdf output than in Flutter's, by an amount matching
        // this exact font at two different sizes (measured via pdftotext
        // -bbox on both PDFs: ~5.46pt off at 48pt, ~3.76pt off at 33pt,
        // both converging to a ~-0.0263 correction ratio instead of the
        // legacy flat +0.0875).
        $ratio = FontRegistrar::baselineCorrectionRatioForMetrics([
            'ascent' => 1764 / 2048,
            'descent' => 284 / 2048,
        ]);

        $this->assertEqualsWithDelta(-0.0263, $ratio, 0.001);
    }

    /**
     * Roboto is no longer shipped, but it is still the font the reference
     * point was measured against, so its real split must keep reproducing
     * the legacy flat constant.
     */
    public function test_baseline_correction_ratio_for_metrics_matches_the_legacy_flat_constant_for_robotos_split(): void
    {
        $ratio = FontRegistrar::baselineCorrectionRatioForMetrics([
            'ascent' => 1900 / 2048,
            'descent' => 500 / 2048,
        ]);

        $this->assertEqualsWithDelta(0.0875, $ratio, 0.001);
    }

    public function test_baseline_correction_ratio_for_metrics_returns_the_reference_ratio_for_null_or_empty_metrics(): void
    {
        $this->assertSame(0.0875, FontRegistrar::baselineCorrectionRatioForMetrics(null));
        $this->assertSame(0.0875, FontRegistrar::baselineCorrectionRatioForMetrics(['ascent' => 0.0, 'descent' => 0.0]));
    }
}
