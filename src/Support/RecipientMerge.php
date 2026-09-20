<?php

declare(strict_types=1);

namespace Certigniter\CertificateRenderer\Support;

use Certigniter\CertificateRenderer\Data\CertificateProject;
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
 *    its `text` through unchanged. When $project is supplied and the
 *    matched value parses as a date (see `DateFormatting::tryFormat`), it
 *    is re-rendered using the element's own `dateFormat` property, or the
 *    project's own `dateFormat` if the element didn't set one.
 *  - qrcode / barcode `data`: INLINE token substitution - every
 *    `{{ColumnName}}` and `<ColumnName>` occurrence in the string is
 *    replaced, for every column in the record, case-SENSITIVE (the token
 *    must match the CSV header exactly, after the header itself was already
 *    whitespace-normalized on parse). Whitespace just inside the delimiters
 *    is ignored, so `{{ ColumnName }}` - the form both Studio editors write -
 *    resolves the same as `{{ColumnName}}`. A token with no matching column
 *    is left in place.
 *
 * A qrcode OR barcode whose content source is "Dynamic value" (`qrType:
 * 'dynamic'`, see DesignElement::isDynamicCode()) is bound to one column via
 * `properties.variableName` and is resolved like a text element's
 * `variableName` instead: a WHOLE-FIELD replacement, matched
 * case-insensitively and trimmed - so a barcode can encode e.g. a
 * per-recipient certificate ID. The `{{ variableName }}` mirror both editors
 * also write into a dynamic QR's `data` is only used when `variableName`
 * itself is empty. "Verification link" codes (DesignElement::isVerificationCode())
 * have no recipient column at all - their payload comes from the renderer's
 * `qrCodeOverrides`.
 */
class RecipientMerge
{
    /** @param array<string, string> $record */
    public static function apply(DesignElement $element, array $record, ?CertificateProject $project = null): DesignElement
    {
        if ($element->isTextLike()) {
            return self::applyToText($element, $record, $project);
        }

        if ($element->isCode()) {
            return self::applyToData($element, $record);
        }

        return $element;
    }

    /** @param array<string, string> $record */
    private static function applyToText(DesignElement $element, array $record, ?CertificateProject $project): DesignElement
    {
        $variableName = $element->property('variableName');

        if (! is_string($variableName) || $variableName === '') {
            return $element;
        }

        $clone = clone $element;
        $rawValue = self::recordValue($record, [$variableName]);
        $clone->properties['text'] = self::applyDateFormat($element, $rawValue, $project);

        return $clone;
    }

    private static function applyDateFormat(DesignElement $element, string $rawValue, ?CertificateProject $project): string
    {
        if ($project === null) {
            return $rawValue;
        }

        $override = $element->property('dateFormat');
        $icuPattern = (is_string($override) && $override !== '') ? $override : $project->dateFormat;

        return DateFormatting::tryFormat($rawValue, $icuPattern) ?? $rawValue;
    }

    /** @param array<string, string> $record */
    private static function applyToData(DesignElement $element, array $record): DesignElement
    {
        $variableName = $element->property('variableName');

        if ($element->isDynamicCode() && is_string($variableName) && trim($variableName) !== '') {
            $clone = clone $element;
            $clone->properties['data'] = self::recordValue($record, [$variableName]);

            return $clone;
        }

        $data = $element->property('data');

        if (! is_string($data) || $data === '') {
            return $element;
        }

        $clone = clone $element;
        $clone->properties['data'] = self::substituteTokens($data, $record);

        return $clone;
    }

    /**
     * @param  array<string, string>  $record
     * @param  string[]  $candidateNames
     */
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
        return (string) preg_replace_callback(
            '/\{\{([^{}]+)\}\}|<([^<>]+)>/',
            /** @param array<int, string> $match */
            function (array $match) use ($record): string {
                // Group 1 is always present - PHP fills an unmatched
                // intermediate group with '' - but group 2 is dropped
                // entirely when the {{token}} branch is the one that matched.
                $token = $match[1] !== '' ? $match[1] : ($match[2] ?? '');

                foreach ([$token, trim($token)] as $key) {
                    if (array_key_exists($key, $record)) {
                        return (string) $record[$key];
                    }
                }

                return $match[0];
            },
            $template,
        );
    }

    private static function fold(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
