<?php

declare(strict_types=1);

namespace Certigniter\CertificateRenderer\Support;

use DateTimeInterface;

/**
 * Translates a `CertificateProject::$dateFormat` value - a Dart/ICU-style
 * pattern (e.g. `'MMM d, yyyy'`), the same syntax Design Studio's date
 * format picker uses via `intl`'s `DateFormat` - into PHP's native
 * `date()` token syntax, so a host application can format a raw date the
 * same way the certificate's designer chose.
 *
 * `RecipientMerge::apply()` uses [tryFormat] automatically when given a
 * `CertificateProject`: a text-like element's own `properties['dateFormat']`
 * wins if set, else the project's own `$dateFormat` applies, and a raw
 * record value that doesn't parse as [DATE_TRANSPORT_FORMAT] (e.g. a name,
 * not a date) is left untouched. This lets two date elements on the same
 * certificate - Issue Date and Expiry Date, say - render in different
 * formats. A caller can still use [format] directly to pre-format a real
 * `DateTimeInterface` before building the recipient map, e.g.
 * `$recipient['Issue Date'] = DateFormatting::format($issuedAt, $project->dateFormat);`
 *
 * Deliberately a small, hand-tested lookup table covering exactly the
 * preset list Design Studio's picker offers
 * (lib/utils/date_format_presets.dart), not a general ICU-to-PHP pattern
 * parser - the two token syntaxes don't map onto each other mechanically
 * (e.g. ICU `M` is a numeric month, PHP `M` is an abbreviated month name),
 * so translating an arbitrary pattern correctly would need real ICU-aware
 * parsing. A pattern outside this known set falls back to the default
 * rather than guessing at a translation that could silently be wrong.
 */
final class DateFormatting
{
    /** @var array<string, string> Dart/ICU pattern => PHP date() format */
    private const ICU_TO_PHP = [
        'MMM d, yyyy' => 'M j, Y',
        'MMMM d, yyyy' => 'F j, Y',
        'd MMM yyyy' => 'j M Y',
        'MM/dd/yyyy' => 'm/d/Y',
        'dd/MM/yyyy' => 'd/m/Y',
        'yyyy-MM-dd' => 'Y-m-d',
        'EEEE, MMMM d, yyyy' => 'l, F j, Y',
    ];

    public const DEFAULT_ICU_PATTERN = 'MMM d, yyyy';

    /**
     * The canonical machine-readable format date-typed recipient values are
     * expected to arrive in (matches Design Studio's spreadsheet export -
     * see `date_format_presets.dart`'s `kDateTransportPattern`), decoupled
     * from any display format so [tryFormat] can tell a real date apart
     * from an ordinary string value.
     */
    public const DATE_TRANSPORT_FORMAT = 'Y-m-d';

    /** The PHP `date()` format string equivalent to a Design Studio date-format pattern. */
    public static function toPhpFormat(string $icuPattern): string
    {
        return self::ICU_TO_PHP[$icuPattern] ?? self::ICU_TO_PHP[self::DEFAULT_ICU_PATTERN];
    }

    /** Formats $date the same way Design Studio's date-format picker would for $icuPattern. */
    public static function format(DateTimeInterface $date, string $icuPattern): string
    {
        return $date->format(self::toPhpFormat($icuPattern));
    }

    /**
     * Parses $rawValue as [DATE_TRANSPORT_FORMAT] and re-renders it as
     * $icuPattern, or returns null when $rawValue isn't a date at all (e.g.
     * a non-date variable like a recipient's name) - callers should fall
     * back to displaying $rawValue unchanged in that case.
     */
    public static function tryFormat(string $rawValue, string $icuPattern): ?string
    {
        $trimmed = trim($rawValue);

        if ($trimmed === '') {
            return null;
        }

        $date = \DateTime::createFromFormat('!'.self::DATE_TRANSPORT_FORMAT, $trimmed);

        if ($date === false || $date->format(self::DATE_TRANSPORT_FORMAT) !== $trimmed) {
            return null;
        }

        return self::format($date, $icuPattern);
    }
}
