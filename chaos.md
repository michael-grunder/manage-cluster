# Spec: `bin/manage-cluster chaos` (v1)

## Goal

Add a new `chaos` command to `bin/manage-cluster` that performs
serialized, semi-intelligent cluster mutations intended to exercise
client behavior under realistic topology churn without immediately
driving the cluster into unrecoverable `CLUSTERDOWN`.

The initial v1 focus is **replica mutation chaos** to help test
PhpRedis' read-distribution behavior when replicas disappear, recover,
or are replaced while the cluster remains mostly healthy.

This is **not** intended to be a full generic chaos monkey in v1.
It should be biased toward:
- primary/replica membership changes
- replica failures and recovery
- replica resynchronization
- controlled slot movement only when safe

The command should maintain internal state across steps so actions are
chosen based on:
- current cluster topology
- pending recovery states caused by earlier actions
- safety invariants
- user-selected event categories

---

## CLI shape

### New command
`bin/manage-cluster chaos [seed-port] [OPTIONS]`

### Examples
`bin/manage-cluster chaos 7000`
`bin/manage-cluster chaos 7000 --categories replica-kill,replica-restart`
`bin/manage-cluster chaos 7000 --max-events 50`
`bin/manage-cluster chaos 7000 --interval 8`
`bin/manage-cluster chaos 7000 --dry-run`
`bin/manage-cluster chaos 7000 --watch`

### Options
- `--categories LIST`
  Comma-separated set of event categories allowed in this run.

  Initial supported categories:
  - `replica-kill`
  - `replica-restart`
  - `replica-remove`
  - `replica-add`
  - `slot-migration`

  Default in v1:
  - `replica-kill,replica-restart,replica-add`

  Notes:
  - `replica-remove` should exist in the design, but may be disabled by
    default in v1 if implementation is incomplete.
  - `slot-migration` should exist in the design but remain conservative.

- `--interval SECONDS`
  Minimum time between completed chaos steps.
  Default: `8`

- `--max-events N`
  Stop after at most N successfully executed events.
  Default: unlimited

- `--max-failures N`
  Abort if event selection or execution fails N times in a row.
  Default: `5`

- `--dry-run`
  Print what would be done, but do not mutate cluster state.

- `--watch`
  Continuously print status after each step and while waiting for
  convergence.

- `--seed SECONDS`
  Optional PRNG seed for reproducibility.

- `--wait-timeout SECONDS`
  Maximum time to wait for a post-event convergence target before the
  event is considered failed.
  Default: `60`

- `--cooldown SECONDS`
  Additional post-convergence quiet period before the next event.
  Default: `2`

- `--allow-slot-migration`
  Explicit opt-in if `slot-migration` is implemented but not enabled by
  default.

- `--unsafe`
  Explicit opt-in for actions that may temporarily reduce redundancy
  below normal safety thresholds. Not required for normal v1 replica
  kill/restart/add flows.

---

## High-level behavior

`chaos` runs a loop:

1. Discover current cluster state
2. Build an internal model of:
   - primaries
   - replicas
   - failed/unreachable nodes
   - replica sync state
   - slot ownership layout
   - node processes known from local cluster metadata
   - outstanding prior chaos actions
3. Select the next valid event from the allowed categories
4. Execute exactly one event
5. Record the event in persistent in-memory runtime state
6. Wait for the expected cluster reaction/convergence
7. Mark the event complete, degraded, or failed
8. Sleep until the next iteration

Only one chaos action may be in-flight at a time.

No concurrent mutations in v1.

---

## Design principles

### 1. Serialized mutations
The system must never perform two disruptive topology changes at once.

Bad:
- kill one replica
- immediately add another replica elsewhere
- immediately rebalance slots

Good:
- kill one replica
- wait for cluster to report it failed or disconnected
- observe stable new state
- only then decide next action

### 2. Stateful event chain
The command must remember what it already did during this run.

Example:
- event 1 kills replica `7002` of primary `7000`
- runtime state records that this replica was intentionally killed
- next eligible events may include:
  - restarting `7002`
  - replacing it with a newly added replica
  - leaving it down temporarily while testing degraded read topology

This avoids "stateless random nonsense" and makes follow-up actions
possible.

