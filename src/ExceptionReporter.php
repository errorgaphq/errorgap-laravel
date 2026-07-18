<?php

declare(strict_types=1);

namespace Errorgap\Laravel;

use Errorgap\Client;
use Errorgap\DeliveryResult;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Throwable;

final class ExceptionReporter
{
    public function __construct(
        private readonly Container $app,
        private readonly Client $client,
    ) {
    }

    /** @param array<string, mixed> $extraContext */
    public function report(
        Throwable $exception,
        array $extraContext = [],
        bool $includeRequest = true,
    ): DeliveryResult {
        $context = [
            'notifier' => 'errorgap-laravel',
            'notifier_version' => Version::VERSION,
            'source' => 'laravel.exception',
        ];
        $environment = [];
        $params = [];

        if ($includeRequest && $this->app->bound('request')) {
            $request = $this->app->make('request');
            if ($request instanceof Request) {
                [$requestContext, $environment, $params] = $this->requestData($request);
                $context = array_merge($context, $requestContext);
            }
        }

        return $this->client->notify(
            $exception,
            context: array_merge($context, $extraContext),
            environment: $environment,
            params: $params,
            sync: true,
        );
    }

    /**
     * @return array{array<string, mixed>, array<string, mixed>, array<string, mixed>}
     */
    private function requestData(Request $request): array
    {
        $route = $request->route();
        $routeName = $route instanceof Route ? $route->getName() : null;
        $routePattern = $route instanceof Route ? '/' . ltrim($route->uri(), '/') : null;

        $context = array_filter([
            'url' => $request->url(),
            'component' => $routeName ?? $routePattern,
            'action' => $request->getMethod(),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        $includeUser = (bool)$this->app->make('config')->get('errorgap.include_user', false);
        if ($includeUser) {
            $user = $request->user();
            if ($user instanceof UrlRoutable) {
                $context['user_id'] = $user->getRouteKey();
            } elseif (is_object($user) && method_exists($user, 'getAuthIdentifier')) {
                $context['user_id'] = $user->getAuthIdentifier();
            }
        }

        $environment = array_filter([
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
            'route' => $routePattern,
            'user_agent' => $request->userAgent(),
            'remote_addr' => $request->ip(),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        $input = $request->all();
        $params = is_array($input) ? $input : [];

        return [$context, $environment, $params];
    }
}
