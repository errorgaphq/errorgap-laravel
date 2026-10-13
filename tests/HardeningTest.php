<?php

declare(strict_types=1);

namespace Errorgap\Laravel\Tests;

use Errorgap\Configuration;
use Errorgap\Laravel\ErrorgapServiceProvider;
use Errorgap\Laravel\ExceptionReporter;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Orchestra\Testbench\TestCase;

final class DropEverything
{
    /** @param array<string, mixed> $notice */
    public function __invoke(array $notice): bool
    {
        return false;
    }
}

final class HardeningTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ErrorgapServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('errorgap.project_slug', 'demo');
        $app['config']->set('errorgap.release', 'v9');
        $app['config']->set('errorgap.ignore_environments', ['testing']);
        $app['config']->set('errorgap.before_notify', DropEverything::class);
    }

    public function testConfigReachesTheCoreConfiguration(): void
    {
        $config = $this->app->make(Configuration::class);

        $this->assertSame('v9', $config->release);
        $this->assertSame(['testing'], $config->ignoreEnvironments);
        $this->assertIsCallable($config->beforeNotify);
        $this->assertFalse(($config->beforeNotify)(['errors' => []]));
    }

    public function testTheSignedInUserIsNamedInTheSessionWhenIncluded(): void
    {
        $this->app['config']->set('errorgap.include_user', true);
        $user = new User();
        $user->forceFill(['id' => 42, 'email' => 'ada@example.com']);
        $request = Request::create('/orders', 'GET');
        $this->app->instance('request', $request);
        // After binding: Laravel's auth provider resets the resolver when the
        // request is rebound.
        $request->setUserResolver(static fn () => $user);

        $recording = new RecordingClient(new Configuration(['projectSlug' => 'demo']));
        (new ExceptionReporter($this->app, $recording))->report(new \RuntimeException('x'));

        $this->assertSame(
            ['user_id' => '42', 'user_email' => 'ada@example.com'],
            $recording->notifications[0]['session'],
        );
    }

    public function testNoUserIsSentByDefault(): void
    {
        $request = Request::create('/orders', 'GET');
        $this->app->instance('request', $request);
        $request->setUserResolver(static fn () => (new User())->forceFill(['id' => 1]));

        $recording = new RecordingClient(new Configuration(['projectSlug' => 'demo']));
        (new ExceptionReporter($this->app, $recording))->report(new \RuntimeException('x'));

        $this->assertSame([], $recording->notifications[0]['session']);
    }
}
