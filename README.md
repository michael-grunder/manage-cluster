# manage-cluster

`bin/manage-cluster` is a local Redis Cluster management CLI for ephemeral test
clusters. It starts and tears down clusters, inspects topology, performs common
maintenance tasks, and supports a few failure-testing workflows that are useful
when developing against Redis or Valkey cluster behavior.

The tool is aimed at local development and automated testing, not production
cluster management.

## Features

- Start a fresh local Redis or Valkey cluster from one or more ports.
- Auto-expand a single seed port into a valid cluster layout.
- Persist cluster metadata so later commands can operate from any known member
  port.
- Stop whole managed clusters cleanly.
- Rebalance slots with `redis-cli --cluster rebalance`.
- Inspect cluster topology with either a TUI table or a plain-text fallback.
- Watch status continuously.
- Flush primary nodes across one or more clusters.
- Fill a cluster with synthetic data until primary memory reaches a target.
- Kill an interactively selected primary/replica or a specific replica port.
- Interactively add a replica to a selected primary.
- Restart an interactively selected failed replica or a specific failed replica port from saved node metadata.
- Run serialized chaos loops that kill, restart, add, and reparent replicas, add
  and remove primaries, migrate slots between primaries, and fail primaries over
  to their replicas, waiting for the cluster to converge between each step.
- Generate shell completion scripts for supported shells.
- Report the running version, including the commit and build time baked into a
  PHAR build.
- Start TLS-only local clusters with ephemeral certificates.
- Generate a standalone startup shell script instead of starting immediately.
- Build a single-file PHAR binary for distribution.

## Requirements

- PHP 8.4+
- Composer dependencies installed
- PhpRedis extension
- `redis-server` or `valkey-server`
- `redis-cli`
- `openssl` when using `--tls`
- An interactive TTY for `add-replica`, for `kill`/`restart-replica` when
  `--replica` is omitted, and for the TUI `status` view

## Installation

Install dependencies:

```bash
composer install
```

By default the CLI looks for `redis-server` and `redis-cli` in `PATH`. You can
override either with `--binary PATH` and `--redis-cli PATH`.

## Quick Start

Start a 3-primary cluster from a single seed port:

```bash
bin/manage-cluster start 7000
```

Start a 3-primary, 1-replica-per-primary cluster:

```bash
bin/manage-cluster start 7000 --replicas 1
```

Start a 4-primary cluster:

```bash
bin/manage-cluster start 7000 --primaries 4
```

Inspect it:

```bash
bin/manage-cluster status 7000
```

Stop it:

```bash
bin/manage-cluster stop 7000
```

## Port Selection

Port arguments accept:

- Individual ports like `7000`
- Hyphen ranges like `7000-7005`
- Brace ranges like `{7000..7005}`

For `start`, a single port expands automatically:

- `bin/manage-cluster start 7000` expands to `7000..7002`
- `bin/manage-cluster start 7000 --replicas 1` expands to 6 ports
- `bin/manage-cluster start 7000 --primaries 4 --replicas 1` expands to 8 ports
- In general, a single seed port expands to `primaries * (replicas + 1)` ports

The final port count must match the requested primary count and be divisible by
`replicas + 1`. A cluster must contain at least 3 primaries.

## Commands

### `start`

Starts one local Redis Cluster and records its metadata in the state store.

```bash
bin/manage-cluster start 7000
bin/manage-cluster start 7000 --primaries 4
bin/manage-cluster start 7000 --replicas 2
bin/manage-cluster start 7000-7005 --binary valkey-server
bin/manage-cluster start 7000 -- --enable-debug-command local
bin/manage-cluster start 7000 --replicas 2 -- replica-serve-stale-data no
```

Useful options:

- `--primaries N` sets the primary count (default: 3)
- `--replicas N` sets replicas per primary
- `--binary PATH` selects `redis-server` or `valkey-server`
- `--cluster-announce-ip IP` advertises a fixed address for all started nodes
- `--tls` enables TLS-only local nodes and generates ephemeral certs
- `--tls-days N` and `--tls-rsa-bits N` tune generated certificates
- `--gen-script PATH` writes an executable startup script instead of launching
- `--state-dir PATH` changes where cluster metadata and per-node files are kept
- Arguments after `--` must be Redis config directive pairs. They are written
  into every generated `redis.conf` and also passed to every started server
  process as command-line config overrides.

