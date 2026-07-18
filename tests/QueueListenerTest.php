<?php

declare(strict_types=1);

namespace Errorgap\Laravel\Tests;

use Errorgap\Client;
use Errorgap\Configuration;
use Errorgap\Laravel\ErrorgapServiceProvider;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Orchestra\Testbench\TestCase;
use Errorgap\Laravel\ExceptionReporter;
use Illuminate\Support\Facades\Event;

final class QueueListenerTest extends TestCase
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
            'api_key' => 'flk_test',
            'environment' => 'testing',
            'async' => false,
            'capture_exceptions' => true,
            'capture_jobs' => true,
        ]);
    }

    public function testJobFailedEventTriggersNotify(): void
    {
        $recording = new RecordingClient($this->app->make(Configuration::class));
        $this->app->instance(Client::class, $recording);
        $this->app->forgetInstance(ExceptionReporter::class);

        $job = $this->createStub(Job::class);
        $job->method('resolveName')->willReturn('App\\Jobs\\Boom');
        $job->method('getQueue')->willReturn('default');

        $exc = new \RuntimeException('job-boom');
        Event::dispatch(new JobFailed('redis', $job, $exc));

        $this->assertCount(1, $recording->notifications);
        $this->assertSame($exc, $recording->notifications[0]['exception']);
        $this->assertSame('queue.job_failed', $recording->notifications[0]['context']['source']);
        $this->assertSame('App\\Jobs\\Boom', $recording->notifications[0]['context']['job']);
        $this->assertSame('errorgap-laravel', $recording->notifications[0]['context']['notifier']);
        $this->assertTrue($recording->notifications[0]['sync']);
    }
}
