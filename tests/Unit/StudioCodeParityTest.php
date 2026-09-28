<?php

declare(strict_types=1);

namespace Certigniter\CertificateRenderer\Tests\Unit;

use Certigniter\CertificateRenderer\Support\BarcodeEncoding;
use Certigniter\CertificateRenderer\Support\QrEncoder;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The web Studio draws QR codes with the `qrcode` npm package and barcodes
 * with JsBarcode. tests/Fixtures/studio-codes.json holds their output for a
 * spread of inputs (its `generator` key says exactly how it was made); the
 * PHP ports must reproduce it module for module, or a certificate's code
 * would not be the one the designer saw.
 */
class StudioCodeParityTest extends TestCase
{
    /** @return array{qr: list<array{data: string, level: string, rows: list<string>}>, barcodes: list<array{type: string, data: string, modules: string, text: string}>} */
    private static function fixture(): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/../Fixtures/studio-codes.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return iterable<string, array{string, string, list<string>}> */
    public static function qrCases(): iterable
    {
        foreach (self::fixture()['qr'] as $case) {
            yield "{$case['level']} {$case['data']}" => [$case['data'], $case['level'], $case['rows']];
        }
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function barcodeCases(): iterable
    {
        foreach (self::fixture()['barcodes'] as $case) {
            yield "{$case['type']} {$case['data']}" => [$case['type'], $case['data'], $case['modules'], $case['text']];
        }
    }

    /** @param  list<string>  $rows */
    #[DataProvider('qrCases')]
    public function test_a_qr_code_is_the_studios_symbol_module_for_module(string $data, string $level, array $rows): void
    {
        $matrix = array_map(
            fn (array $row): string => implode('', array_map(fn (bool $dark): string => $dark ? '1' : '0', $row)),
            QrEncoder::matrix($data, $level),
        );

        $this->assertSame($rows, $matrix);
    }

    #[DataProvider('barcodeCases')]
    public function test_a_barcode_is_the_studios_symbol_module_for_module(string $type, string $data, string $modules, string $text): void
    {
        $this->assertSame(['modules' => $modules, 'text' => $text], BarcodeEncoding::encode($data, $type));
    }

    public function test_a_value_its_symbology_cannot_carry_is_rejected_as_jsbarcode_rejects_it(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BarcodeEncoding::encode('4006381333932', 'ean13');
    }

    public function test_an_unknown_barcode_type_is_rejected_as_the_studio_rejects_it(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BarcodeEncoding::encode('123456789', 'not-a-real-symbology');
    }
}
