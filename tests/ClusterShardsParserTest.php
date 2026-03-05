<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster\Tests;

use Mgrunder\CreateCluster\ClusterShardsParser;
use PHPUnit\Framework\TestCase;

final class ClusterShardsParserTest extends TestCase
{
    public function testParsesResp2StyleShardReplyIntoMastersAndReplicas(): void
    {
        $parser = new ClusterShardsParser();

        $reply = [
            [
                'slots', [5461, 10922],
                'nodes', [
                    [
                        'id', 'def959b870585e8f8d67dc4647fee9317a35aca2',
                        'port', 7001,
                        'ip', '127.0.0.1',
                        'endpoint', '127.0.0.1',
                        'role', 'master',
                        'replication-offset', 448,
                        'health', 'online',
                    ],
                    [
                        'id', '0341d2d83fbcd63ba7c87ba7dcc40c0863a38b84',
                        'port', 7003,
                        'ip', '127.0.0.1',
                        'endpoint', '127.0.0.1',
                        'role', 'replica',
                        'replication-offset', 448,
                        'health', 'online',
                    ],
                ],
            ],
            [
                'slots', [0, 5460],
                'nodes', [
                    [
                        'id', 'af8898a87e8d8200a32e6c2fa8b28951859f65d5',
                        'port', 7000,
                        'ip', '127.0.0.1',
                        'endpoint', '127.0.0.1',
                        'role', 'master',
                        'replication-offset', 449,
                        'health', 'online',
                    ],
                    [
                        'id', 'fac7f037bffb58ba7261dfb91a86d6dab4f6c17c',
                        'port', 7005,
                        'ip', '127.0.0.1',
                        'endpoint', '127.0.0.1',
                        'role', 'replica',
                        'replication-offset', 449,
                        'health', 'online',
                    ],
                ],
            ],
        ];

        $shards = $parser->parse($reply);

        self::assertCount(2, $shards);
        self::assertSame('0-5460', $shards[0]->slotRange());
        self::assertSame('127.0.0.1:7000', $shards[0]->master->address());
        self::assertCount(1, $shards[0]->replicas);
        self::assertSame('fac7f037', $shards[0]->replicas[0]->shortId());

        self::assertSame('5461-10922', $shards[1]->slotRange());
        self::assertSame(7001, $shards[1]->master->port);
        self::assertSame(448, $shards[1]->master->replicationOffset);
    }

    public function testSkipsInvalidShardEntriesAndNodesWithoutIds(): void
    {
        $parser = new ClusterShardsParser();

        $reply = [
            'invalid',
            [
                'slots', [0, 100],
                'nodes', [
                    [
                        'port', 7000,
                        'role', 'master',
                    ],
                ],
            ],
            [
                'slots', [101, 200],
                'nodes', [
                    [
                        'id', 'aaabbbcccdddeee',
                        'port', 7001,
                        'endpoint', '127.0.0.1',
                        'role', 'master',
                        'replication-offset', 12,
                        'health', 'online',
                    ],
                ],
            ],
        ];

        $shards = $parser->parse($reply);

        self::assertCount(1, $shards);
        self::assertSame('101-200', $shards[0]->slotRange());
        self::assertSame('127.0.0.1:7001', $shards[0]->master->address());
    }
}
