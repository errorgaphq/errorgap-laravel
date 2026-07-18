<?php

declare(strict_types=1);

namespace Errorgap\Laravel;

use Errorgap\Client;
use Illuminate\Contracts\Queue\Job;

final class JobApmTracker
{
    /** @var array<int, int> */
    private array $startedAt = [];

    public function __construct(
        private readonly Client $client,
        private readonly QuerySpanCollector $spans,
    ) {
    }

    public function start(Job $job): void
    {
        $this->startedAt[spl_object_id($job)] = hrtime(true);
        $this->spans->start();
    }

    public function finish(string $connectionName, Job $job, bool $failed): void
    {
        $key = spl_object_id($job);
        $startedAt = $this->startedAt[$key] ?? null;
        unset($this->startedAt[$key]);
        if ($startedAt === null) {
            return;
        }

        $this->client->notifyTransaction([
            'kind' => 'job',
            'job_class' => $job->resolveName(),
            'queue' => $job->getQueue(),
            'status_code' => $failed ? 500 : 200,
            'duration_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 3),
            'spans' => $this->spans->flush(),
            'connection' => $connectionName,
        ], sync: true);
    }
}
