<?php

namespace Certigniter\CertificateRenderer\Support;

use Certigniter\CertificateRenderer\Data\DesignElement;

/**
 * Certigniter's bulk-CSV issuance (batch_pdf_generator.dart) uses two
 * different, non-interchangeable merge mechanisms depending on element
 * type - replicate both exactly, since a template built for one will not
 * work through the other:
 *
 *  - text / placeholder_text / dynamic_text: `variableName` triggers a
 *    WHOLE-FIELD replacement (the element's own `text` is discarded
 *    entirely), matched against the recipient record case-insensitively
 *    and trimmed. An element with no `variableName` is static and passes
 *    its `text` through unchanged.
 *  - qrcode / barcode `data`: INLINE token substitution - every
 *    `{{ColumnName}}` and `<ColumnName>` occurrence in the string is
 *    replaced, for every column in the record, case-SENSITIVE and
 *    NOT trimmed (the token must match the CSV header exactly, after the
 *    header itself was already whitespace-normalized on parse).
 */
class RecipientMerge
{
    /** @param array<string, string> $record */
    public static function apply(DesignElement $element, array $record): DesignElement
    {
        if ($element->isTextLike()) {
            return self::applyToText($element, $record);
        }

        if (in_array($element->type, ['qrcode', 'barcode'], true)) {
            return self::applyToData($element, $record);
        }

        return $element;
    }

    private static function applyToText(DesignElement $element, array $record): DesignElement
    {
        $variableName = $element->property('variableName');

        if (!is_string($variableName) || $variableName === '') {
            return $element;
        }

        $clone = clone $element;
        $clone->properties['text'] = self::recordValue($record, [$variableName]);

        return $clone;
    }

    private static function applyToData(DesignElement $element, array $record): DesignElement
    {
        $data = $element->property('data');

        if (!is_string($data) || $data === '') {
            return $element;
        }

        $clone = clone $element;
        $clone->properties['data'] = self::substituteTokens($data, $record);

        return $clone;
    }

    /** @param array<string, string> $record @param string[] $candidateNames */
    public static function recordValue(array $record, array $candidateNames): string
    {
        $normalizedRecord = [];
        foreach ($record as $key => $value) {
            $normalizedRecord[self::fold($key)] = $value;
        }

        foreach ($candidateNames as $name) {
            $folded = self::fold($name);

            if (array_key_exists($folded, $normalizedRecord)) {
                return (string) $normalizedRecord[$folded];
            }
        }

        return '';
    }

    /** @param array<string, string> $record */
    private static function substituteTokens(string $template, array $record): string
    {
        $output = $template;

        foreach ($record as $key => $value) {
            $output = str_replace(
                ['{{'.$key.'}}', '<'.$key.'>'],
                (string) $value,
                $output,
            );
        }

        return $output;
    }

    private static function fold(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
