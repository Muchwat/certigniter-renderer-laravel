<?php

declare(strict_types=1);

namespace Certigniter\CertificateRenderer\Tests\Integration;

use Certigniter\CertificateRenderer\CertificateRenderer;
use Certigniter\CertificateRenderer\Facades\Certigniter;
use Certigniter\CertificateRenderer\Support\FontRegistrar;
use Certigniter\CertificateRenderer\Tests\Fixtures\IgniterFixture;
use Illuminate\Support\Facades\File;
use Illuminate\View\FileViewFinder;

/**
 * What the service provider promises a host application, asserted against
 * a real booted application rather than by reading the provider.
 */
class ServiceProviderTest extends TestCase
{
    /** @var string[] paths published during a test, removed again afterwards */
    private array $published = [];

    protected function tearDown(): void
    {
        foreach ($this->published as $path) {
            if (is_dir($path)) {
                File::deleteDirectory($path);
            } else {
                @unlink($path);
            }
        }
        $this->published = [];

        parent::tearDown();
    }

    /** Run `vendor:publish` for one of the package's tags and return its exit code. */
    private function publish(string $tag): int
    {
        $command = $this->artisan('vendor:publish', ['--tag' => $tag]);

        return is_int($command) ? $command : $command->run();
    }

    public function test_the_packages_config_is_merged_into_the_application(): void
    {
        $this->assertIsString(config('certigniter.encryption_key'));
        $this->assertSame(IgniterFixture::KEY, config('certigniter.encryption_key'));
        $this->assertTrue(config('certigniter.compose_group_transforms'));
        $this->assertSame('gs', config('certigniter.ghostscript_binary'));
    }

    public function test_the_renderer_resolves_as_a_singleton_and_through_its_alias(): void
    {
        $renderer = $this->application()->make(CertificateRenderer::class);

        $this->assertInstanceOf(CertificateRenderer::class, $renderer);
        $this->assertSame($renderer, $this->application()->make(CertificateRenderer::class));
        $this->assertSame($renderer, $this->application()->make('certigniter'));
        $this->assertSame($renderer, Certigniter::getFacadeRoot());
    }

    public function test_the_renderer_is_built_from_configuration_rather_than_its_own_defaults(): void
    {
        config()->set('certigniter.encryption_key', $key = str_repeat('k', 32));
        $this->application()->forgetInstance(CertificateRenderer::class);

        // A package sealed with the configured key opens without the caller
        // passing a key at all - which is only true if the provider read
        // config when it built the renderer.
        $project = Certigniter::parseIgniter(IgniterFixture::make(title: 'Configured')->bytes($key));

        $this->assertSame('Configured', $project->title);
    }

    public function test_the_certificate_view_is_registered_under_the_packages_namespace(): void
    {
        $finder = $this->views()->getFinder();
        $this->assertInstanceOf(FileViewFinder::class, $finder);
        $hints = $finder->getHints();

        $this->assertArrayHasKey('certigniter', $hints);
        $this->assertContains(
            realpath(__DIR__.'/../../resources/views'),
            array_map('realpath', $hints['certigniter']),
        );
        $this->assertStringContainsString(
            'certificate-container',
            $this->views()->make('certigniter::certificate', [
                'project' => Certigniter::parseIgniter(IgniterFixture::make()->bytes()),
                'elements' => [],
                'fonts' => new FontRegistrar,
                'imageSources' => [],
                'codeSources' => [],
            ])->render(),
        );
    }

    public function test_the_config_file_can_be_published(): void
    {
        $target = config_path('certigniter.php');
        $this->published[] = $target;
        @unlink($target);

        $this->assertSame(0, $this->publish('certigniter-config'));

        $this->assertFileExists($target);
        $this->assertArrayHasKey('encryption_key', require $target);
    }

    public function test_the_certificate_view_can_be_published_for_overriding(): void
    {
        $target = resource_path('views/vendor/certigniter');
        $this->published[] = $target;
        File::deleteDirectory($target);

        $this->assertSame(0, $this->publish('certigniter-views'));

        $this->assertFileExists($target.'/certificate.blade.php');
    }
}
