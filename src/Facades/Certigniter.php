<?php

declare(strict_types=1);

namespace Certigniter\CertificateRenderer\Facades;

use Certigniter\CertificateRenderer\CertificateRenderer;
use Illuminate\Support\Facades\Facade;

/**
 * @method static string renderIgniterToPdf(string $igniterContents, array<string, string>|null $recipient = null, ?string $encryptionKey = null, array<string, string> $imageOverrides = [], array<string, string> $qrCodeOverrides = [])
 * @method static string capture(string $igniterContents, array<string, string>|null $recipient = null, ?string $encryptionKey = null, array<string, string> $imageOverrides = [], array<string, string> $qrCodeOverrides = [], ?string $outputPath = null, int $resolution = 150)
 * @method static \Certigniter\CertificateRenderer\Data\CertificateProject parseIgniter(string $igniterContents, ?string $encryptionKey = null)
 * @method static string renderProjectToPdf(\Certigniter\CertificateRenderer\Data\CertificateProject $project, array<string, string>|null $recipient = null, array<string, string> $imageOverrides = [], array<string, string> $qrCodeOverrides = [])
 * @method static string[] warnings()
 *
 * @see \Certigniter\CertificateRenderer\CertificateRenderer
 */
class Certigniter extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return CertificateRenderer::class;
    }
}
