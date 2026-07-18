<?php

declare(strict_types=1);

namespace Errorgap\Laravel\Tests;

use Errorgap\Laravel\QuerySpanCollector;
use PHPUnit\Framework\TestCase;

final class QuerySpanCollectorTest extends TestCase
{
    public function testNormalizesSqlLiterals(): void
    {
        $sql = "select * from orders where id = 42 and email = 'dev@example.com'";
        $this->assertSame(
            'select * from orders where id = ? and email = ?',
            QuerySpanCollector::normalizeSql($sql),
        );
    }

    public function testOnlyRecordsDuringAnActiveTransaction(): void
    {
        $collector = new QuerySpanCollector(dirname(__DIR__));
        $collector->record('select 1', 2.5);
        $this->assertSame([], $collector->flush());

        $collector->start();
        $collector->record('select 42', 3.1254);
        $spans = $collector->flush();

        $this->assertCount(1, $spans);
        $this->assertSame('db', $spans[0]['kind']);
        $this->assertSame('select ?', $spans[0]['sql']);
        $this->assertSame(3.125, $spans[0]['duration_ms']);
    }
}