### 3. Safety before randomness
Randomness is allowed only within the set of events that pass safety
checks.

The command should never intentionally choose an event that is likely to
cause immediate cluster-wide unavailability when a safer eligible action
exists.

### 4. Observable convergence
Every event type must define a postcondition that can be waited on.

Examples:
- killed replica is reported disconnected or failed
- restarted replica is reachable again
- added replica appears as a replica of the chosen primary
- syncing replica reaches stable `connected`/online state
- slot migration finishes with stable slot ownership

---

## v1 scope

### Primary target
Exercise **replica churn** in a way that causes:
- stale replica lists
- dead replica selection
- changing replica counts
- replacement replicas joining and syncing
- transient sync windows where a replica is present but not yet useful

This is specifically useful for testing clients that:
- distribute reads across replicas
- cache cluster topology
- retry when a selected replica is down
- must recover when replicas are replaced or lagging

### Explicit non-goals for v1
- intentionally killing primaries
- forcing failover of primaries
- simultaneous multi-node outages
- network partition simulation
- process pause / SIGSTOP / half-dead socket simulation
- slot migration under heavy concurrent topology churn
- random arbitrary CLUSTER MEET / FORGET experiments

Those can be future v2/v3 features.

---

## Internal model

Each loop iteration should materialize a runtime model similar to:

### ClusterRuntimeState
- `cluster_id`
- `seed_port`
- `started_at`
- `event_counter`
- `consecutive_failures`
- `allowed_categories`
- `inflight_event` (null or one event)
- `history[]`
- `node_state_by_port`
- `primary_state_by_port`
- `replica_state_by_port`
- `degraded_primaries`
- `last_stable_topology_hash`

### NodeState
- `port`
- `node_id`
- `role` (`primary` or `replica`)
- `primary_port` (for replicas)
- `known_by_cluster` (bool)
- `reachable` (bool)
- `is_failed` (bool)
- `is_handshake` (bool)
- `is_loading` (bool)
- `is_syncing` (bool)
- `link_status`
- `slots[]` or slot ranges for primaries
- `pid` if available from local metadata
- `managed` (bool)

### EventRecord
- `id`
- `category`
- `status`
  - `planned`
  - `running`
  - `waiting`
  - `completed`
  - `failed`
  - `aborted`
- `target_port`
- `target_primary_port`
- `started_at`
- `completed_at`
- `summary`
- `postcondition`
- `notes`

---

## Event categories

## 1. `replica-kill`

### Purpose
Stop one managed replica process to simulate a dead replica while
leaving its primary alive.

### Candidate eligibility
A replica may be selected only if:
- it is currently reachable
- it belongs to a reachable primary
- its primary still has at least one remaining healthy path after the
  kill, according to v1 safety rules
- no other mutation is in-flight
- it is not currently syncing from a just-added replacement unless the
  user explicitly allows that in a later version

### Safety rules
In v1, prefer replicas where:
- the primary has at least 2 replicas before the kill, or
- the cluster has enough overall redundancy that losing this replica
  leaves the primary still readable via at least one other replica

If the primary has only one replica, `replica-kill` may still be allowed
in v1 because testing "primary now has zero healthy replicas" is useful
for PhpRedis read distribution testing, but:
- only one such degraded primary may exist at a time
- the cluster must otherwise be healthy
- do not stack this with other degraded primaries unless `--unsafe`

### Execution
- stop the replica process using existing managed-cluster machinery
- record the event as `running`
- transition to `waiting`

### Postcondition
Wait until one of:
- cluster reports the replica unreachable / failed
- node disappears from local reachability checks
- timeout

### Completion
Mark complete when the replica is observably down from the cluster's
perspective, not merely when the process was signaled.

---

## 2. `replica-restart`

### Purpose
Bring back a previously killed or failed managed replica.

### Candidate eligibility
A replica may be selected if:
- it is known in local cluster metadata
- it is currently not reachable
- it previously belonged to a still-existing primary
- its restart parameters are known

### Execution
- restart the node process from metadata
- if needed, rejoin it according to existing restart logic
- record as `running`
- transition to `waiting`

### Postcondition
Wait for:
- process reachable on TCP
- node visible in cluster topology
- node role = replica
- replica attached to expected primary
- optionally wait until sync stabilizes

