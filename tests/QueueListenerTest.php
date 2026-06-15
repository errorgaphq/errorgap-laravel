<?php

declare(strict_types=1);

namespace Errorgap\Laravel\Tests;

use Errorgap\Client;
use Errorgap\Configuration;
use Errorgap\Laravel\ErrorgapServiceProvider;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Orchestra\Testbench\TestCase;

final class RecordingClient extends Client
{
    /** @var list<array{exception: \Throwable, context: array<string, mixed>}> */
    public array $notifications = [];

    public function notify(
        \Throwable $exception,
        array $context = [],
        array $environment = [],
        array $session = [],
        array $params = [],
        bool $sync = false,
    ): \Errorgap\DeliveryResult {
        $this->notifications[] = ['exception' => $exception, 'context' => $context];
        return new \Errorgap\DeliveryResult(status: 201);
    }
}

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

        // Re-register the queue listener with the swapped client.
        Event::listen(JobFailed::class, function (JobFailed $event) use ($recording): void {
            $recording->notify(
                $event->exception,
                context: [
                    'source' => 'queue.job_failed',
                    'job' => $event->job->resolveName(),
                    'queue' => $event->job->getQueue(),
                    'connection' => $event->connectionName,
                ],
                sync: true,
            );
        });

        $job = $this->createMock(Job::class);
        $job->method('resolveName')->willReturn('App\\Jobs\\Boom');
        $job->method('getQueue')->willReturn('default');

        $exc = new \RuntimeException('job-boom');
        Event::dispatch(new JobFailed('redis', $job, $exc));

        $this->assertCount(1, $recording->notifications);
        $this->assertSame($exc, $recording->notifications[0]['exception']);
        $this->assertSame('queue.job_failed', $recording->notifications[0]['context']['source']);
        $this->assertSame('App\\Jobs\\Boom', $recording->notifications[0]['context']['job']);
    }
}
