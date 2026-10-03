<?php

declare(strict_types=1);

namespace Errorgap\Laravel;

use Errorgap\Client;
use Errorgap\TransactionContext;
use Illuminate\Contracts\Queue\Job;

final class JobApmTracker
{
    /** @var array<int, int> */
    private array $startedAt = [];

    /** @var array<int, string> transaction ids by job */
    private array $ids = [];

    /**
     * A failed job's transaction stays current after it is recorded: Laravel
     * reports the exception after the "exception occurred" event, and the
     * report should carry the job's id. It ends when the next job starts.
     */
    private bool $failedStillCurrent = false;

    public function __construct(
        private readonly Client $client,
        private readonly QuerySpanCollector $spans,
    ) {
    }

    public function start(Job $job): void
    {
        $this->endFailed();
        $key = spl_object_id($job);
        $this->startedAt[$key] = hrtime(true);
        $this->ids[$key] = TransactionContext::begin();
        $this->spans->start();
    }

    public function finish(string $connectionName, Job $job, bool $failed): void
    {
        $key = spl_object_id($job);
        $startedAt = $this->startedAt[$key] ?? null;
        $transactionId = $this->ids[$key] ?? null;
        unset($this->startedAt[$key], $this->ids[$key]);
        if ($startedAt === null) {
            return;
        }
        if ($failed) {
            $this->failedStillCurrent = true;
        } else {
            TransactionContext::end();
        }

        $this->client->notifyTransaction([
            'id' => $transactionId,
            'kind' => 'job',
            'job_class' => $job->resolveName(),
            'queue' => $job->getQueue(),
            'status_code' => $failed ? 500 : 200,
            'duration_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 3),
            'spans' => $this->spans->flush(),
            'connection' => $connectionName,
        ], sync: true);
    }

    private function endFailed(): void
    {
        if ($this->failedStillCurrent) {
            $this->failedStillCurrent = false;
            TransactionContext::end();
        }
    }
}
