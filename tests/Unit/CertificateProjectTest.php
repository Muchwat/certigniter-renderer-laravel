<?php

namespace Certigniter\CertificateRenderer\Tests\Unit;

use Certigniter\CertificateRenderer\Data\CertificateProject;
use PHPUnit\Framework\TestCase;

class CertificateProjectTest extends TestCase
{
    private function rawProject(array $overrides = []): array
    {
        // A plain top-level merge (not array_replace_recursive) so an
        // 'elements' or 'size' override fully replaces the default rather
        // than being recursively merged index-by-index with it.
        return array_merge([
            'id' => 'p1',
            'title' => 'Sample',
            'color_format' => 'css-hex',
            'size' => ['width' => 200.0, 'height' => 150.0, 'unit' => 'mm'],
            'elements' => [
                ['id' => 't1', 'type' => 'text', 'x' => 0, 'y' => 0, 'width' => 10, 'height' => 10, 'properties' => ['text' => 'Hello']],
            ],
        ], $overrides);
    }

    public function test_from_array_parses_scalar_fields_and_size(): void
    {
        $project = CertificateProject::fromArray($this->rawProject());

        $this->assertSame('p1', $project->id);
        $this->assertSame('Sample', $project->title);
        $this->assertSame(200.0, $project->width);
        $this->assertSame(150.0, $project->height);
        $this->assertSame('mm', $project->unit);
        $this->assertSame('css-hex', $project->colorFormat);
        $this->assertCount(1, $project->elements);
    }

    public function test_color_format_defaults_to_legacy_argb_when_absent(): void
    {
        $raw = $this->rawProject();
        unset($raw['color_format']);

        $project = CertificateProject::fromArray($raw);

        $this->assertSame('argb', $project->colorFormat);
    }

    public function test_a_legacy_top_level_background_becomes_a_synthetic_full_bleed_image_element(): void
    {
        $raw = $this->rawProject([
            'background' => ['path' => '/tmp/bg.png'],
        ]);

        $project = CertificateProject::fromArray($raw);

        $this->assertSame('legacy-background', $project->elements[0]->id);
        $this->assertSame('image', $project->elements[0]->type);
        $this->assertSame(0.0, $project->elements[0]->x);
        $this->assertSame(200.0, $project->elements[0]->width);
        $this->assertSame(150.0, $project->elements[0]->height);
        $this->assertSame('t1', $project->elements[1]->id, 'original elements keep their order after the synthetic background');
    }

    public function test_no_synthetic_background_element_is_added_when_none_is_present(): void
    {
        $project = CertificateProject::fromArray($this->rawProject());

        $this->assertCount(1, $project->elements);
        $this->assertNotSame('legacy-background', $project->elements[0]->id);
    }

    public function test_children_returns_only_the_groups_own_children_in_project_order(): void
    {
        $raw = $this->rawProject([
            'elements' => [
                ['id' => 'a', 'type' => 'text', 'x' => 0, 'y' => 0, 'width' => 1, 'height' => 1],
                ['id' => 'group', 'type' => 'group', 'x' => 0, 'y' => 0, 'width' => 1, 'height' => 1, 'childrenIds' => ['b', 'c']],
                ['id' => 'b', 'type' => 'text', 'x' => 0, 'y' => 0, 'width' => 1, 'height' => 1],
                ['id' => 'c', 'type' => 'text', 'x' => 0, 'y' => 0, 'width' => 1, 'height' => 1],
            ],
        ]);
        $project = CertificateProject::fromArray($raw);
        $group = $project->elements[1];

        $children = $project->children($group);

        $this->assertSame(['b', 'c'], array_map(fn ($e) => $e->id, $children));
    }

    public function test_variable_names_collects_text_variables_and_qr_barcode_tokens_in_first_seen_order(): void
    {
        $raw = $this->rawProject([
            'elements' => [
                ['id' => 't', 'type' => 'placeholder_text', 'x' => 0, 'y' => 0, 'width' => 1, 'height' => 1, 'properties' => ['variableName' => 'Recipient Name']],
                ['id' => 'qr', 'type' => 'qrcode', 'x' => 0, 'y' => 0, 'width' => 1, 'height' => 1, 'properties' => ['data' => '{{Certificate ID}}-<Recipient Name>']],
            ],
        ]);
        $project = CertificateProject::fromArray($raw);

        $this->assertSame(['Recipient Name', 'Certificate ID'], $project->variableNames());
    }

    public function test_authentication_qr_element_id_finds_the_single_authentication_link_qr(): void
    {
        $raw = $this->rawProject([
            'elements' => [
                ['id' => 'qr1', 'type' => 'qrcode', 'x' => 0, 'y' => 0, 'width' => 1, 'height' => 1, 'properties' => ['qrType' => 'custom']],
                ['id' => 'qr2', 'type' => 'qrcode', 'x' => 0, 'y' => 0, 'width' => 1, 'height' => 1, 'properties' => ['qrType' => 'authentication']],
            ],
        ]);
        $project = CertificateProject::fromArray($raw);

        $this->assertSame('qr2', $project->authenticationQrElementId());
    }

    public function test_authentication_qr_element_id_is_null_when_none_exists(): void
    {
        $project = CertificateProject::fromArray($this->rawProject());

        $this->assertNull($project->authenticationQrElementId());
    }

    public function test_element_catalog_reports_replaceable_images_with_a_signature_hint(): void
    {
        $raw = $this->rawProject([
            'elements' => [
                [
                    'id' => 'sig', 'type' => 'image', 'x' => 0, 'y' => 0, 'width' => 30, 'height' => 10,
                    'properties' => ['name' => 'Director signature', 'path' => '/tmp/sig.png'],
                ],
            ],
        ]);
        $project = CertificateProject::fromArray($raw);

        $catalog = $project->elementCatalog('image');

        $this->assertSame('sig', $catalog[0]['id']);
        $this->assertTrue($catalog[0]['replaceable']);
        $this->assertSame('Director signature', $catalog[0]['label']);
        $this->assertSame('signature', $catalog[0]['details']['replacementHint']);
        $this->assertTrue($catalog[0]['details']['hasLocalPath']);
        $this->assertFalse($catalog[0]['details']['hasEmbeddedData']);
    }

    public function test_get_element_ids_is_an_alias_for_element_catalog(): void
    {
        $project = CertificateProject::fromArray($this->rawProject());

        $this->assertSame($project->elementCatalog(), $project->getElementIds());
        $this->assertSame($project->elementCatalog('text'), $project->getElementIds('text'));
    }
}
