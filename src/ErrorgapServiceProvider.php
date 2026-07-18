<?php

declare(strict_types=1);

namespace Errorgap\Laravel;

use Errorgap\Client;
use Errorgap\Configuration;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
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
                'apmEnabled' => $cfg['apm_enabled'] ?? false,
                'apmSampleRate' => $cfg['apm_sample_rate'] ?? 1.0,
                'logger' => $app->make('log'),
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

        $this->app->singleton(QuerySpanCollector::class, function (): QuerySpanCollector {
            return new QuerySpanCollector(base_path());
        });

        $this->app->singleton(ExceptionReporter::class, function (Container $app): ExceptionReporter {
            return new ExceptionReporter($app, $app->make(Client::class));
        });

        $this->app->singleton(JobApmTracker::class, function (Container $app): JobApmTracker {
            return new JobApmTracker(
                $app->make(Client::class),
                $app->make(QuerySpanCollector::class),
            );
        });
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

        if (($cfg['apm_enabled'] ?? false) === true) {
            $this->bindApm();
        }
    }

    private function bindExceptionReporter(): void
    {
        // Hooking through the exception-handler Reporter callback. Laravel 8+
        // exposes `reportable()` on `App\Exceptions\Handler`; we instead bind
        // a callback to the global handler via the container so apps that
        // haven't overridden Handler still get coverage.
        $reporter = $this->app->make(ExceptionReporter::class);

        $handler = $this->app->bound(\Illuminate\Contracts\Debug\ExceptionHandler::class)
            ? $this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class)
            : null;

        if ($handler !== null && method_exists($handler, 'reportable')) {
            // Laravel 8+ Handler exposes reportable() — preferred path.
            $handler->reportable(function (Throwable $exc) use ($reporter): void {
                $reporter->report($exc);
            });
            return;
        }

        // Fallback: wrap the existing handler so report() also notifies us.
        $this->app->extend(
            \Illuminate\Contracts\Debug\ExceptionHandler::class,
            function (\Illuminate\Contracts\Debug\ExceptionHandler $existing) use ($reporter): object {
                return new ReportingExceptionHandler($existing, $reporter);
            },
        );
    }

    private function bindJobFailureListener(): void
    {
        Event::listen(JobFailed::class, function (JobFailed $event): void {
            $this->app->make(ExceptionReporter::class)->report(
                $event->exception,
                extraContext: [
                    'source' => 'queue.job_failed',
                    'job' => $event->job->resolveName(),
                    'queue' => $event->job->getQueue(),
                    'connection' => $event->connectionName,
                ],
                includeRequest: false,
            );
        });
    }

    private function bindApm(): void
    {
        $kernel = $this->app->make(HttpKernel::class);
        if (method_exists($kernel, 'prependMiddleware')) {
            $kernel->prependMiddleware(ApmMiddleware::class);
        }

        $collector = $this->app->make(QuerySpanCollector::class);
        /** @var \Illuminate\Database\DatabaseManager $database */
        $database = $this->app->make('db');
        $database->connection()->listen(static function (QueryExecuted $query) use ($collector): void {
            $collector->record($query->sql, (float)$query->time);
        });


        Event::listen(JobProcessing::class, function (JobProcessing $event): void {
            $this->app->make(JobApmTracker::class)->start($event->job);
        });
        Event::listen(JobProcessed::class, function (JobProcessed $event): void {
            $this->app->make(JobApmTracker::class)->finish($event->connectionName, $event->job, false);
        });
        Event::listen(JobExceptionOccurred::class, function (JobExceptionOccurred $event): void {
            $this->app->make(JobApmTracker::class)->finish($event->connectionName, $event->job, true);
        });
    }
}