### Completion
Mark complete when the replica is back as a healthy replica or at least
clearly in a valid sync/rejoin path.

### Notes
For v1, "healthy enough to count" should be configurable but default to:
- reachable
- listed as replica of the expected primary
- cluster not reporting it as failed

Do not require full data parity checks in v1.

---

## 3. `replica-add`

### Purpose
Add a brand new replica to a selected primary to simulate topology
expansion and replica resynchronization.

### Candidate eligibility
A primary may be selected if:
- it is reachable
- it owns slots
- the cluster is otherwise stable
- there is no inflight mutation
- available free port(s) and metadata path(s) exist
- the number of replicas for that primary is below a configurable cap

### Preferred targets
Bias toward primaries that:
- currently have fewer replicas than others
- recently lost a replica
- are degraded due to earlier chaos actions

### Execution
- allocate a new managed node
- start it
- join it as a replica of the selected primary
- record event with:
  - new replica port
  - target primary port
- transition to `waiting`

### Postcondition
Wait for:
- new node reachable
- cluster sees node as replica of target primary
- replica link active or sync in progress
- eventually stable replica state

### Completion
Mark complete when the node is visible as a functioning replica, even if
it only recently completed sync.

### Notes
This is a key v1 event because it exercises:
- changing replica inventories
- clients discovering new replicas
- replicas that exist before they are fully useful

---

## 4. `replica-remove`

### Purpose
Remove an existing replica from the cluster topology cleanly.

### v1 status
Optional / stretch goal.
Define the interface and state shape now, but implementation may be
deferred or initially disabled.

### Candidate eligibility
A replica may be selected only if:
- it is reachable
- it is not the only healthy replica for a primary unless `--unsafe`
- cluster is stable
- no inflight mutation exists

### Execution
Likely flow:
- ensure replica is not needed for other pending recovery logic
- forget/remove from cluster
- stop process and remove local metadata as appropriate

### Postcondition
Wait for:
- node no longer appears in cluster topology, or
- node is cleanly forgotten and gone

### Notes
If implementation complexity is high, v1 may omit this action entirely.

---

## 5. `slot-migration`

### Purpose
Exercise slot ownership changes in a controlled way without combining
them with major replica churn.

### v1 status
Supported in design, conservative in implementation.

### Candidate eligibility
Slot migration may be selected only if:
- cluster is fully healthy
- there are no degraded primaries
- no nodes are failed
- no replicas are currently syncing
- no inflight mutation exists
- user enabled category explicitly if guarded

### Execution
- move a small number of slots from one primary to another
- prefer a bounded, incremental rebalance rather than full rebalance

### Postcondition
Wait for:
- migrated slots stably owned by destination primary
- no open migrating/importing states remain
- cluster remains healthy

### Notes
For v1, slot migration should be rare and small.
Replica mutation remains the priority.

---

## Event selection policy

Each loop must:
1. build the set of eligible candidate events
2. discard unsafe events
3. score the remaining events
4. randomly choose among the top-scoring subset

This gives "semi-random but intelligent" behavior.

### Scoring hints

Prefer events that:
- repair or evolve existing chaos state
- deepen a useful test scenario without destroying cluster health
- exercise different primaries over time
- avoid repeating the same exact action too often

Example scoring ideas:
- +4 restart a replica intentionally killed earlier
- +3 add a replica to a degraded primary
- +2 kill a healthy replica on a currently stable primary
- -5 any event that would create a second degraded primary
- -10 any event blocked by sync or instability
- -100 events forbidden by safety invariants

### Anti-repetition rules
Avoid:
- killing the same replica repeatedly back-to-back
- adding unlimited replicas to the same primary
- bouncing a replica up/down so fast that the cluster never converges

---

## Safety invariants

These invariants must be checked before every event.

### Global invariants
- only one inflight event at a time
- cluster must not already be in `CLUSTERDOWN`
- abort or pause if cluster becomes broadly unhealthy
- do not perform mutations while previous convergence is unresolved

### Replica-chaos invariants
- at most one degraded primary at a time in normal mode
- do not kill a replica on a primary already waiting for replacement
- do not remove a replica while it is the only healthy follower for a
  primary unless `--unsafe`
- do not add a new replica if another replica is still joining/syncing
  for that same primary
