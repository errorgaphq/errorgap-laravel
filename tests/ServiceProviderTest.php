<?php

declare(strict_types=1);

namespace Errorgap\Laravel\Tests;

use Errorgap\Client;
use Errorgap\Configuration;
use Errorgap\Laravel\ErrorgapServiceProvider;
use Orchestra\Testbench\TestCase;

final class ServiceProviderTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ErrorgapServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('errorgap', [
            'endpoint' => 'https://errorgap.example.com',
            'project_slug' => 'demo',
            'project_id' => 'p_1',
            'api_key' => 'flk_test',
            'environment' => 'testing',
            'async' => false,
            'timeout_seconds' => 5,
            'filter_keys' => null,
            'capture_exceptions' => true,
            'capture_jobs' => true,
        ]);
    }

    public function testBindsConfigurationFromConfigArray(): void
    {
        $configuration = $this->app->make(Configuration::class);
        $this->assertInstanceOf(Configuration::class, $configuration);
        $this->assertSame('https://errorgap.example.com', $configuration->endpoint);
        $this->assertSame('demo', $configuration->projectSlug);
        $this->assertSame('p_1', $configuration->projectId);
        $this->assertSame('flk_test', $configuration->apiKey);
        $this->assertSame('testing', $configuration->environment);
        $this->assertFalse($configuration->async);
    }

    public function testBindsClientAsSingleton(): void
    {
        $a = $this->app->make(Client::class);
        $b = $this->app->make(Client::class);
        $this->assertSame($a, $b);
    }

    public function testPublishesConfig(): void
    {
        $provider = $this->app->resolveProvider(ErrorgapServiceProvider::class);
        $this->assertInstanceOf(ErrorgapServiceProvider::class, $provider);
    }
}
