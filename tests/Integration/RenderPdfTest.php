<?php

declare(strict_types=1);

namespace Certigniter\CertificateRenderer\Tests\Integration;

use Certigniter\CertificateRenderer\CertificateRenderer;
use Certigniter\CertificateRenderer\Facades\Certigniter;
use Certigniter\CertificateRenderer\Tests\Fixtures\IgniterFixture;
use RuntimeException;

/**
 * The whole pipeline, end to end: `.igniter` bytes in, real PDF bytes out,
 * through a booted application. Assertions are about what is on the page -
 * the text that was drawn, the page box, whether the project's own font
 * travelled into the file - because the failure this suite exists to catch
 * is "it still returns a PDF, but an empty/wrong one".
 */
class RenderPdfTest extends TestCase
{
    private function renderer(): CertificateRenderer
    {
        return $this->application()->make(CertificateRenderer::class);
    }

    public function test_it_renders_an_igniter_package_to_a_structurally_valid_pdf(): void
    {
        $igniter = IgniterFixture::make()
            ->withShape('border')
            ->withText('title', 'Certificate of Completion')
            ->bytes();

        $pdf = RenderedPdf::from($this->renderer()->renderIgniterToPdf($igniter));

        $this->assertTrue($pdf->isStructurallyValid());
        $this->assertSame(1, $pdf->pageCount());
        $this->assertTrue($pdf->containsText('Certificate of Completion'));
    }

    public function test_it_renders_through_the_facade_too(): void
    {
        $pdf = RenderedPdf::from(Certigniter::renderIgniterToPdf(
            IgniterFixture::make()->withText('title', 'Via The Facade')->bytes()
        ));

        $this->assertTrue($pdf->containsText('Via The Facade'));
    }

    public function test_the_page_is_the_projects_own_size_and_is_not_transposed(): void
    {
        // 297x210mm at 72pt/inch. A landscape project coming back portrait
        // is the regression CertificateRenderer::renderProjectToPdf()'s
        // setPaper() comment describes.
        $landscape = RenderedPdf::from($this->renderer()->renderIgniterToPdf(
            IgniterFixture::make(width: 297.0, height: 210.0)->withText('t', 'Landscape')->bytes()
        ));

        $this->assertSame(841.89, $landscape->widthInPoints());
        $this->assertSame(595.276, $landscape->heightInPoints());
        $this->assertGreaterThan($landscape->heightInPoints(), $landscape->widthInPoints());

        $portrait = RenderedPdf::from($this->renderer()->renderIgniterToPdf(
            IgniterFixture::make(width: 210.0, height: 297.0)->withText('t', 'Portrait')->bytes()
        ));

        $this->assertSame(595.276, $portrait->widthInPoints());
        $this->assertSame(841.89, $portrait->heightInPoints());
    }

    public function test_recipient_values_are_merged_into_the_rendered_page(): void
    {
        $igniter = IgniterFixture::make()
            ->withText('title', 'Certificate of Completion')
            ->withRecipientField('name', 'recipient_name')
            ->bytes();

        $ada = RenderedPdf::from($this->renderer()->renderIgniterToPdf($igniter, ['recipient_name' => 'Ada Lovelace']));
        $grace = RenderedPdf::from($this->renderer()->renderIgniterToPdf($igniter, ['recipient_name' => 'Grace Hopper']));

        $this->assertTrue($ada->containsText('Ada Lovelace'));
        $this->assertFalse($ada->containsText('Grace Hopper'));
        $this->assertTrue($grace->containsText('Grace Hopper'));

        // Both still carry the static text, so the merge replaced a field
        // rather than the page.
        $this->assertTrue($ada->containsText('Certificate of Completion'));
        $this->assertTrue($grace->containsText('Certificate of Completion'));
    }

    public function test_a_font_the_project_carries_is_embedded_into_the_pdf(): void
    {
        $withFont = RenderedPdf::from($this->renderer()->renderIgniterToPdf(
            IgniterFixture::make()
                ->withEmbeddedFont('Integration Serif')
                ->withText('title', 'Embedded Font', ['fontFamily' => 'Integration Serif'])
                ->bytes()
        ));

        $this->assertTrue($withFont->hasEmbeddedFontProgram());
        $this->assertTrue($withFont->containsText('Embedded Font'));
    }

    public function test_a_font_the_project_only_names_falls_back_instead_of_failing(): void
    {
        $pdf = RenderedPdf::from($this->renderer()->renderIgniterToPdf(
            IgniterFixture::make()
                ->withText('title', 'Missing Font', ['fontFamily' => 'A Family Nobody Shipped'])
                ->bytes()
        ));

        $this->assertTrue($pdf->isStructurallyValid());
        $this->assertTrue($pdf->containsText('Missing Font'));
    }

    public function test_an_embedded_image_is_drawn_onto_the_page(): void
    {
        $renderer = $this->renderer();

        $pdf = RenderedPdf::from($renderer->renderIgniterToPdf(
            IgniterFixture::make()->withImage('logo')->bytes()
        ));

        $this->assertSame(1, $pdf->drawnImageCount());
        $this->assertSame([], $renderer->warnings());
    }

    public function test_a_qr_code_override_is_drawn_through_the_simple_qrcode_facade(): void
    {
        // QrCodeRenderer goes through a facade, so this path only exists
        // inside a booted application - it is the reason this suite exists.
        $renderer = $this->renderer();
        $igniter = IgniterFixture::make()->withQrCode('verify', '', 'verification')->bytes();

        $blank = RenderedPdf::from($renderer->renderIgniterToPdf($igniter));
        $filled = RenderedPdf::from($renderer->renderIgniterToPdf(
            $igniter, null, null, [], ['verify' => 'https://example.test/verify/ABC123']
        ));

        // A verification QR carries no data of its own; only the override
        // gives it a payload, and a payload is what makes modules appear.
        $this->assertGreaterThan($blank->drawnPathSegmentCount(), $filled->drawnPathSegmentCount());
        $this->assertSame([], $renderer->warnings());
    }

    public function test_a_barcode_is_drawn_onto_the_page(): void
    {
        $renderer = $this->renderer();

        $pdf = RenderedPdf::from($renderer->renderIgniterToPdf(
            IgniterFixture::make()->withBarcode('code', 'CERT-000123')->bytes()
        ));

        $this->assertTrue($pdf->isStructurallyValid());
        $this->assertGreaterThan(0, $pdf->drawnImageCount() + $pdf->drawnPathSegmentCount());
        $this->assertSame([], $renderer->warnings());
    }

    public function test_parse_igniter_exposes_the_projects_recipient_columns_and_element_catalog(): void
    {
        $project = Certigniter::parseIgniter(
            IgniterFixture::make(title: 'Catalogued')
                ->withText('title', 'Certificate of Completion')
                ->withRecipientField('name', 'recipient_name')
                ->withImage('logo')
                ->withQrCode('verify', '', 'verification')
                ->bytes()
        );

        $this->assertSame('Catalogued', $project->title);
        $this->assertSame(['recipient_name'], $project->variableNames());
        $this->assertSame(['verify'], $project->verificationCodeElementIds());
        $this->assertCount(4, $project->elementCatalog());
        $this->assertSame(['logo'], array_column($project->elementCatalog('image'), 'id'));
    }

    public function test_a_package_sealed_with_a_different_key_fails_with_an_actionable_message(): void
    {
        $igniter = IgniterFixture::make()->bytes(str_repeat('z', 32));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/CERTIGNITER_ENCRYPTION_KEY/');

        $this->renderer()->renderIgniterToPdf($igniter);
    }
}
