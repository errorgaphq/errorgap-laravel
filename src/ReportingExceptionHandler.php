<?php

declare(strict_types=1);

namespace Errorgap\Laravel;

use Errorgap\Client;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Throwable;

/**
 * Wraps the application's exception handler so any reported throwable is also
 * sent to Errorgap. Used only when the host's handler does not expose
 * `reportable()` (Laravel <8).
 */
final class ReportingExceptionHandler implements ExceptionHandler
{
    public function __construct(
        private ExceptionHandler $inner,
        private Client $client,
    ) {
    }

    public function report(Throwable $e): void
    {
        $this->client->notify($e, sync: true);
        $this->inner->report($e);
    }

    public function shouldReport(Throwable $e): bool
    {
        return $this->inner->shouldReport($e);
    }

    public function render($request, Throwable $e)
    {
        return $this->inner->render($request, $e);
    }

    public function renderForConsole($output, Throwable $e): void
    {
        $this->inner->renderForConsole($output, $e);
    }
}
