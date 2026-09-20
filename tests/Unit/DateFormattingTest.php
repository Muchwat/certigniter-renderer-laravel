<?php

declare(strict_types=1);

namespace Certigniter\CertificateRenderer\Tests\Unit;

use Certigniter\CertificateRenderer\Support\DateFormatting;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DateFormattingTest extends TestCase
{
    private function sampleDate(): DateTimeImmutable
    {
        // A date with a single-digit day and month, and a weekday name
        // that isn't ambiguous with any format token, to catch off-by-one
        // padding mistakes (e.g. 'M j, Y' vs 'M d, Y').
        return new DateTimeImmutable('2026-01-05');
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function knownPatternProvider(): array
    {
        return [
            'MMM d, yyyy' => ['MMM d, yyyy', 'Jan 5, 2026'],
            'MMMM d, yyyy' => ['MMMM d, yyyy', 'January 5, 2026'],
            'd MMM yyyy' => ['d MMM yyyy', '5 Jan 2026'],
            'MM/dd/yyyy' => ['MM/dd/yyyy', '01/05/2026'],
            'dd/MM/yyyy' => ['dd/MM/yyyy', '05/01/2026'],
            'yyyy-MM-dd' => ['yyyy-MM-dd', '2026-01-05'],
            'EEEE, MMMM d, yyyy' => ['EEEE, MMMM d, yyyy', 'Monday, January 5, 2026'],
        ];
    }

    #[DataProvider('knownPatternProvider')]
    public function test_each_known_design_studio_pattern_formats_as_expected(string $icuPattern, string $expected): void
    {
        $this->assertSame($expected, DateFormatting::format($this->sampleDate(), $icuPattern));
    }

    public function test_an_unrecognized_pattern_falls_back_to_the_default_instead_of_guessing(): void
    {
        $this->assertSame(
            DateFormatting::format($this->sampleDate(), DateFormatting::DEFAULT_ICU_PATTERN),
            DateFormatting::format($this->sampleDate(), 'not a known pattern'),
        );
    }

    public function test_to_php_format_returns_the_raw_translation(): void
    {
        $this->assertSame('Y-m-d', DateFormatting::toPhpFormat('yyyy-MM-dd'));
    }

    public function test_try_format_reformats_a_canonical_transport_value(): void
    {
        $this->assertSame('Jan 5, 2026', DateFormatting::tryFormat('2026-01-05', 'MMM d, yyyy'));
    }

    public function test_try_format_returns_null_for_a_value_that_is_not_a_date(): void
    {
        $this->assertNull(DateFormatting::tryFormat('John Doe', 'MMM d, yyyy'));
    }

    public function test_try_format_returns_null_for_an_empty_value(): void
    {
        $this->assertNull(DateFormatting::tryFormat('', 'MMM d, yyyy'));
    }

    public function test_try_format_rejects_an_invalid_calendar_date(): void
    {
        $this->assertNull(DateFormatting::tryFormat('2026-13-45', 'MMM d, yyyy'));
    }
}
