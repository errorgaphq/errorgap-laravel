<?php

declare(strict_types=1);

namespace Errorgap\Laravel\Tests;

use Errorgap\Configuration;
use Errorgap\Laravel\ApmMiddleware;
use Errorgap\Laravel\QuerySpanCollector;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class ApmMiddlewareTest extends TestCase
{
    public function testCapturesNormalizedRouteDurationStatusAndSpans(): void
    {
        $configuration = new Configuration([
            'projectSlug' => 'demo',
            'apmEnabled' => true,
            'apmSampleRate' => 1.0,
        ]);
        $client = new RecordingClient($configuration);
        $collector = new QuerySpanCollector(dirname(__DIR__));
        $middleware = new ApmMiddleware($client, $configuration, $collector);
        $request = Request::create('/orders/42?attempt=2', 'POST');
        $route = new Route(['POST'], 'orders/{order}', static fn () => null);
        $request->setRouteResolver(static fn () => $route);

        $response = $middleware->handle($request, static function () use ($collector): Response {
            $collector->record("select * from orders where id = 42", 4.75);
            return new Response('created', 201);
        });

        $this->assertSame(201, $response->getStatusCode());
        $this->assertCount(1, $client->transactions);
        $transaction = $client->transactions[0];
        $this->assertSame('web', $transaction['kind']);
        $this->assertSame('POST', $transaction['method']);
        $this->assertSame('/orders/{order}', $transaction['path']);
        $this->assertSame('/orders/42?attempt=2', $transaction['path_raw']);
        $this->assertSame(201, $transaction['status_code']);
        $this->assertGreaterThanOrEqual(0.0, $transaction['duration_ms']);
        $this->assertSame('select * from orders where id = ?', $transaction['spans'][0]['sql']);
    }

    public function testRecordsServerErrorWhenRequestThrows(): void
    {
        $configuration = new Configuration(['projectSlug' => 'demo', 'apmEnabled' => true]);
        $client = new RecordingClient($configuration);
        $middleware = new ApmMiddleware(
            $client,
            $configuration,
            new QuerySpanCollector(dirname(__DIR__)),
        );
        $request = Request::create('/explode', 'GET');

        try {
            $middleware->handle($request, static fn () => throw new \RuntimeException('boom'));
            $this->fail('Expected exception');
        } catch (\RuntimeException $exception) {
            $this->assertSame('boom', $exception->getMessage());
        }

        $this->assertSame(500, $client->transactions[0]['status_code']);
    }
}
