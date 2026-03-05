<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

final class ClusterShardsParser
{
    /**
     * @param mixed $reply
     * @return list<ClusterShardStatus>
     */
    public function parse(mixed $reply): array
    {
        if (!is_array($reply)) {
            return [];
        }

        $shards = [];
        foreach ($reply as $shardData) {
            if (!is_array($shardData)) {
                continue;
            }

            $shardMap = $this->zipKeyValuePairs($shardData);
            $slotsData = $shardMap['slots'] ?? null;
            $nodesData = $shardMap['nodes'] ?? null;

            if (!is_array($slotsData) || count($slotsData) < 2 || !is_array($nodesData)) {
                continue;
            }

            $master = null;
            $replicas = [];

            foreach ($nodesData as $nodeData) {
                if (!is_array($nodeData)) {
                    continue;
                }

                $node = $this->parseNode($nodeData);
                if ($node === null) {
                    continue;
                }

                if ($node->role === 'master') {
                    $master = $node;
                    continue;
                }

                if ($node->role === 'replica') {
                    $replicas[] = $node;
                }
            }

            if ($master === null) {
                continue;
            }

            $shards[] = new ClusterShardStatus(
                slotStart: (int) $slotsData[0],
                slotEnd: (int) $slotsData[1],
                master: $master,
                replicas: $replicas,
            );
        }

        usort(
            $shards,
            static fn (ClusterShardStatus $left, ClusterShardStatus $right): int => $left->slotStart <=> $right->slotStart,
        );

        return $shards;
    }

    /**
     * @param array<mixed> $data
     * @return array<string, mixed>
     */
    private function zipKeyValuePairs(array $data): array
    {
        $result = [];

        for ($index = 0; $index + 1 < count($data); $index += 2) {
            $key = $data[$index];
            if (!is_string($key) || $key === '') {
                continue;
            }

            $result[$key] = $data[$index + 1];
        }

        return $result;
    }

    /**
     * @param array<mixed> $nodeData
     */
    private function parseNode(array $nodeData): ?ClusterNodeStatus
    {
        $nodeMap = $this->zipKeyValuePairs($nodeData);

        $id = (string) ($nodeMap['id'] ?? '');
        if ($id === '') {
            return null;
        }

        return new ClusterNodeStatus(
            id: $id,
            ip: (string) ($nodeMap['ip'] ?? ''),
            port: (int) ($nodeMap['port'] ?? 0),
            endpoint: (string) ($nodeMap['endpoint'] ?? ''),
            role: (string) ($nodeMap['role'] ?? ''),
            replicationOffset: (int) ($nodeMap['replication-offset'] ?? 0),
            health: (string) ($nodeMap['health'] ?? 'unknown'),
        );
    }
}