Behavior notes:

- The CLI validates executables, requested ports, and cluster shape before
  launch.
- Redis nodes are launched concurrently and then waited on as a batch before
  cluster topology creation.
- Managed node configs set `cluster-allow-replica-migration no` by default so
  Redis does not move replicas between primaries during replica kill/restart
  tests. Pass `-- cluster-allow-replica-migration yes` to opt back into Redis'
  automatic replica migration behavior.
- Startup prints the resolved server flavor/version, such as
  `Redis 8.0.0 (e91a340e)`.
- Managed node files, logs, configs, and metadata live under
  `/tmp/manage-cluster` by default.
- Startup config directives in the generated `redis.conf` are reused when a
  managed replica is later restarted from its saved node config.

### `stop`

Stops all nodes in the selected managed cluster or clusters.

```bash
bin/manage-cluster stop 7000
bin/manage-cluster stop 7000 8000
bin/manage-cluster stop 7000-7005
```

When the seed port belongs to a managed cluster, the command stops all cluster
members and removes its saved metadata. If a node accepts the initial
`SHUTDOWN NOSAVE` request but then stays stuck in a blocked state, `stop`
waits briefly and escalates to OS signals for managed nodes so one hung server
does not stall the whole batch. If the port is not in the state store, the CLI
falls back to stopping the reachable cluster from that seed.

### `status`

Reads `CLUSTER SHARDS`, fetches per-node memory usage, and renders shard and
node status. Without a seed port, it summarizes every managed cluster found in
the configured state directory instead.

```bash
bin/manage-cluster status
bin/manage-cluster status 7000
bin/manage-cluster status --watch
bin/manage-cluster status 7000 --watch
```

Behavior notes:

- Without a seed port, `status` reads the state index and summarizes all known
  managed clusters
- Uses a `php-tui` table when stdout is a TTY
- Falls back to plain text for non-interactive output
- With no seed port, `--watch` opens an interactive `php-tui` overview; use
  Up/Down or `j`/`k` to select a running cluster and Enter to open that
  cluster's `status PORT --watch` view
- Shows per-node used memory; unreachable nodes render `-`
- `--watch` refreshes once per second in a fullscreen boxed TUI and relies on incremental redraws to avoid full-frame flashing
- `--watch` also shows an independently probed per-node latency column; nodes that have not answered the latest background probe yet render as `pending`, slow/unresponsive probes render `timeout`, and hard failures render `down`

### `list`

Shows managed clusters that still appear to be running, based on saved metadata
plus quick local port checks.

```bash
bin/manage-cluster list
```

### `rebalance`

Runs `redis-cli --cluster rebalance` against a seed node.

```bash
bin/manage-cluster rebalance 7000
```

### `flush`

Sends `FLUSHDB` to primary nodes only.

```bash
bin/manage-cluster flush 7000
bin/manage-cluster flush 7000 8000
```

### `fill`

Generates synthetic keys until total primary `used_memory` reaches a target.

```bash
bin/manage-cluster fill --size 1g
bin/manage-cluster fill --size 2.5g --keys 20000
bin/manage-cluster fill 7000 --size 256m --types string,set --members 32 --member-size 2048
bin/manage-cluster fill 7000 --size 512m --pin-primary 7003
```

Useful options:

- `--size SIZE` is required and accepts integer or decimal raw bytes, or
  `k|m|g|t` suffixes such as `18.5m`
- `--types CSV` limits key generation to `string,set,list,hash,zset`
- `--members N` sets entries per composite key
- `--member-size N` sets bytes per string payload or composite member payload
- `--keys N` adjusts adaptive sizing when both size knobs are omitted
- `--pin-primary PORT` restricts generated keys to one primary

Behavior notes:

- If exactly one managed cluster exists, the seed port may be omitted.
- When both `--members` and `--member-size` are omitted, values are derived from
  `--size` using a 5,000-key target by default.
- Progress is shown continuously; TTY output updates one line in place.
- For container types, each member uses `max(8, ceil(member-size / members))`
  bytes.

### `kill`

Opens an interactive tree view rooted at any cluster seed and shuts down the
selected node, or shuts down a specific replica when `--replica PORT` is
provided.

