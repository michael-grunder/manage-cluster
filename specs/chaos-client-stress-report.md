# Making `chaos` a stronger Redis Cluster client test

Reviewed 2026-09-05 against repository revision `c09eb57` and the working tree.
This is a source review and design report; no live cluster experiments were
performed. Proposed event names and options below are not implemented CLI
features unless explicitly identified as existing.

## Recommendation

**Add coordinated primary failover first, then primary crash/election/rejoin,
replica reassignment, and deliberately prolonged slot migrations.** These cover
different client failure paths: replacement of the writable endpoint, requests
interrupted during an outage, a live replica changing shards, and routing while
keys are divided between two nodes.

Increasing event frequency alone will have limited value. The runner also needs
to target slots the client actually uses, guarantee coverage of enabled events,
and measure client recovery during each transition. A thousand successful
topology mutations do not establish that a client handled them correctly.

## What the current implementation actually exercises

The v1 scope in [chaos.md](chaos.md) deliberately excludes primary failures,
failovers, partitions, and overlapping mutations. The implementation largely
follows that conservative scope.

| Area | Observed implementation | Consequence for client testing |
| --- | --- | --- |
| Replica removal | `replica-remove` is accepted by `ChaosOptions`, but has no candidate builder, executor, or postcondition. Selecting only it fails the implemented-category check. | Permanent membership removal is not currently exercised by `chaos`. The README acknowledges this. |
| Replica kill | `executeChaosEvent()` rewrites configuration and calls `RedisNodeClient::shutdown()`, whose default sends `SHUTDOWN NOSAVE`. | Tests an orderly server shutdown, not an abrupt process crash. The separate `kill --method` machinery is not used here. |
| Replica restart/add | Starts an existing configuration, or creates a node and sends `CLUSTER MEET` followed by `CLUSTER REPLICATE`. | Useful coverage of unavailable replicas, changing replica counts, and synchronization. |
| Slot movement | `SlotMigrator` performs `IMPORTING`, `MIGRATING`, key transfer, then `NODE`, one slot at a time; it explicitly updates other reachable primaries. | Exercises ownership changes, but empty or cold slots and short transfer windows may generate little client traffic through transitional routing. |
| Selection | Only candidates within one point of the highest score participate in random selection. Restarting an intentionally killed replica scores 7; ordinary slot migration scores 2. | Enabled categories have no coverage guarantee. Recovery and replica inventory balancing can dominate the run. |
| Timing | Events are serialized, convergence requires two matching topology polls, then the runner sleeps for `max(interval, cooldown)`. | Most mutations begin from settled topology. There is no deliberate hold at an intermediate migration stage. |
| Automatic replica movement | Generated configurations set `cluster-allow-replica-migration no`. | Redis's own redistribution of replicas is suppressed unless explicitly enabled at cluster creation. |

Relevant code: [ClusterManager.php](src/ClusterManager.php), especially
`chaos()`, `selectChaosCandidate()`, `executeChaosEvent()`, and
`waitForChaosEventConvergence()`;
[ChaosOptions.php](src/ChaosOptions.php);
[RedisNodeClient.php](src/RedisNodeClient.php);
[SlotMigrator.php](src/SlotMigrator.php);
[SlotMigrationPlanner.php](src/SlotMigrationPlanner.php);
[StartScriptGenerator.php](src/StartScriptGenerator.php).

## Proposed mutation coverage, in priority order

| Priority | Proposed scenario | Client behavior to expose | Recovery target |
| --- | --- | --- | --- |
| 1 | `primary-failover` | Shard-wide routing refresh, role changes on existing connections, write retries | Promoted replica owns the old primary's slots; old primary follows it |
| 1 | `primary-crash-recover` | Broken connections, election delays, transient unavailability, ambiguous writes | Election completes; former primary rejoins under the new owner |
| 1 | `replica-reparent` | Cached replica lists become wrong while the endpoint remains alive | Replica follows a different shard and becomes usable there |
| 1 | Staged hot-slot migration | `ASK`, connection-local `ASKING`, `TRYAGAIN`, then `MOVED` | One agreed owner, no open migration, expected data available |
| 2 | Replica removal and replacement at the same port | Stale node identity and connection-pool reuse | Old ID absent; new ID attached and synchronized |
| 2 | Server-driven replica migration | Topology changes that occur independently of the runner's chosen target | Orphaned primary gains a replica; donor retains required redundancy |
| 2 | Primary add/drain/remove | Growing/shrinking primary inventory and slot-map fragmentation | Intended primary count and complete slot coverage |
| 2 | Process pause or client-command pause | Timeouts while sockets remain open; request queues and retry limits | Node responds again and roles converge if a failover occurred |
| 3 | Partition, forced promotion, takeover | Conflicting views, quorum loss, stale old primary, delayed reconciliation | Fault healed and surviving nodes agree on ownership |
| 3 | Atomic slot migration where supported | Different transfer/handoff/cancellation behavior | Migration task and topology both report completion |

