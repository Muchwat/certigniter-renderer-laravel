<?php

namespace Certigniter\CertificateRenderer;

use Illuminate\Support\ServiceProvider;

class CertificateRendererServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/certigniter.php', 'certigniter');

        $this->app->singleton(CertificateRenderer::class, function ($app) {
            $config = $app['config']->get('certigniter');

            return new CertificateRenderer(
                encryptionKey: (string) $config['encryption_key'],
                composeGroupTransforms: (bool) ($config['compose_group_transforms'] ?? true),
                ghostscriptBinary: (string) ($config['ghostscript_binary'] ?? 'gs'),
            );
        });

        $this->app->alias(CertificateRenderer::class, 'certigniter');
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'certigniter');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/certigniter.php' => config_path('certigniter.php'),
            ], 'certigniter-config');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/certigniter'),
            ], 'certigniter-views');
        }
    }
}
