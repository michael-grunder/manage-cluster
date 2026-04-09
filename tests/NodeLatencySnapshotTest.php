<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster\Tests;

use Mgrunder\CreateCluster\NodeLatencySnapshot;
use Mgrunder\CreateCluster\NodeLatencyState;
use PHPUnit\Framework\TestCase;

final class NodeLatencySnapshotTest extends TestCase
{
    public function testDisplayValueFormatsSuccessfulLatency(): void
    {
        self::assertSame('1.23 ms', new NodeLatencySnapshot(NodeLatencyState::Ok, 1.234)->displayValue());
        self::assertSame('12.3 ms', new NodeLatencySnapshot(NodeLatencyState::Ok, 12.34)->displayValue());
        self::assertSame('123 ms', new NodeLatencySnapshot(NodeLatencyState::Ok, 123.4)->displayValue());
    }

    public function testDisplayValueMapsNonSuccessfulStates(): void
    {
        self::assertSame('pending', new NodeLatencySnapshot(NodeLatencyState::Pending)->displayValue());
        self::assertSame('timeout', new NodeLatencySnapshot(NodeLatencyState::Timeout)->displayValue());
        self::assertSame('down', new NodeLatencySnapshot(NodeLatencyState::Error)->displayValue());
    }
}
