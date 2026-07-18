<?php

declare(strict_types=1);

namespace Errorgap\Laravel\Tests;

use Errorgap\Configuration;
use Errorgap\Laravel\ExceptionReporter;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Orchestra\Testbench\TestCase;

final class ExceptionReporterTest extends TestCase
{
    public function testCapturesLaravelRequestContextAndParams(): void
    {
        $this->app['config']->set('errorgap.include_user', false);
        $request = Request::create(
            '/orders/42?attempt=2',
            'POST',
            ['order' => 42, 'password' => 'super-secret'],
            server: ['HTTP_USER_AGENT' => 'Laravel SDK test'],
        );
        $route = (new Route(['POST'], 'orders/{order}', static fn () => null))->name('orders.show');
        $request->setRouteResolver(static fn () => $route);
        $this->app->instance('request', $request);

        $recording = new RecordingClient(new Configuration(['projectSlug' => 'demo']));
        $reporter = new ExceptionReporter($this->app, $recording);
        $exception = new \RuntimeException('request failed');
        $reporter->report($exception);

        $this->assertCount(1, $recording->notifications);
        $notice = $recording->notifications[0];
        $this->assertSame($exception, $notice['exception']);
        $this->assertSame('errorgap-laravel', $notice['context']['notifier']);
        $this->assertSame('laravel.exception', $notice['context']['source']);
        $this->assertSame('orders.show', $notice['context']['component']);
        $this->assertSame('POST', $notice['context']['action']);
        $this->assertSame('/orders/42', $notice['environment']['path']);
        $this->assertSame('/orders/{order}', $notice['environment']['route']);
        $this->assertSame('super-secret', $notice['params']['password']);
        $this->assertTrue($notice['sync']);
    }
}
