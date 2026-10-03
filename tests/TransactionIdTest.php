<?php

declare(strict_types=1);

namespace Errorgap\Laravel\Tests;

use Errorgap\Configuration;
use Errorgap\Laravel\ApmMiddleware;
use Errorgap\Laravel\JobApmTracker;
use Errorgap\Laravel\QuerySpanCollector;
use Errorgap\TransactionContext;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Errors reported during a request or a job carry its transaction id, so
 * errorgap shows the error it actually raised and links the two.
 */
final class TransactionIdTest extends TestCase
{
    protected function setUp(): void
    {
        // A failed job in another test leaves its id current until the next
        // job starts, as it does in a real worker; start from none.
        while (TransactionContext::current() !== null) {
            TransactionContext::end();
        }
    }

    private function configuration(): Configuration
    {
        return new Configuration(['projectSlug' => 'demo', 'apmEnabled' => true, 'apmSampleRate' => 1.0]);
    }

    public function testAnErrorReportedDuringARequestCarriesItsId(): void
    {
        $client = new RecordingClient($this->configuration());
        $middleware = new ApmMiddleware($client, $this->configuration(), new QuerySpanCollector(dirname(__DIR__)));

        $middleware->handle(Request::create('/orders/7', 'GET'), static function () use ($client): Response {
            // What Laravel's exception handler does inside the pipeline.
            $client->notify(new \RuntimeException('boom'));
            return new Response('error', 500);
        });

        $id = $client->transactions[0]['id'];
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $id);
        $this->assertSame($id, $client->notifications[0]['transaction_id']);
        $this->assertNull(TransactionContext::current());
    }

    public function testAFailedJobsReportCarriesItsIdUntilTheNextJob(): void
    {
        $client = new RecordingClient($this->configuration());
        $tracker = new JobApmTracker($client, new QuerySpanCollector(dirname(__DIR__)));
        $job = $this->createStub(Job::class);
        $job->method('resolveName')->willReturn('App\\Jobs\\ChargeCard');
        $job->method('getQueue')->willReturn('payments');

        $tracker->start($job);
        $tracker->finish('redis', $job, true);
        // Laravel reports the exception after "job exception occurred".
        $client->notify(new \RuntimeException('card declined'));

        $id = $client->transactions[0]['id'];
        $this->assertSame($id, $client->notifications[0]['transaction_id']);

        $next = $this->createStub(Job::class);
        $next->method('resolveName')->willReturn('App\\Jobs\\SendReceipt');
        $next->method('getQueue')->willReturn('mail');
        $tracker->start($next);
        $this->assertNotSame($id, TransactionContext::current());
        $tracker->finish('redis', $next, false);
        $this->assertNull(TransactionContext::current(), 'a successful job ends its transaction');
        $this->assertNotSame($id, $client->transactions[1]['id']);
    }
}
