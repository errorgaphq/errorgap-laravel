<?php

declare(strict_types=1);

namespace Errorgap\Laravel\Tests;

use Errorgap\Configuration;
use Errorgap\Laravel\JobApmTracker;
use Errorgap\Laravel\QuerySpanCollector;
use Illuminate\Contracts\Queue\Job;
use PHPUnit\Framework\TestCase;

final class JobApmTrackerTest extends TestCase
{
    public function testCapturesSuccessfulAndFailedJobs(): void
    {
        $configuration = new Configuration(['projectSlug' => 'demo', 'apmEnabled' => true]);
        $client = new RecordingClient($configuration);
        $collector = new QuerySpanCollector(dirname(__DIR__));
        $tracker = new JobApmTracker($client, $collector);
        $job = $this->createStub(Job::class);
        $job->method('resolveName')->willReturn('App\\Jobs\\ChargeCard');
        $job->method('getQueue')->willReturn('payments');

        $tracker->start($job);
        $collector->record('select * from orders where id = 7', 2.25);
        $tracker->finish('redis', $job, true);

        $this->assertCount(1, $client->transactions);
        $transaction = $client->transactions[0];
        $this->assertSame('job', $transaction['kind']);
        $this->assertSame('App\\Jobs\\ChargeCard', $transaction['job_class']);
        $this->assertSame('payments', $transaction['queue']);
        $this->assertSame(500, $transaction['status_code']);
        $this->assertSame('redis', $transaction['connection']);
        $this->assertTrue($transaction['sync']);
        $this->assertSame('select * from orders where id = ?', $transaction['spans'][0]['sql']);
    }
}
