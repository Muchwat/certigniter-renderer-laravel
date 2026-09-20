<?php

declare(strict_types=1);

namespace Certigniter\CertificateRenderer\Tests\Unit;

use Certigniter\CertificateRenderer\Data\CertificateProject;
use Certigniter\CertificateRenderer\Data\DesignElement;
use Certigniter\CertificateRenderer\Support\RecipientMerge;
use PHPUnit\Framework\TestCase;

class RecipientMergeTest extends TestCase
{
    private function textElement(?string $variableName, string $text = 'unused', ?string $dateFormat = null): DesignElement
    {
        return new DesignElement(
            id: 'e1',
            type: 'placeholder_text',
            x: 0,
            y: 0,
            width: 10,
            height: 10,
            properties: array_filter([
                'text' => $text,
                'variableName' => $variableName,
                'dateFormat' => $dateFormat,
            ], fn ($v) => $v !== null),
        );
    }

    private function project(string $dateFormat = 'MMM d, yyyy'): CertificateProject
    {
        return new CertificateProject(
            id: 'p1', title: 'Test', width: 100, height: 100, unit: 'mm',
            elements: [], dateFormat: $dateFormat,
        );
    }

    public function test_a_variable_name_replaces_the_field_wholesale(): void
    {
        $merged = RecipientMerge::apply(
            $this->textElement('Recipient Name'),
            ['Recipient Name' => 'Ada Lovelace'],
        );

        $this->assertSame('Ada Lovelace', $merged->property('text'));
    }

    public function test_matching_is_case_insensitive_and_trims_the_column_name(): void
    {
        $merged = RecipientMerge::apply(
            $this->textElement('Recipient Name'),
            [' recipient name ' => 'Ada'],
        );

        $this->assertSame('Ada', $merged->property('text'));
    }

    public function test_a_static_element_with_no_variable_name_passes_through_unchanged(): void
    {
        $element = $this->textElement(null, 'Static Title');

        $merged = RecipientMerge::apply($element, ['Recipient Name' => 'Ada']);

        $this->assertSame('Static Title', $merged->property('text'));
        $this->assertSame($element, $merged, 'no clone should be made when nothing changes');
    }

    public function test_an_unmatched_variable_name_resolves_to_empty_string_not_the_original_text(): void
    {
        $merged = RecipientMerge::apply(
            $this->textElement('Missing Column', 'unused placeholder'),
            ['Recipient Name' => 'Ada'],
        );

        $this->assertSame('', $merged->property('text'));
    }

    public function test_qrcode_data_substitutes_double_brace_and_angle_bracket_tokens(): void
    {
        $qr = new DesignElement(
            id: 'qr', type: 'qrcode', x: 0, y: 0, width: 10, height: 10,
            properties: ['data' => 'https://verify/{{Certificate ID}}?u=<Recipient Name>'],
        );

        $merged = RecipientMerge::apply($qr, [
            'Certificate ID' => 'CERT-1',
            'Recipient Name' => 'Ada',
        ]);

        $this->assertSame('https://verify/CERT-1?u=Ada', $merged->property('data'));
    }

    public function test_token_substitution_is_case_sensitive_and_not_trimmed(): void
    {
        $qr = new DesignElement(
            id: 'qr', type: 'qrcode', x: 0, y: 0, width: 10, height: 10,
            properties: ['data' => '{{certificate id}}'],
        );

        $merged = RecipientMerge::apply($qr, ['Certificate ID' => 'CERT-1']);

        $this->assertSame('{{certificate id}}', $merged->property('data'), 'differently-cased token must not match');
    }

    public function test_token_substitution_ignores_whitespace_inside_the_delimiters(): void
    {
        $qr = new DesignElement(
            id: 'qr', type: 'qrcode', x: 0, y: 0, width: 10, height: 10,
            properties: ['data' => 'https://verify/{{ Certificate ID }}?u=< Recipient Name >'],
        );

        $merged = RecipientMerge::apply($qr, ['Certificate ID' => 'CERT-1', 'Recipient Name' => 'Ada']);

        $this->assertSame('https://verify/CERT-1?u=Ada', $merged->property('data'));
    }

