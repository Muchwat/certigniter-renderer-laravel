<?php

namespace Certigniter\CertificateRenderer\Tests\Unit;

use Certigniter\CertificateRenderer\Support\FontRegistrar;
use Dompdf\Dompdf;
use Dompdf\Options;
use PHPUnit\Framework\TestCase;

class FontRegistrarTest extends TestCase
{
    private string $fontsBasePath;

    private string $cachePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fontsBasePath = dirname(__DIR__, 2).'/resources/fonts';
        $this->cachePath = sys_get_temp_dir().'/certigniter-font-registrar-test-'.uniqid();
        mkdir($this->cachePath, 0700, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->cachePath.'/*') ?: []);
        @rmdir($this->cachePath);
        parent::tearDown();
    }

    private function registrar(array $embeddedFonts = []): FontRegistrar
    {
        return new FontRegistrar(
            fonts: ['Roboto' => ['normal' => 'Roboto/Roboto-Regular.ttf', 'bold' => 'Roboto/Roboto-Bold.ttf']],
            fallbackRelativePath: 'Inter/Inter-VariableFont.ttf',
            basePath: $this->fontsBasePath,
            embeddedFonts: $embeddedFonts,
            fontCachePath: $this->cachePath,
        );
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
        $options->setChroot([$this->fontsBasePath, $this->cachePath]);

        return new Dompdf($options);
    }

    public function test_resolve_family_returns_a_bundled_family_verbatim(): void
    {
        $this->assertSame('Roboto', $this->registrar()->resolveFamily('Roboto'));
    }

    public function test_resolve_family_falls_back_to_inter_for_an_unbundled_family(): void
    {
        $this->assertSame('Inter', $this->registrar()->resolveFamily('Some Random System Font'));
        $this->assertSame('Inter', $this->registrar()->resolveFamily(null));
    }

    public function test_resolve_family_recognizes_a_registered_embedded_family_only_after_register_all_runs(): void
    {
        $fontBytes = file_get_contents($this->fontsBasePath.'/Roboto/Roboto-Regular.ttf');
        $registrar = $this->registrar([
            'Custom Project Font' => ['normal' => base64_encode($fontBytes), 'bold' => base64_encode($fontBytes)],
        ]);

        $this->assertSame('Inter', $registrar->resolveFamily('Custom Project Font'), 'not registered yet');

        $registrar->registerAll($this->dompdf());

        $this->assertSame('Custom Project Font', $registrar->resolveFamily('Custom Project Font'));
    }

    public function test_register_all_writes_a_cached_ttf_file_for_an_embedded_font(): void
    {
        $fontBytes = file_get_contents($this->fontsBasePath.'/Roboto/Roboto-Regular.ttf');
        $registrar = $this->registrar([
            'Custom Project Font' => ['normal' => 'data:font/ttf;base64,'.base64_encode($fontBytes)],
        ]);

        $registrar->registerAll($this->dompdf());

        $cachedFiles = glob($this->cachePath.'/source-*.ttf');
        $this->assertNotEmpty($cachedFiles, 'expected an embedded font to be decoded to a cache file');
        $this->assertSame($fontBytes, file_get_contents($cachedFiles[0]));
    }

    public function test_register_all_skips_an_embedded_font_with_invalid_base64_without_throwing(): void
    {
        $registrar = $this->registrar([
            'Broken Font' => ['normal' => 'not valid base64!!!'],
        ]);

        $registrar->registerAll($this->dompdf());

        $this->assertSame('Inter', $registrar->resolveFamily('Broken Font'));
    }

    public function test_baseline_correction_ratio_is_the_reference_value_for_a_family_with_no_cached_metrics(): void
    {
        // registerAll() never ran, so nothing has been parsed yet.
        $this->assertSame(0.0875, $this->registrar()->baselineCorrectionRatio('Roboto'));
        $this->assertSame(0.0875, $this->registrar()->baselineCorrectionRatio('Unknown Family'));
    }

    public function test_baseline_correction_ratio_matches_the_legacy_flat_constant_for_the_font_it_was_tuned_against(): void
    {
        $registrar = $this->registrar();
        $registrar->registerAll($this->dompdf());

        // Roboto's real ascent/descent split (hhea: 1900/-500 at 2048
        // units/em) is what the reference point in
        // baselineCorrectionRatioForMetrics() was measured against, so
        // registering the real font file should reproduce ~0.0875, not
        // just return it as an unrelated fallback.
        $this->assertEqualsWithDelta(0.0875, $registrar->baselineCorrectionRatio('Roboto'), 0.001);
    }

    public function test_baseline_correction_ratio_differs_for_a_font_with_a_meaningfully_different_ascent_descent_split(): void
    {
        $registrar = new FontRegistrar(
            fonts: [
                'Roboto' => ['normal' => 'Roboto/Roboto-Regular.ttf', 'bold' => 'Roboto/Roboto-Bold.ttf'],
                'Cinzel' => ['normal' => 'Cinzel/Cinzel-Regular.ttf', 'bold' => 'Cinzel/Cinzel-Bold.ttf'],
            ],
            fallbackRelativePath: 'Inter/Inter-VariableFont.ttf',
            basePath: $this->fontsBasePath,
            fontCachePath: $this->cachePath,
        );
        $registrar->registerAll($this->dompdf());

        // Cinzel's ascent ratio (~0.724) is well below Roboto's (~0.792),
        // so it must land on a visibly different correction, not silently
        // fall through to the same flat value.
        $roboto = $registrar->baselineCorrectionRatio('Roboto');
        $cinzel = $registrar->baselineCorrectionRatio('Cinzel');
        $this->assertGreaterThan(0.05, abs($roboto - $cinzel));
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

    public function test_baseline_correction_ratio_for_metrics_returns_the_reference_ratio_for_null_or_empty_metrics(): void
    {
        $this->assertSame(0.0875, FontRegistrar::baselineCorrectionRatioForMetrics(null));
        $this->assertSame(0.0875, FontRegistrar::baselineCorrectionRatioForMetrics(['ascent' => 0.0, 'descent' => 0.0]));
    }
}
