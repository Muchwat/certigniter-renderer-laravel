<?php

declare(strict_types=1);

namespace Certigniter\CertificateRenderer\Tests\Unit;

use Certigniter\CertificateRenderer\Data\CertificateProject;
use PHPUnit\Framework\TestCase;

class CertificateProjectTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
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

    public function test_date_format_is_parsed_from_the_project(): void
    {
        $project = CertificateProject::fromArray($this->rawProject(['date_format' => 'yyyy-MM-dd']));

        $this->assertSame('yyyy-MM-dd', $project->dateFormat);
    }

    public function test_date_format_defaults_when_absent(): void
    {
        // Every .igniter file saved before this field existed lacks the
        // key entirely - matches Design Studio's own de-facto default.
        $project = CertificateProject::fromArray($this->rawProject());

        $this->assertSame('MMM d, yyyy', $project->dateFormat);
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

    public function test_variable_names_uses_a_dynamic_codes_column_and_skips_verification_codes(): void
    {
        $raw = $this->rawProject([
            'elements' => [
                ['id' => 'bc', 'type' => 'barcode', 'x' => 0, 'y' => 0, 'width' => 1, 'height' => 1, 'properties' => ['qrType' => 'dynamic', 'variableName' => 'Certificate ID']],
                ['id' => 'qr', 'type' => 'qrcode', 'x' => 0, 'y' => 0, 'width' => 1, 'height' => 1, 'properties' => ['qrType' => 'dynamic', 'variableName' => 'Serial', 'data' => '{{ Serial }}']],
                ['id' => 'vbc', 'type' => 'barcode', 'x' => 0, 'y' => 0, 'width' => 1, 'height' => 1, 'properties' => ['qrType' => 'verification', 'data' => '']],
                ['id' => 'sqr', 'type' => 'qrcode', 'x' => 0, 'y' => 0, 'width' => 1, 'height' => 1, 'properties' => ['data' => 'https://verify/{{ Code }}']],
            ],
        ]);
        $project = CertificateProject::fromArray($raw);

        $this->assertSame(['Certificate ID', 'Serial', 'Code'], $project->variableNames());
    }

    public function test_verification_code_element_ids_finds_verification_qr_codes_and_barcodes(): void
    {
        $raw = $this->rawProject([
            'elements' => [
                ['id' => 'qr', 'type' => 'qrcode', 'x' => 0, 'y' => 0, 'width' => 1, 'height' => 1, 'properties' => ['qrType' => 'verification']],
                ['id' => 'static', 'type' => 'barcode', 'x' => 0, 'y' => 0, 'width' => 1, 'height' => 1, 'properties' => ['data' => '123']],
                ['id' => 'bc', 'type' => 'barcode', 'x' => 0, 'y' => 0, 'width' => 1, 'height' => 1, 'properties' => ['qrType' => 'verification']],
            ],
        ]);
        $project = CertificateProject::fromArray($raw);

        $this->assertSame(['qr', 'bc'], $project->verificationCodeElementIds());
        $this->assertSame('qr', $project->authenticationQrElementId(), 'the QR-only lookup is unchanged');
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

    public function test_element_catalog_reports_a_background_hint_for_ai_generated_images_even_when_resized_below_the_size_fallback(): void
    {
        $raw = $this->rawProject([
            'elements' => [
                [
                    'id' => 'bg', 'type' => 'image', 'x' => 0, 'y' => 0, 'width' => 50, 'height' => 40,
                    'properties' => [
                        'aiGenerated' => true,
                        'aiModel' => 'gemini-3.1-flash-image',
                        'aiPrompt' => str_repeat('an ornate gold border certificate background ', 5),
                        'aiGeneratedAt' => '2026-09-17T00:00:00Z',
                    ],
                ],
            ],
        ]);
        $project = CertificateProject::fromArray($raw);

        $details = $project->elementCatalog('image')[0]['details'];

        $this->assertSame('background', $details['replacementHint'], 'the aiGenerated tag must win even though this image is far below the 90% size fallback');
        $this->assertTrue($details['aiGenerated']);
        $this->assertSame('gemini-3.1-flash-image', $details['aiModel']);
        $this->assertSame('2026-09-17T00:00:00Z', $details['aiGeneratedAt']);
        $this->assertStringEndsWith('…', $details['aiPromptPreview']);
    }

    public function test_element_catalog_reports_ai_metadata_as_null_and_false_for_a_regular_image(): void
    {
        $raw = $this->rawProject([
            'elements' => [
                ['id' => 'sig', 'type' => 'image', 'x' => 0, 'y' => 0, 'width' => 30, 'height' => 10, 'properties' => ['path' => '/tmp/sig.png']],
            ],
        ]);
        $project = CertificateProject::fromArray($raw);

        $details = $project->elementCatalog('image')[0]['details'];

        $this->assertFalse($details['aiGenerated']);
        $this->assertNull($details['aiModel']);
        $this->assertNull($details['aiGeneratedAt']);
        $this->assertNull($details['aiPromptPreview']);
    }

    public function test_get_element_ids_is_an_alias_for_element_catalog(): void
    {
        $project = CertificateProject::fromArray($this->rawProject());

        $this->assertSame($project->elementCatalog(), $project->getElementIds());
        $this->assertSame($project->elementCatalog('text'), $project->getElementIds('text'));
    }

    public function test_unnamed_elements_with_a_known_role_get_a_smart_default_label(): void
    {
        $raw = $this->rawProject([
            'elements' => [
                ['id' => 'sig1', 'type' => 'image', 'x' => 0, 'y' => 0, 'width' => 1, 'height' => 1, 'properties' => ['placeholderRole' => 'signature_image']],
                ['id' => 'sig2', 'type' => 'image', 'x' => 0, 'y' => 0, 'width' => 1, 'height' => 1, 'properties' => ['placeholderRole' => 'signature_image']],
                ['id' => 'bar1', 'type' => 'barcode', 'x' => 0, 'y' => 0, 'width' => 1, 'height' => 1],
            ],
        ]);
        $project = CertificateProject::fromArray($raw);

        $labels = array_column($project->elementCatalog(), 'label', 'id');

        $this->assertSame('Signature 1', $labels['sig1']);
        $this->assertSame('Signature 2', $labels['sig2']);
        $this->assertSame('Barcode 1', $labels['bar1']);
    }

    public function test_unrecognized_elements_fall_back_to_a_numbered_generic_label(): void
    {
        $raw = $this->rawProject([
            'elements' => [
                ['id' => 'x1', 'type' => 'widget', 'x' => 0, 'y' => 0, 'width' => 1, 'height' => 1],
                ['id' => 'x2', 'type' => 'widget', 'x' => 0, 'y' => 0, 'width' => 1, 'height' => 1],
            ],
        ]);
        $project = CertificateProject::fromArray($raw);

        $labels = array_column($project->elementCatalog(), 'label', 'id');

        $this->assertSame('Widget 1', $labels['x1']);
        $this->assertSame('Widget 2', $labels['x2']);
    }

    public function test_a_named_element_keeps_its_custom_name_over_any_default(): void
    {
        $raw = $this->rawProject([
            'elements' => [
                ['id' => 'sig', 'type' => 'image', 'x' => 0, 'y' => 0, 'width' => 1, 'height' => 1, 'properties' => ['placeholderRole' => 'signature_image', 'name' => 'CEO Signature']],
            ],
        ]);
        $project = CertificateProject::fromArray($raw);

        $this->assertSame('CEO Signature', $project->elementCatalog()[0]['label']);
    }

    public function test_a_text_element_with_no_custom_name_previews_its_own_content(): void
    {
        $project = CertificateProject::fromArray($this->rawProject());

        $this->assertSame('Hello', $project->elementCatalog()[0]['label']);
    }

    public function test_an_empty_text_element_labels_itself_rather_than_falling_back_to_a_number(): void
    {
        $raw = $this->rawProject([
            'elements' => [
                ['id' => 't', 'type' => 'text', 'x' => 0, 'y' => 0, 'width' => 1, 'height' => 1, 'properties' => ['text' => '']],
            ],
        ]);
        $project = CertificateProject::fromArray($raw);

        $this->assertSame('[Empty Text]', $project->elementCatalog()[0]['label']);
    }

    public function test_a_dynamic_text_placeholder_with_no_custom_name_previews_its_humanized_variable_name(): void
    {
        $raw = $this->rawProject([
            'elements' => [
                ['id' => 't', 'type' => 'placeholder_text', 'x' => 0, 'y' => 0, 'width' => 1, 'height' => 1, 'properties' => ['placeholderRole' => 'dynamic_text', 'variableName' => 'company_name']],
            ],
        ]);
        $project = CertificateProject::fromArray($raw);

        $this->assertSame('Company Name', $project->elementCatalog()[0]['label']);
    }
}