```bash
bin/manage-cluster kill 7000
bin/manage-cluster kill 7000 --replica 7002
bin/manage-cluster kill 7000 --replica 7002 --method sigsegv
bin/manage-cluster kill 7000 --primary 7000 --replica 7002
bin/manage-cluster kill 7000 --all --wait
bin/manage-cluster kill 7000 --primary 7000 --all --wait
```

The picker shows primaries followed by their replicas. Navigation supports
`↑`/`↓` or `j`/`k`, `Enter` to confirm, and `q` or `Esc` to cancel.
When `--replica` is used, the port must be a replica in the discovered topology.
Add `--primary PORT` to ensure that replica belongs to the expected primary;
invalid targets print the valid replicas grouped by primary.
Use `--all` to shut down every replica in the cluster, or combine it with
`--primary PORT` to shut down every replica attached to that primary. Add
`--wait` when targeting replicas to keep the command running until Redis cluster
state reports each stopped replica as down.
Use `--method METHOD` to choose how targets are terminated. `shutdown` is the
default, `nosave` sends `SHUTDOWN NOSAVE`, and `sigterm`, `sigquit`, `sigsegv`,
`sigkill`, `sigabrt`, or `sigbus` send that process signal to the managed Redis
process from saved node metadata.

### `add-replica`

Starts a new node and attaches it as a replica of a selected primary.

```bash
bin/manage-cluster add-replica 7000
bin/manage-cluster add-replica 7000 --port 7010
```

Behavior notes:

- Opens an interactive primary picker
- If `--port` is omitted, the CLI picks the first free port above the current
  cluster range
- Reuses the managed cluster directory when it can resolve one from metadata or
  node config
- Supports `--binary`, `--cluster-announce-ip`, `--tls`, `--tls-days`,
  `--tls-rsa-bits`, and `--state-dir`

### `restart-replica`

Restarts a failed replica using its existing managed node config.

```bash
bin/manage-cluster restart-replica 7000
bin/manage-cluster restart-replica 7000 --replica 7002
bin/manage-cluster restart-replica 7000 --primary 7000 --replica 7002
bin/manage-cluster restart-replica 7000 --all --wait
bin/manage-cluster restart-replica 7000 --primary 7000 --all --wait
bin/manage-cluster restart-replica 7000 --replica 7002 --config replica-serve-stale-data=no
```

Behavior notes:

- Requires an interactive TTY only when `--replica` is omitted
- Only failed replicas can be restarted
- Add `--primary PORT` to ensure the failed replica belongs to the expected
  primary before restarting it
- Use `--all` to restart every failed replica in the cluster, or combine it with
  `--primary PORT` to restart every failed replica attached to that primary
- Add `--wait` to keep the command running until Redis cluster state reports
  each restarted replica as healthy; while waiting, progress output includes
  replica offsets against their primary offset when Redis reports them
- `--all` starts every selected replica process before waiting for readiness, so
  multiple replicas can synchronize back in parallel
- Repeated `--config NAME=VALUE` options run `CONFIG SET` after the replica is
  ready and then `CONFIG REWRITE` so the override is persisted in `redis.conf`
- Invalid `--replica` targets print the restartable failed replicas grouped by
  primary
- `kill` attempts `CONFIG REWRITE` before shutdown so runtime config changes are
  preserved when Redis can rewrite the managed node config

### `chaos`

Runs a conservative, serialized chaos loop aimed at replica and slot churn
rather than a fully generic chaos monkey.

```bash
bin/manage-cluster chaos 7000
bin/manage-cluster chaos 7000 --categories replica-kill,replica-restart
bin/manage-cluster chaos 7000 --categories all
bin/manage-cluster chaos 7000 --max-events 50
bin/manage-cluster chaos 7000 --interval 8 --watch
bin/manage-cluster chaos 7000 --dry-run
bin/manage-cluster chaos 7000 --allow-slot-migration --slot-batch 32
bin/manage-cluster chaos 7000 --categories slot-migration --slot-strategy random
bin/manage-cluster chaos 7000 --allow-primary-failover
bin/manage-cluster chaos 7000 --categories primary-failover --watch
bin/manage-cluster chaos 7000 --allow-replica-reparent
bin/manage-cluster chaos 7000 --allow-primary-add --allow-primary-remove
```