- do not perform slot migration while any replica is down/syncing

### Abort conditions
Abort the run if:
- cluster enters persistent `CLUSTERDOWN`
- topology cannot be parsed
- managed metadata is inconsistent
- consecutive event failures exceed threshold
- wait timeout is reached for a required postcondition and the cluster is
  left in an unknown dangerous state

---

## Waiting and convergence

After each event, the command enters a wait loop.

### Wait loop behavior
- poll cluster state once per second
- print concise progress in `--watch` mode
- check the event-specific postcondition
- optionally require the topology to remain stable for N consecutive
  polls before marking complete

### Suggested stable completion rule
An event is complete only when:
- its postcondition is satisfied, and
- the relevant topology remains unchanged for 2 consecutive polls

This helps avoid marking success during transient gossip windows.

### Examples

#### After `replica-kill`
Wait for:
- target replica unreachable or failed
- target primary still reachable
- cluster not in global down state

#### After `replica-add`
Wait for:
- new replica exists
- replica attached to correct primary
- replica no longer in a transient handshake-only state

#### After `replica-restart`
Wait for:
- target reachable again
- cluster sees it in expected role
- no immediate fail flag

---

## Dry-run mode

When `--dry-run` is enabled:
- discover the cluster normally
- perform eligibility and scoring normally
- print the event that would be executed
- print why it was eligible
- do not mutate anything
- optionally continue looping and printing hypothetical next steps

This is important both for debugging the policy and for prompting the
model to generate safe logic.

---

## Watch output

In `--watch` mode, print compact operator-friendly output such as:

- current cluster health
- primaries and replica counts
- degraded primaries
- inflight event
- last completed event
- current wait reason / postcondition

Example sketch:

`[chaos] event#4 replica-kill target=7002 primary=7000`
`[wait ] 7002 unreachable=1 failed=1 primary=7000 healthy=1`
`[done ] replica-kill target=7002 completed in 6.2s`

Or a slightly richer summary block if desired.

---

## Suggested v1 implementation phases

### Phase 1
Implement:
- command skeleton
- runtime state model
- cluster discovery
- dry-run event selection
- watch output

No mutations yet.

### Phase 2
Implement:
- `replica-kill`
- `replica-restart`

This already gives a useful degraded/recovery loop.

### Phase 3
Implement:
- `replica-add`

This is the most valuable next step for PhpRedis read-distribution
testing because it exercises topology growth and synchronization.

### Phase 4
Optionally implement:
- conservative `slot-migration`
- optional `replica-remove`

---

## Suggested default v1 policy

Default categories:
- `replica-kill`
- `replica-restart`
- `replica-add`

Default behavioral bias:
- prefer creating one degraded-primary scenario
- then prefer either restarting the dead replica or adding a replacement
- once cluster stabilizes, choose another primary and repeat

In other words, the default loop should look roughly like:
1. pick one healthy replica and kill it
2. wait until failure is observable
3. either:
   - restart it, or
   - add a replacement replica
4. wait until stable
5. possibly restart old dead replica later, or remove it manually
6. repeat elsewhere

That gives realistic replica churn without turning the cluster into
garbage.

---

## PhpRedis-oriented test value

This chaos mode should help surface client bugs around:
- cached replica lists containing dead nodes
- selecting a down replica for reads
- re-resolving topology after read failures
- discovering newly added replicas
- handling replicas that are present but syncing
- skewed replica counts across primaries
- transient cluster metadata inconsistency during replica churn

This makes v1 specifically useful for improving:
- read distribution fallback logic
- retry strategies
- topology refresh timing
- resilience under rare operational changes

---

## Minimal acceptance criteria for v1

A v1 implementation is acceptable if it can:
- target one managed cluster from a seed port
- run a serialized event loop
- maintain runtime history/state
- safely execute replica kill and restart events
- optionally add new replicas
- wait for observable convergence after each event
- avoid stacking mutations that commonly cause `CLUSTERDOWN`
- print enough status for an operator to understand what is happening

It does not need to:
- perfectly model all Redis cluster edge cases
- guarantee zero risk of temporary degradation
- support every topology mutation category immediately

The primary success criterion is:
**useful, repeatable, semi-intelligent replica churn for client testing**.
