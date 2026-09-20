<?php

declare(strict_types=1);

namespace Certigniter\CertificateRenderer\Tests\Integration;

use Certigniter\CertificateRenderer\CertificateRendererServiceProvider;
use Certigniter\CertificateRenderer\Facades\Certigniter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Orchestra\Testbench\TestCase as TestbenchTestCase;

/**
 * Base for the tests that need a real, booted Laravel application:
 * Testbench boots one, discovers this package's service provider through
 * the same `extra.laravel` metadata a host app's package discovery uses,
 * and tears it down between tests.
 *
 * This is deliberately not mocked. The parts of the package that only
 * exist inside a framework - config merging and publishing, the container
 * binding, the `certigniter::` view namespace, the facade root
 * QrCodeRenderer needs - are exactly the parts that break across a major
 * Laravel release, so they are exercised against the framework itself.
 */
abstract class TestCase extends TestbenchTestCase
{
    /** @return array<int, class-string> */
    protected function getPackageProviders($app): array
    {
        return [CertificateRendererServiceProvider::class];
    }

    /** @return array<string, class-string> */
    protected function getPackageAliases($app): array
    {
        return ['Certigniter' => Certigniter::class];
    }

    /** The application Testbench booted for this test. */
    protected function application(): Application
    {
        $this->assertNotNull($this->app, 'Testbench did not boot an application.');

        return $this->app;
    }

    protected function views(): ViewFactory
    {
        return $this->application()->make(ViewFactory::class);
    }
}