### 1. Coordinated failover: the best first addition

The applicable command is **`CLUSTER FAILOVER` sent to the replica to promote**.
Normal mode coordinates with the primary, catches up its replication stream,
and obtains authorization from a majority of primaries. `FORCE` skips primary
coordination but still requires that majority. `TAKEOVER` also bypasses consensus.
An `OK` from normal failover means the attempt was scheduled, not that promotion
completed. See the [Redis command documentation](https://redis.io/docs/latest/commands/cluster-failover/).

Proposed normal scenario:

1. Choose a managed, slot-owning primary A and its replica B. Require B's
   replication link to be up, no loading/full synchronization, acceptable lag,
   and visibility as A's replica from the voting primaries. Check voting
   availability before starting.
2. Record IDs, roles, slot ranges, replication offsets, and configuration epochs.
3. Keep the client's workload running through its established connections and
   send `CLUSTER FAILOVER` to B.
4. Wait for B to own A's original slots, A to become B's replica, and independent
   observers to agree. Validate write progress through the client.
5. Keep the new roles for a while. Later promote A back, after it is eligible,
   to test repeated role reversals and connection reuse.

This should reveal clients that refresh only the slot that returned a redirect,
retain obsolete replica membership, or permanently associate a pooled connection
with one role. Run both primary-only and replica-read workloads. Ordinary
demotion may produce redirection rather than a `READONLY` error; assert recovery
and classify the actual replies instead of requiring one error string.

Use normal failover as a frequent opt-in event. Give `FORCE` and `TAKEOVER`
separate scenario names and coverage counters. A failed normal failover must not
silently escalate into takeover. Valkey documents the same three modes, with
some version-specific timeout configuration; capability and configuration
discovery should therefore precede execution.
[Valkey failover documentation](https://valkey.io/commands/cluster-failover/).

### 2. Primary crash, automatic election, and old-primary return

Add a compound event that abruptly terminates a primary, observes automatic
promotion, and then restarts the old process. Use a verified managed PID and an
actual crash signal such as `SIGKILL`; reuse the existing kill-method abstraction.
Do not use graceful shutdown as the only primary failure mechanism.

Start with at least three slot-owning primaries and an eligible replica for
every primary that may be failed. Verify the surviving voters can form a
majority. Automatic election requires failure detection and a sufficiently
fresh eligible replica; asynchronous replication permits acknowledged writes
to be lost during failures. The [Redis cluster specification](https://redis.io/docs/latest/operate/oss_and_stack/reference/cluster-spec/)
describes these constraints.

Treat crash, election, outage dwell, restart, and reattachment as one scenario
with explicit phases. Election timeouts should account for the actual
`cluster-node-timeout`, failover eligibility settings, and measured lag.
Observe which replica wins instead of assuming the runner's preferred replica
will win. On restart, discover the current role and owner; do not force the old
primary back into its historical role.

Repeat with the original discovery seed as the failed primary. This is an
essential client test and currently a runner limitation. Test requests already
in flight, new connections created during the outage, and long-lived pools.

### 3. Move an existing replica to a different primary

Send `CLUSTER REPLICATE <new-primary-id>` to an existing replica. The target
primary must already be known to that node. A slot-owning or nonempty primary
cannot simply be converted this way, whereas an existing replica can change
its upstream. See [CLUSTER REPLICATE](https://redis.io/docs/latest/commands/cluster-replicate/).

Select a donor shard with spare healthy replicas and a different recipient.
Preserve the replica's process, endpoint, and node ID. Hold the synchronization
window long enough for read-distribution clients to encounter it, then wait for
the new attachment, link, and readiness before allowing another disruptive step.
Restore the original attachment later or deliberately retain the new layout.

This is particularly relevant to PhpRedis replica reads: the old endpoint may
still answer connections, yet no longer belong in the old shard's replica list.
It exercises a different cache invalidation path from killing that endpoint.
The explicit `clusterReplicate()` wrapper already exists.

Also add a separate **automatic replica migration** scenario. Enable
`cluster-allow-replica-migration yes`, leave one primary without healthy replicas,
and provision another with enough surplus above `cluster-migration-barrier`.
Redis can move a replica to the orphaned primary itself. Observe the selected
node instead of prescribing it, and restore changed settings afterward.
[Redis replica migration rules](https://redis.io/docs/latest/operate/oss_and_stack/reference/cluster-spec/#replica-migration).

These are separate controls: disabling automatic replica migration does not
mean primary failover is disabled. The current default configuration should be
recorded prominently in each run's manifest.

### 4. Make existing migrations reliably hit difficult routing states

Traditional resharding distinguishes requests for keys still at the source,
requests redirected with `ASK`, and same-slot multi-key requests that receive
`TRYAGAIN` when only some keys are present. The importing destination expects
`ASKING` before the redirected command. See [CLUSTER SETSLOT](https://redis.io/docs/latest/commands/cluster-setslot/).

Add explicit migration phases and workload coordination:

1. Choose slots from a workload manifest or measured hot-slot set. Populate
   multiple keys sharing a hash tag, such as `{chaos:17}:a` and `{chaos:17}:b`.
   Keep a separate cold-slot variant for topology parsing coverage.
2. Enter `IMPORTING`/`MIGRATING`, then hold briefly before moving keys.
3. Transfer a small key batch and hold with keys on both sides. Send existing-key
   reads, missing-key reads/writes, and same-slot `MGET`/`MSET` from the client.
4. Finish transfer, commit ownership, and check eventual client routing.

Proposed controls include key batch size, a bounded phase dwell, a slot allowlist,
and repeated A→B→A movement. Existing `--slot-batch` counts slots; increasing it
does not guarantee a longer transitional window for any particular hot slot.
Use pipelines and concurrent requests to detect `ASKING` sent on the wrong
pooled connection or replies assigned to the wrong operation.

Retain a normal migration mode that promptly informs other primaries. Add a
separate variant with bounded delay before those notifications, while preserving
the source/destination protocol, to exercise stale observers. Measure resulting
disagreement; gossip may converge before the planned delay ends.

Only after resumable migration recovery exists, add interruption at specific
phases and failover of a source or destination during migration. These should
be reproducible scenario steps, not arbitrary simultaneous random actions.

### 5. Membership and identity changes

Implement `replica-remove`, then a replacement scenario that reuses its port
with a new node ID. Remove a spare managed replica, stop/isolate it, and verify
the old identity is absent before joining its replacement. Keep local metadata
consistent with both completed and interrupted membership changes.

`CLUSTER FORGET` removes a node from an observer's table and temporarily bans
its ID. Redis 7.2+ propagates the ban through gossip; older behavior requires
informing every remaining node. For predictable tests, explicitly notify all
remaining reachable nodes and verify absence everywhere, rather than depending
only on that version-specific propagation.
[CLUSTER FORGET](https://redis.io/docs/latest/commands/cluster-forget/).

A controlled reset of a removed replica is another identity test:
`CLUSTER RESET HARD` assigns a new ID and clears epochs; resetting a replica
also flushes its dataset and makes it an empty primary. Rejoin it using
`MEET`/`REPLICATE` and await synchronization. Restrict this scenario to disposable
replicas already removed from service.
[CLUSTER RESET](https://redis.io/docs/latest/commands/cluster-reset/).

For primary membership, add an empty primary with `MEET`, migrate selected slots
to it, and later drain and remove it after dealing with attached replicas.
Explicitly exercise a primary going from zero slots to some slots and back to
zero. The current random slot planner already permits draining a primary
completely, so final-role discovery must be tested even before adding this
scenario. Do not assume the source stays a primary after losing its last slot.

### 6. Pauses, partitions, and forced promotions

Process pause/resume (`SIGSTOP`/`SIGCONT`) is valuable because TCP connections
can remain established while the server stops making progress. Use pauses
shorter and longer than the cluster failure-detection threshold. A long primary
pause may trigger election; resumption must then converge to the new topology.
Record a cleanup obligation before pausing, and provide an independent watchdog
so termination of the chaos runner does not strand a paused process.

`CLIENT PAUSE <milliseconds> WRITE|ALL` provides a bounded, server-supported
command-processing stall. It is useful for queueing and timeout tests, but has
different effects from stopping the whole process or partitioning its cluster
bus. Record which fault was injected.
[CLIENT PAUSE](https://redis.io/docs/latest/commands/client-pause/).

Partition tests should separately control client traffic, replication traffic,
and cluster-bus traffic. Use an isolated network setup or a dedicated proxy
layer with explicit healing; account for advertised endpoints so clients cannot
bypass the injected fault. Test client access to an isolated old primary as well
as access to the majority side.

Add a forced-failover scenario with the old primary unavailable but a voting
majority reachable. Reserve takeover for explicit disaster-recovery/partition
experiments, where bypassing authorization is the intended subject. Also test
bounded quorum loss or a shard losing all serving nodes to measure
`CLUSTERDOWN` handling. Record coverage/read-availability settings because they
change the scope of the outage.
[Redis cluster configuration](https://redis.io/docs/latest/operate/oss_and_stack/management/scaling/).

Avoid random `DELSLOTS`, `FLUSHSLOTS`, resets of active primaries, or epoch bumps
as an initial expansion. Their useful client-visible outcomes can usually be
produced by the structured scenarios above, with clearer recovery and diagnosis.

### 7. Add atomic slot migration as a separate backend

Redis 8.4 introduced `CLUSTER MIGRATION` for atomic slot migration.
[Redis 8.4 release documentation](https://redis.io/docs/latest/develop/whats-new/8-4/).
On supporting servers, exercise `IMPORT`, task `STATUS`, cancellation, and
handoff under writes. Cancellation on the source alone does not stop destination
retries; cleanup must address both ends and verify task/topology state.
[CLUSTER MIGRATION](https://redis.io/docs/latest/commands/cluster-migration/).

Keep traditional migration for deliberate `ASK`/`TRYAGAIN` coverage. Detect
server flavor, version, and command support; do not assume Redis and Valkey
share this interface or every configuration parameter. Store the migration
backend in the event log so results from the two paths remain interpretable.

## Runner changes needed to support stronger scenarios

These findings come from the current code, not live reproduction. Address the
first four before introducing faults into migration or primary availability.

| Finding | Evidence | Recommended change |
| --- | --- | --- |
| Discovery depends on one seed | `readClusterShardsWithFallback()` and `readClusterInfoWithFallback()` retry TLS/plaintext on the same port; they do not try other nodes. | Maintain independent observer candidates from saved metadata and discovered topology. Read multiple reachable primaries, retaining per-observer views and discrepancies. |
| Unavailability is not modeled as an expected phase | `chaos()` aborts on cluster-down at loop entry. Discovery exceptions can end convergence, and failed events clear `inflightEvent`. | Give each scenario allowed intermediate conditions, a phase deadline, and a recovery obligation. Continue observing during expected outage; block unrelated mutations until recovery completes. |
| Migration cleanup can leave split data | `SlotMigrator::clearMigrationState()` sends `STABLE` after any exception, including one after partial key transfer or ownership notification. It does not move keys back or reconcile ownership. | Journal transfer/commit phases. Reconcile observed data and ownership before clearing state; prefer a verified forward completion where valid. A failed event must remain unresolved until repaired. |
| Success checks omit important state | Migration convergence checks one seed's slot ranges and source/destination reachability. It does not inspect open migration markers. `isHandshake` is always false; the topology hash omits node IDs and epochs. | Parse `CLUSTER NODES` transition markers, flags, IDs and epochs; combine with `SHARDS` and direct role/replication observations. Verify single ownership, no open transfers, and agreement across survivors. |
| Replica readiness is permissive | `isHealthyReplica()` does not exclude `isSyncing`; add/restart completion does not require loading to finish or the replication link to be up. | Distinguish discovered, connected, syncing, readable, and promotion-eligible states. Use scenario-specific readiness predicates. |
| Listening can be mistaken for responsive | Discovery counts `INFO` success **or** a listening port as reachability. | Keep TCP reachability separate from successful Redis commands and bounded response latency, especially for pause tests. |
| Control timeouts can race migration | `MIGRATE` receives 15,000 ms, but `connectToNode()` sets a 1.5-second read timeout; the convergence deadline starts only after execution returns. | Use operation-specific read deadlines and an end-to-end event budget. A controller timeout must trigger state reconciliation, since the server may still be executing. |
| Local metadata may become stale during a run | `createReplicaForPrimary()` persists the added port, but the loop retains its original `$metadata` array. | Refresh metadata after membership changes so newly added nodes remain managed and restartable. Test add→kill→restart in a single run. |
| Category starvation and indefinite idle waits | Highest-score filtering can exclude lower-scored categories; no-candidate iterations wait without an overall duration bound. | Reserve recovery capacity, then use weighted fairness or coverage quotas among eligible mutations. Add a run-duration/idle budget and report skipped categories with blockers. |
| Reproduction is incomplete | Runtime history is in memory; seeded choices depend on observed topology and timing. | Persist a JSONL event journal plus initial topology, IDs, configuration, server/client versions, workload seed, chosen plans, phase timestamps, and results. Replay concrete plans with validated preconditions. |

`MIGRATE` can return an I/O error after an uncertain transfer outcome; this is
another reason recovery must inspect both endpoints rather than blindly retry
with `REPLACE` or merely clear flags.
[MIGRATE error semantics](https://redis.io/docs/latest/commands/migrate/).

The current shape is also becoming difficult to extend: orchestration,
selection, observation, and every event's postcondition are concentrated in
`ClusterManager`. Extract cohesive event handlers, a topology observer, a
candidate scheduler, and a scenario runner. Keep explicit Redis methods in
`RedisNodeClient`, filesystem/journal ownership in `ClusterStateStore`, and CLI
validation/completion in their existing modules. Inject a clock, randomizer,
and fakeable process/Redis interfaces for deterministic unit tests.

## Make the client workload part of the experiment

Keep a separate client harness running continuously before, during, and after
events. Have it supply active slots and timestamped observations to the runner,
without using the client under test as the runner's only source of cluster truth.

| Workload | What to check |
| --- | --- |
| Hot single-key reads/writes | Recovery latency, redirect depth, correct value and key association |
| Replica reads | Replica inventory refresh, bounded stale-read behavior under the selected policy, rejection of unusable replicas |
| Concurrent pipelines | Reply ordering, partial success accounting, reconnect behavior and retry amplification |
| Same-slot multi-key operations and transactions | Transitional routing, bounded retries, accurate reporting of uncertain execution |
| Non-idempotent writes with operation IDs | Possible duplicate execution after an ambiguous timeout; no claim of exactly-once execution without an application mechanism |
| Blocking operations and Pub/Sub, where supported | Reconnection/resubscription and gaps; apply their own delivery expectations |
| Warm persistent pools and new clients | Both stale cached topology and discovery while a seed is unavailable |

Maintain a small immutable sentinel dataset with expected values and a separate
mutable dataset with operation IDs. Verify the sentinel baseline has replicated
before fault injection. After convergence, inspect every relevant shard through
an independent connection and reconcile mutable operations with the harness log.

Distinguish definite command rejection, success, and unknown outcome after a
transport failure. Report replication loss separately from client duplication or
reply corruption. `WAIT` can strengthen a test's replication precondition but
does not turn Redis into a strongly consistent system or remove every failover
loss case. See [Redis replication](https://redis.io/docs/latest/operate/oss_and_stack/management/replication/).

For each event, record the outage duration, p50/p95/p99 latency, longest stalled
operation, redirects/retries, connection churn, topology refresh count, observed
error classes, and time to sustained successful traffic. Establish limits per
scenario and client configuration. Report whether the intended transition was
actually hit by workload traffic; a migration with no observed transitional
requests should not count as `ASK` coverage.

## Suggested delivery sequence and validation

1. **Observation and recovery foundation:** multiple observers, node identity in
   state, metadata refresh, bounded phases, a persistent journal, and migration
   reconciliation. Add coverage accounting before increasing event diversity.
2. **First useful expansion:** coordinated failover, actual crash/restart events,
   replica reparenting, and staged hot-slot migration. Keep one scenario active
   at a time; compound phases already produce substantially stronger stress.
3. **Membership expansion:** implement removal, replacement at the same port,
   automatic replica migration, and primary add/drain/remove.
4. **Advanced campaigns:** watchdog-backed pauses, partitions, explicit
   force/takeover, migration interrupted by failover, and supported atomic
   migration. Add controlled overlap only with per-shard fault budgets and
   independently tracked recovery obligations.

Use a three-primary, two-replica-per-primary topology for the first campaigns,
then test one-replica shards, uneven inventories, and fragmented ownership.
Keep replica-read testing as a named profile and introduce separate primary
failover, resharding, and outage profiles. Retain the current conservative default
until recovery behavior is established; do not overload `--unsafe` to mean every
new fault type is authorized.

Unit tests should use fake observers, clocks, Redis replies, and process controls;
the normal PHPUnit suite must remain socket-free. Cover role reversal, lost seed,
new node ID at an old port, an accepted failover that never completes, a donor
without spare replicas, election timeout, migration failure at each phase,
metadata refresh after add, category starvation, and recovery after runner restart.
The existing slot planner/eligibility tests are useful foundations; the inspected
`ClusterManagerTest` does not cover the chaos loop's event lifecycle.

Validate the implemented scenarios separately in an explicit disposable-cluster
campaign across supported Redis/Valkey versions and TLS modes. Require both
topology recovery and client recovery, save failing journals, and reduce failures
to the smallest replayable scenario. Update `chaos.md`, README, completion tests,
and the changelog when these proposed features are implemented.
