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
}
