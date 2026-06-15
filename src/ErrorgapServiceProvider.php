<?php

declare(strict_types=1);

namespace Errorgap\Laravel;

use Errorgap\Client;
use Errorgap\Configuration;
use Illuminate\Contracts\Container\Container;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Throwable;

final class ErrorgapServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/errorgap.php', 'errorgap');

        $this->app->singleton(Configuration::class, function (Container $app): Configuration {
            /** @var array<string, mixed> $cfg */
            $cfg = $app['config']->get('errorgap', []);
            $options = [
                'endpoint' => $cfg['endpoint'] ?? null,
                'projectSlug' => $cfg['project_slug'] ?? null,
                'projectId' => $cfg['project_id'] ?? null,
                'apiKey' => $cfg['api_key'] ?? null,
                'environment' => $cfg['environment'] ?? null,
                'rootDirectory' => base_path(),
                'async' => $cfg['async'] ?? true,
                'timeoutSeconds' => $cfg['timeout_seconds'] ?? 5,
            ];
            if (is_array($cfg['filter_keys'] ?? null)) {
                $options['filterKeys'] = array_values($cfg['filter_keys']);
            }

            return new Configuration(array_filter($options, static fn ($v) => $v !== null));
        });

        $this->app->singleton(Client::class, function (Container $app): Client {
            return new Client($app->make(Configuration::class));
        });

        $this->app->alias(Client::class, 'errorgap');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/errorgap.php' => config_path('errorgap.php'),
        ], 'errorgap-config');

        /** @var array<string, mixed> $cfg */
        $cfg = $this->app['config']->get('errorgap', []);

        if (($cfg['capture_exceptions'] ?? true) === true) {
            $this->bindExceptionReporter();
        }

        if (($cfg['capture_jobs'] ?? true) === true) {
            $this->bindJobFailureListener();
        }
    }

    private function bindExceptionReporter(): void
    {
        // Hooking through the exception-handler Reporter callback. Laravel 8+
        // exposes `reportable()` on `App\Exceptions\Handler`; we instead bind
        // a callback to the global handler via the container so apps that
        // haven't overridden Handler still get coverage.
        $client = $this->app->make(Client::class);

        $handler = $this->app->bound(\Illuminate\Contracts\Debug\ExceptionHandler::class)
            ? $this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class)
            : null;

        if ($handler !== null && method_exists($handler, 'reportable')) {
            // Laravel 8+ Handler exposes reportable() — preferred path.
            $handler->reportable(function (Throwable $exc) use ($client): void {
                $client->notify($exc, sync: true);
            });
            return;
        }

        // Fallback: wrap the existing handler so report() also notifies us.
        $this->app->extend(
            \Illuminate\Contracts\Debug\ExceptionHandler::class,
            function (\Illuminate\Contracts\Debug\ExceptionHandler $existing) use ($client): object {
                return new ReportingExceptionHandler($existing, $client);
            },
        );
    }

    private function bindJobFailureListener(): void
    {
        $client = $this->app->make(Client::class);
        Event::listen(JobFailed::class, function (JobFailed $event) use ($client): void {
            $client->notify(
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
    }
}
