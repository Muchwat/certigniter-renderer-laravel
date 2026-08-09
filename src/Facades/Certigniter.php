<?php

namespace Certigniter\CertificateRenderer\Facades;

use Certigniter\CertificateRenderer\CertificateRenderer;
use Illuminate\Support\Facades\Facade;

/**
 * @method static string renderIgniterToPdf(string $encryptedIgniterContent, ?array $recipient = null, ?string $encryptionKey = null)
 * @method static \Certigniter\CertificateRenderer\Data\CertificateProject parseIgniter(string $encryptedIgniterContent, ?string $encryptionKey = null)
 * @method static string renderProjectToPdf(\Certigniter\CertificateRenderer\Data\CertificateProject $project, ?array $recipient = null)
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
