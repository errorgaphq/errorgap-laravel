<?php

declare(strict_types=1);

namespace Errorgap\Laravel\Tests;

use Errorgap\Client;
use Errorgap\DeliveryResult;

final class RecordingClient extends Client
{
    /** @var list<array<string, mixed>> */
    public array $notifications = [];
    /** @var list<array<string, mixed>> */
    public array $transactions = [];

    public function notify(
        \Throwable $exception,
        array $context = [],
        array $environment = [],
        array $session = [],
        array $params = [],
        bool $sync = false,
    ): DeliveryResult {
        $this->notifications[] = compact(
            'exception',
            'context',
            'environment',
            'session',
            'params',
            'sync',
        );
        return new DeliveryResult(status: 201);
    }

    /** @param array<string, mixed> $transaction */
    public function notifyTransaction(array $transaction, bool $sync = false): DeliveryResult
    {
        $this->transactions[] = $transaction + ['sync' => $sync];
        return new DeliveryResult(status: 201);
    }
}
