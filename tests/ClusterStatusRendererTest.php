<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster\Tests;

use Mgrunder\CreateCluster\ClusterNodeStatus;
use Mgrunder\CreateCluster\ClusterShardStatus;
use Mgrunder\CreateCluster\ClusterStatusRenderer;
use Mgrunder\CreateCluster\SlotRange;
use PHPUnit\Framework\TestCase;

final class ClusterStatusRendererTest extends TestCase
{
    public function testSlotColumnGrowsToFitFragmentedOwnership(): void
    {
        $shards = [
            new ClusterShardStatus(
                slots: [new SlotRange(0, 41), new SlotRange(53, 5460), new SlotRange(7678, 7684)],
                master: self::node(7000, 'master'),
                replicas: [self::node(7003, 'replica')],
            ),
            new ClusterShardStatus(
                slots: [new SlotRange(42, 52)],
                master: self::node(7001, 'master'),
                replicas: [],
            ),
        ];

        $lines = self::renderedNodeLines($shards);

        self::assertStringContainsString('[0-41,53-5460,7678-7684]', $lines[0]);
        self::assertSame(
            array_map(self::offsetColumn(...), $lines),
            array_fill(0, count($lines), self::offsetColumn($lines[0])),
            'every node row should place the offset column at the same width',
        );
    }

    public function testPrimariesWithoutSlotsStillRender(): void
    {
        $shards = [
            new ClusterShardStatus(
                slots: [new SlotRange(0, 16383)],
                master: self::node(7000, 'master'),
                replicas: [],
            ),
            new ClusterShardStatus(
                slots: [],
                master: self::node(7001, 'master'),
                replicas: [],
            ),
        ];

        $lines = self::renderedNodeLines($shards);

        self::assertCount(2, $lines);
        self::assertStringContainsString('[0-16383]', $lines[0]);
        self::assertStringContainsString('[-]', $lines[1]);
    }

    public function testVeryFragmentedOwnershipIsTruncatedToKeepColumnsUsable(): void
    {
        $ranges = [];
        for ($index = 0; $index < 40; $index++) {
            $ranges[] = new SlotRange($index * 4, $index * 4 + 1);
        }

        $shards = [
            new ClusterShardStatus(
                slots: $ranges,
                master: self::node(7000, 'master'),
                replicas: [self::node(7003, 'replica')],
            ),
        ];

        $lines = self::renderedNodeLines($shards);

        self::assertStringContainsString('...', $lines[0]);
        self::assertSame(self::offsetColumn($lines[0]), self::offsetColumn($lines[1]));
        self::assertLessThan(120, strlen($lines[0]));
    }

    /**
     * @param list<ClusterShardStatus> $shards
     * @return list<string>
     */
    private static function renderedNodeLines(array $shards): array
    {
        $rendered = (new ClusterStatusRenderer())->render($shards, 95, 7000, false);
        $lines = explode(PHP_EOL, trim($rendered));

        // Drop the header, timestamp, and separator rows.
        return array_slice($lines, 3);
    }

    private static function offsetColumn(string $line): int
    {
        $position = strpos($line, ' 4242 ');
        self::assertNotFalse($position, sprintf('No offset column found in: %s', $line));

        return $position;
    }

    private static function node(int $port, string $role): ClusterNodeStatus
    {
        return new ClusterNodeStatus(
            id: str_pad((string) $port, 40, '0'),
            ip: '127.0.0.1',
            port: $port,
            endpoint: '',
            role: $role,
            replicationOffset: 4242,
            health: 'online',
        );
    }
}