Useful options:

- `--categories LIST` limits event selection to `replica-kill`,
  `replica-restart`, `replica-remove`, `replica-add`, `replica-reparent`,
  `primary-add`, `primary-remove`, `slot-migration`, and `primary-failover`;
  pass `all` instead of a list to allow every category. Without
  `--categories`, chaos runs `replica-kill`, `replica-restart`, and
  `replica-add`, and every other category is opt-in
- `--interval SECONDS` sets the minimum time between completed steps
- `--max-events N` stops after N completed events
- `--max-failures N` aborts after N consecutive execution or convergence failures
- `--dry-run` prints the next planned event without mutating the cluster
- `--watch` prints compact state and convergence progress
- `--seed N` seeds the PRNG for reproducible event selection
- `--wait-timeout SECONDS` bounds the post-event convergence wait
- `--cooldown SECONDS` adds a quiet period after convergence
- `--allow-slot-migration` adds `slot-migration` to the allowed categories
  without having to restate the replica categories
- `--allow-primary-failover` adds `primary-failover` to the allowed categories
  without having to restate the replica categories
- `--allow-replica-reparent` adds `replica-reparent` to the allowed categories
  without having to restate the replica categories
- `--allow-primary-add` and `--allow-primary-remove` add the primary membership
  categories to the allowed categories
- `--slot-strategy NAME` picks `balanced` (default) or `random` slot selection
- `--slot-batch N` bounds how many slots one migration event moves (default: 16)
- `--unsafe` allows lower-redundancy actions that are otherwise skipped

Behavior notes:

- `chaos` actively executes `replica-kill`, `replica-restart`, `replica-add`,
  `replica-reparent`, `primary-add`, `primary-remove`, `slot-migration`, and
  `primary-failover`
- `replica-remove` is parsed for forward compatibility but remains disabled by
  the conservative planner
- The loop keeps in-memory runtime history so follow-up actions can repair or
  extend earlier replica churn instead of choosing stateless random actions
- Only one mutation is in flight at a time, and each event must satisfy a
  topology-based postcondition before the next one can start
- `--dry-run` with no `--max-events` prints a single planned step and exits
- Requires saved managed-cluster metadata in the configured `--state-dir`

#### Slot migration

`slot-migration` is off by default. Enable it with `--allow-slot-migration`, or
name it in `--categories`. Each event moves a bounded batch of slots from one
primary to another using `CLUSTER SETSLOT` plus `MIGRATE`, then waits until the
destination owns every migrated slot and no migration state is left open.

Two strategies decide which slots move where:

- `balanced` (default) weights the choice by current ownership, so primaries
  holding more slots are likelier to give them up and primaries holding fewer
  are likelier to receive them. Each move closes about half the gap between the
  two, never drains its source, and never runs toward the heavier primary, so
  repeated events keep the cluster roughly even and ownership stays contiguous.
- `random` ignores the distribution entirely: a uniformly chosen primary hands a
  randomly sized, randomly positioned window of its slots to another uniformly
  chosen primary, and it may hand over every slot it owns. This deliberately
  produces fragmented, lopsided topologies, which is the point when testing how
  a client copes with them.

Slot migration only runs on a settled cluster: no `CLUSTERDOWN`, no degraded
primaries, and no failed, loading, or syncing nodes. `--unsafe` skips that
precondition. When the cluster is temporarily unsettled, chaos waits without
consuming the failure budget; `--watch` identifies the blocking nodes and
conditions. `--seed N` makes both event selection and slot planning reproducible.

Migrating slots fragments ownership, so `status` reports each primary's slot
ranges as a comma-separated list, and a primary that has given away every slot
is still listed with `[-]`.

#### Primary failover

`primary-failover` is off by default. Enable it with `--allow-primary-failover`,
or name it in `--categories`. Each event sends a coordinated `CLUSTER FAILOVER`
to a replica, which swaps the shard's writable endpoint without stopping any
process: the replica is promoted and its old primary comes back as a replica of
the node that replaced it. It is the cheapest way to make a client refresh a
shard's routing, notice role changes on connections it already holds, and retry
writes that were aimed at the old primary.

A shard is only chosen when the promotion has a realistic chance of completing:

- the primary is managed, reachable, owns slots, and is not already failing over
- the replica is managed, attached to that primary, reachable, not loading or
  syncing, and reports `master_link_status:up`
- the replica is caught up within 1 MiB of its primary's replication offset
- at least three primaries own slots and a majority of them are reachable, since
  a normal failover needs their authorization

`CLUSTER FAILOVER` only acknowledges that the promotion was scheduled, so the
event is not complete until the promoted node owns every slot the old primary
had and the old primary has resynchronized as its replica. A failover that never
converges fails the event; chaos never escalates it to `FORCE` or `TAKEOVER`.

Unlike `slot-migration`, failover does not require the whole cluster to be
settled, so it can run in the same loop as replica churn. It is blocked while
`CLUSTERDOWN` is reported, and, without `--unsafe`, while any primary is
unreachable or failed or any node is still handshaking. Over a run, chaos holds
the new roles for a while instead of failing straight back, then prefers
promoting a primary it demoted earlier so role reversals and connection reuse
both get exercised.

#### Replica reparenting

`replica-reparent` is off by default. Enable it with `--allow-replica-reparent`,
or name it in `--categories`. Each event sends `CLUSTER REPLICATE` to a live
replica so it follows a different primary. Nothing stops and nothing is
replaced: the replica keeps its process, port, and node ID, so a client that
cached the donor shard's replica list still reaches a server that answers
normally but no longer belongs to that shard. That is a different cache
invalidation path from killing the same endpoint, and it is the direct test of
whether a client remaps the keyspace behind a replica it already knows.

A move is only chosen when it is a routing experiment rather than a redundancy
cut:

- the replica is managed, attached, reachable, not loading or syncing, and
  reports `master_link_status:up`
- the donor primary is reachable and keeps another healthy replica afterwards,
  unless `--unsafe` allows draining it to zero
- the recipient is a different reachable primary that owns slots and is not
  failed, loading, or failing over
- at least two reachable primaries own slots

The event is complete only when the same node ID appears under the new primary,
the donor no longer lists it, and its replication link to the new primary is up,
so chaos does not stack another mutation on a replica that is still
synchronizing. Selection prefers a recipient with no healthy replicas, holds a
new layout for a while instead of bouncing one replica between two shards, and
later prefers returning a replica to the shard it came from.

#### Primary membership

`primary-add` and `primary-remove` are off by default, and each is a multi-step
event that changes how many shards a client has to know about. Enable them with
`--allow-primary-add` / `--allow-primary-remove`, or name them in
`--categories`.

`primary-add` grows the cluster:

1. Start a new managed node in the cluster's own directory and join it with
   `CLUSTER MEET`, so an empty primary appears that owns no keys yet.
2. Migrate up to `--slot-batch` slots into it from the primary that owns the
   most, so the new shard starts serving part of the keyspace.

`primary-remove` shrinks it, in the order that keeps the keyspace covered
throughout:

1. Reattach every replica of the departing primary to a surviving one, so
   nothing is left following a node that is about to disappear.
2. Drain every slot it owns, spread in contiguous chunks across the remaining
   primaries.
3. `CLUSTER FORGET` its node ID from every remaining reachable node.
4. Stop the process and drop the port from the managed cluster metadata.

Both refuse to run unless at least three reachable primaries own slots, and a
removal must leave at least three behind, so the cluster keeps enough primaries
to authorize a failover. Without `--unsafe` they also wait for a settled
membership: no unreachable or failed primary, no handshaking node, and no down
replica, since a replica that is down cannot be moved out of the way. Chaos will
not grow past six primaries, never removes the seed port it discovers the
cluster through, holds a new shard for at least one event before taking it away,
and then prefers completing the add/remove cycle for a primary it created.

Removal drains one slot at a time through the same `MIGRATE` path as
`slot-migration`, so removing a primary that owns a third of the keyspace is a
long event. Scoring prefers victims whose drain fits inside `--slot-batch`,
which is exactly what a chaos-added primary owns.

### `help`

Show top-level or command-specific help:

```bash
bin/manage-cluster help
bin/manage-cluster help start
bin/manage-cluster help fill
```

Usage lines and examples are rendered with the command you actually ran, so the
printed examples can be copied and pasted as-is:

