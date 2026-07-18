<?php

declare(strict_types=1);

namespace Errorgap\Laravel\Tests;

use Errorgap\Client;
use Errorgap\Configuration;
use Errorgap\Laravel\ErrorgapServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;

final class ApmServiceProviderIntegrationTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ErrorgapServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('errorgap', [
            'endpoint' => 'https://errorgap.example.com',
            'project_slug' => 'demo',
            'api_key' => 'flk_test',
            'environment' => 'testing',
            'async' => false,
            'apm_enabled' => true,
            'apm_sample_rate' => 1.0,
            'capture_exceptions' => true,
            'capture_jobs' => true,
        ]);
    }

    protected function defineRoutes($router): void
    {
        Route::get('/apm-query', static function () {
            DB::select('select 42 as answer');
            return response('ok');
        });
    }

    public function testProviderInstallsRequestAndDatabaseInstrumentation(): void
    {
        $recording = new RecordingClient($this->app->make(Configuration::class));
        $this->app->instance(Client::class, $recording);

        $this->get('/apm-query')->assertOk();

        $this->assertCount(1, $recording->transactions);
        $transaction = $recording->transactions[0];
        $this->assertSame('/apm-query', $transaction['path']);
        $this->assertCount(1, $transaction['spans']);
        $this->assertSame('select ? as answer', $transaction['spans'][0]['sql']);
    }
}
