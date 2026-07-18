<?php

declare(strict_types=1);

namespace Errorgap\Laravel;

use Closure;
use Errorgap\Client;
use Errorgap\Configuration;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class ApmMiddleware
{
    public function __construct(
        private readonly Client $client,
        private readonly Configuration $configuration,
        private readonly QuerySpanCollector $spans,
    ) {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        if (!$this->configuration->apmEnabled) {
            return $next($request);
        }

        $startedAt = hrtime(true);
        $statusCode = 500;
        $this->spans->start();

        try {
            $response = $next($request);
            if ($response instanceof Response) {
                $statusCode = $response->getStatusCode();
            } elseif (is_object($response) && method_exists($response, 'getStatusCode')) {
                $statusCode = (int)$response->getStatusCode();
            }
            return $response;
        } catch (Throwable $exception) {
            throw $exception;
        } finally {
            $durationMs = (hrtime(true) - $startedAt) / 1_000_000;
            $this->client->notifyTransaction([
                'kind' => 'web',
                'method' => $request->getMethod(),
                'path' => $this->routePattern($request),
                'path_raw' => $request->getRequestUri(),
                'status_code' => $statusCode,
                'duration_ms' => round($durationMs, 3),
                'spans' => $this->spans->flush(),
            ]);
        }
    }

    private function routePattern(Request $request): string
    {
        $route = $request->route();
        if ($route instanceof Route) {
            return '/' . ltrim($route->uri(), '/');
        }

        return $request->getPathInfo();
    }
}