- `manage-cluster` when the command was resolved through `PATH`, including a
  PHAR installed under that name
- A path relative to the current directory, such as `bin/manage-cluster` or
  `./manage-cluster.phar`, when the command lives inside it
- The path as given otherwise

Error output uses the same name, and `completions bash|zsh` registers
completion for the name the CLI was invoked as.

### `version`

Show which build is running:

```bash
bin/manage-cluster version
bin/manage-cluster --version
bin/manage-cluster -v
```

A PHAR reports the metadata recorded when it was built:

```
manage-cluster.phar 0.1.0
commit    5ef1f40
built     2026-09-05 20:04:01 UTC
source    PHAR build
php       8.4.13
```

A source checkout has no recorded build, so it reports the declared version and
describes the working tree with `git` instead, marking a tree with uncommitted
changes as `-dirty`:

```
manage-cluster 0.1.0
commit    5ef1f40-dirty
source    source checkout
php       8.4.13
```

The `commit` line is omitted when `git` is unavailable or the checkout is not a
repository. The declared version lives in `BuildInfo::VERSION`; `composer.json`
intentionally carries no `version` field, as Composer recommends for packages
versioned by VCS tags.

### `completions`

Generate a shell completion script:

```bash
bin/manage-cluster completions bash
bin/manage-cluster completions zsh
```

Source the generated script from your shell startup files or install it into the
appropriate completion directory for your shell.

## State Directory

Managed cluster state defaults to `/tmp/manage-cluster`.

Each cluster gets its own directory containing:

- `cluster.json` metadata
- Per-node directories with `redis.conf`, `redis.log`, `redis.pid`, and
  `nodes.conf`
- TLS material when `--tls` is used

The state store is what allows later commands such as `stop`, `fill`, and
`restart-replica` to work from a seed port instead of requiring full cluster
topology to be passed every time.

## TLS

`start --tls` and `add-replica --tls` generate ephemeral local CA/server
material for development and testing. Certificates default to 3650 days and
2048-bit RSA keys, both configurable with `--tls-days` and `--tls-rsa-bits`.

This mode is for local test clusters, not long-lived PKI management.

## Start Script Generation

Instead of launching immediately, `start` can emit a standalone executable shell
script:

```bash
bin/manage-cluster --gen-script start-cluster.sh start {7000..7002}
./start-cluster.sh
```

The generated script performs executable and port preflight checks, starts the
nodes, creates the cluster, emits progress messages, and preserves the cluster
directory on failure for inspection.

## PHAR Builds

Build an executable PHAR archive:

```bash
composer build-phar
```

Custom output path:

```bash
composer build-phar -- --output dist/custom-name.phar
```

Direct builder usage:

```bash
/path/to/php -d phar.readonly=0 bin/build-phar --output dist/custom-name.phar
```

Compression control:

```bash
/path/to/php -d phar.readonly=0 bin/build-phar --compression auto
/path/to/php -d phar.readonly=0 bin/build-phar --compression none
/path/to/php -d phar.readonly=0 bin/build-phar --compression gz
/path/to/php -d phar.readonly=0 bin/build-phar --compression bz2
```

Notes:

- PHAR builds require the `phar` extension and `phar.readonly=0` at build time
- Each build embeds `build-info.json` with the version, the commit it was built
  from, and the UTC build time, which `manage-cluster version` prints
- Automatic compression prefers `bz2`, then `gz`, then uncompressed output
- Compressed PHARs require the matching runtime extension: `bz2` for bzip2,
  `zlib` for gzip

Run the built archive directly:

```bash
./dist/manage-cluster.phar start 7000 --replicas 1
```

Copy the archive somewhere on `PATH` to run it as a bare command. Help text and
generated completions follow that name:

```bash
cp dist/manage-cluster.phar ~/.local/bin/manage-cluster
manage-cluster help start
manage-cluster completions zsh
```

## Development

Useful checks:

```bash
php -l bin/manage-cluster
php -l src/*.php
vendor/bin/phpstan analyze
vendor/bin/phpunit
```

## Release Notes

The changelog lives in `CHANGELOG.md` and follows Keep a Changelog. The current
unreleased section already captures the functionality that has accumulated ahead
of an expected `v0.1.0` tag.
