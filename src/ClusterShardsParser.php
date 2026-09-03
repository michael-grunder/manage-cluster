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

            if (!is_array($slotsData) || !is_array($nodesData)) {
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
                slots: $this->parseSlotRanges($slotsData),
                master: $master,
                replicas: $replicas,
            );
        }

        // Shards without slots sort last so an emptied primary stays visible
        // instead of jumping to the front of the topology.
        usort(
            $shards,
            static fn (ClusterShardStatus $left, ClusterShardStatus $right): int
                => ($left->firstSlot() ?? SlotRange::TOTAL_SLOTS) <=> ($right->firstSlot() ?? SlotRange::TOTAL_SLOTS),
        );

        return $shards;
    }

    /**
     * CLUSTER SHARDS reports slots as a flat list of start/end pairs, so a
     * primary that has taken slots from elsewhere reports several pairs.
     *
     * @param array<mixed> $slotsData
     * @return list<SlotRange>
     */
    private function parseSlotRanges(array $slotsData): array
    {
        $values = array_values($slotsData);
        $slots = [];

        for ($index = 0; $index + 1 < count($values); $index += 2) {
            $start = $this->readInt($values[$index]);
            $end = $this->readInt($values[$index + 1]);
            if ($start === null || $end === null || $end < $start) {
                continue;
            }

            if ($start < SlotRange::FIRST_SLOT || $end > SlotRange::LAST_SLOT) {
                continue;
            }

            foreach (range($start, $end) as $slot) {
                $slots[] = $slot;
            }
        }

        return SlotRange::compact($slots);
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

        $id = $this->readString($nodeMap['id'] ?? null);
        if ($id === null || $id === '') {
            return null;
        }

        return new ClusterNodeStatus(
            id: $id,
            ip: $this->readString($nodeMap['ip'] ?? null) ?? '',
            port: $this->readInt($nodeMap['port'] ?? null) ?? 0,
            endpoint: $this->readString($nodeMap['endpoint'] ?? null) ?? '',
            role: $this->readString($nodeMap['role'] ?? null) ?? '',
            replicationOffset: $this->readInt($nodeMap['replication-offset'] ?? null) ?? 0,
            health: $this->readString($nodeMap['health'] ?? null) ?? 'unknown',
        );
    }

    private function readInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    private function readString(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return null;
    }
}