    public function test_a_dynamic_barcode_resolves_its_variable_name_as_a_whole_field(): void
    {
        $barcode = new DesignElement(
            id: 'bc', type: 'barcode', x: 0, y: 0, width: 10, height: 10,
            properties: ['qrType' => 'dynamic', 'variableName' => 'Certificate ID', 'data' => 'ignored'],
        );

        $merged = RecipientMerge::apply($barcode, [' certificate id ' => 'CERT-42']);

        $this->assertSame('CERT-42', $merged->property('data'));
    }

    public function test_a_dynamic_qr_resolves_its_variable_name_rather_than_the_mirrored_token(): void
    {
        $qr = new DesignElement(
            id: 'qr', type: 'qrcode', x: 0, y: 0, width: 10, height: 10,
            properties: ['qrType' => 'dynamic', 'variableName' => 'certificate_id', 'data' => '{{ certificate_id }}'],
        );

        $merged = RecipientMerge::apply($qr, ['Certificate_ID' => 'CERT-7']);

        $this->assertSame('CERT-7', $merged->property('data'));
    }

    public function test_a_dynamic_code_with_no_matching_column_resolves_to_empty_string(): void
    {
        $barcode = new DesignElement(
            id: 'bc', type: 'barcode', x: 0, y: 0, width: 10, height: 10,
            properties: ['qrType' => 'dynamic', 'variableName' => 'Certificate ID'],
        );

        $merged = RecipientMerge::apply($barcode, ['Recipient Name' => 'Ada']);

        $this->assertSame('', $merged->property('data'));
    }

    public function test_group_and_other_types_pass_through_untouched(): void
    {
        $group = new DesignElement(id: 'g', type: 'group', x: 0, y: 0, width: 10, height: 10);

        $merged = RecipientMerge::apply($group, ['x' => 'y']);

        $this->assertSame($group, $merged);
    }

    public function test_record_value_tries_candidates_in_order_and_falls_back_to_empty_string(): void
    {
        $record = ['Certificate ID' => 'CERT-1'];

        $this->assertSame('CERT-1', RecipientMerge::recordValue($record, ['Missing', 'Certificate ID']));
        $this->assertSame('', RecipientMerge::recordValue($record, ['Nope']));
    }

    public function test_without_a_project_a_date_value_passes_through_unformatted(): void
    {
        $merged = RecipientMerge::apply(
            $this->textElement('Issue Date'),
            ['Issue Date' => '2026-01-05'],
        );

        $this->assertSame('2026-01-05', $merged->property('text'));
    }

    public function test_a_date_element_without_its_own_format_inherits_the_project_default(): void
    {
        $merged = RecipientMerge::apply(
            $this->textElement('Issue Date'),
            ['Issue Date' => '2026-01-05'],
            $this->project(dateFormat: 'yyyy-MM-dd'),
        );

        $this->assertSame('2026-01-05', $merged->property('text'));
    }

    public function test_a_date_element_with_its_own_format_overrides_the_project_default(): void
    {
        $merged = RecipientMerge::apply(
            $this->textElement('Issue Date', dateFormat: 'MMM d, yyyy'),
            ['Issue Date' => '2026-01-05'],
            $this->project(dateFormat: 'yyyy-MM-dd'),
        );

        $this->assertSame('Jan 5, 2026', $merged->property('text'));
    }

    public function test_two_date_elements_on_the_same_certificate_can_use_different_formats(): void
    {
        $project = $this->project(dateFormat: 'yyyy-MM-dd');

        $issueDate = RecipientMerge::apply(
            $this->textElement('Issue Date', dateFormat: 'MMM d, yyyy'),
            ['Issue Date' => '2026-01-05', 'Expiry Date' => '2027-01-05'],
            $project,
        );
        $expiryDate = RecipientMerge::apply(
            $this->textElement('Expiry Date', dateFormat: 'dd/MM/yyyy'),
            ['Issue Date' => '2026-01-05', 'Expiry Date' => '2027-01-05'],
            $project,
        );

        $this->assertSame('Jan 5, 2026', $issueDate->property('text'));
        $this->assertSame('05/01/2027', $expiryDate->property('text'));
    }

    public function test_a_non_date_value_passes_through_unchanged_even_with_a_project(): void
    {
        $merged = RecipientMerge::apply(
            $this->textElement('Recipient Name'),
            ['Recipient Name' => 'John Doe'],
            $this->project(),
        );

        $this->assertSame('John Doe', $merged->property('text'));
    }
}
