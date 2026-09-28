<?php

declare(strict_types=1);

namespace Certigniter\CertificateRenderer\Support;

use DateTimeInterface;

/**
 * Translates a `CertificateProject::$dateFormat` value, an ICU date
 * pattern such as `'MMM d, yyyy'`, into PHP's `date()` format syntax, so
 * the calling application can format a date exactly as the certificate's
 * designer chose.
 *
 * `RecipientMerge::apply()` uses {@see self::tryFormat()} automatically
 * when given a `CertificateProject`: a text-like element's own
 * `properties['dateFormat']` wins if set, otherwise the project's
 * `$dateFormat` applies, and a recipient value that does not parse as
 * {@see self::DATE_TRANSPORT_FORMAT} (a name, say) is left untouched. Two
 * date elements on one certificate, such as Issue Date and Expiry Date,
 * can therefore use different formats. To pre-format a
 * `DateTimeInterface` yourself, call {@see self::format()}:
 *
 *     $recipient['Issue Date'] = DateFormatting::format($issuedAt, $project->dateFormat);
 *
 * This is deliberately a small, tested lookup table of the supported
 * patterns rather than a general ICU-to-PHP translator. The two syntaxes
 * do not map onto each other mechanically (ICU `M` is a numeric month;
 * PHP `M` is an abbreviated month name), so an unknown pattern falls back
 * to the default rather than risk a translation that is silently wrong.
 */
final class DateFormatting
{
    /** @var array<string, string> ICU pattern => PHP date() format */
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
     * The machine-readable format (ISO 8601, `yyyy-MM-dd`) in which date
     * values are expected to arrive in recipient data. It is independent of
     * any display format, so {@see self::tryFormat()} can tell a real date
     * apart from an ordinary string value.
     */
    public const DATE_TRANSPORT_FORMAT = 'Y-m-d';

    /** The PHP `date()` format equivalent to a supported ICU pattern, or to the default pattern if it is not supported. */
    public static function toPhpFormat(string $icuPattern): string
    {
        return self::ICU_TO_PHP[$icuPattern] ?? self::ICU_TO_PHP[self::DEFAULT_ICU_PATTERN];
    }

    /** Formats `$date` using the given ICU pattern. */
    public static function format(DateTimeInterface $date, string $icuPattern): string
    {
        return $date->format(self::toPhpFormat($icuPattern));
    }

    /**
     * Parses `$rawValue` as {@see self::DATE_TRANSPORT_FORMAT} and
     * re-renders it with `$icuPattern`. Returns null when `$rawValue` is not
     * a date (a recipient's name, say); callers should then display
     * `$rawValue` unchanged.
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
