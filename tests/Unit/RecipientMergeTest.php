<?php

namespace Certigniter\CertificateRenderer\Tests\Unit;

use Certigniter\CertificateRenderer\Data\DesignElement;
use Certigniter\CertificateRenderer\Support\RecipientMerge;
use PHPUnit\Framework\TestCase;

class RecipientMergeTest extends TestCase
{
    private function textElement(?string $variableName, string $text = 'unused'): DesignElement
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
            ], fn ($v) => $v !== null),
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
}
