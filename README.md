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
- Interactively kill a selected primary or replica.
- Interactively add a replica to a selected primary.
- Interactively restart a failed replica from saved node metadata.
- Run serialized replica-chaos loops that kill, restart, and add replicas while
  waiting for the cluster to converge between each step.
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
- An interactive TTY for `kill`, `add-replica`, `restart-replica`, and the TUI
  `status` view

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
- Arguments after `--` are appended to every started server process

Behavior notes:

- The CLI validates executables, requested ports, and cluster shape before
  launch.
- Redis nodes are launched concurrently and then waited on as a batch before
  cluster topology creation.
- Startup prints the resolved server flavor/version, such as
  `Redis 8.0.0 (e91a340e)`.
- Managed node files, logs, configs, and metadata live under
  `/tmp/manage-cluster` by default.

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
bin/manage-cluster status 7000 --watch
```

Behavior notes:

- Without a seed port, `status` reads the state index and summarizes all known
  managed clusters
- Uses a `php-tui` table when stdout is a TTY
- Falls back to plain text for non-interactive output
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
bin/manage-cluster fill --size 5g --keys 20000
bin/manage-cluster fill 7000 --size 256m --types string,set --members 32 --member-size 2048
bin/manage-cluster fill 7000 --size 512m --pin-primary 7003
```

Useful options:

- `--size SIZE` is required and accepts raw bytes or `k|m|g|t` suffixes
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
selected node.

```bash
bin/manage-cluster kill 7000
```

The picker shows primaries followed by their replicas. Navigation supports
`↑`/`↓` or `j`/`k`, `Enter` to confirm, and `q` or `Esc` to cancel.

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
```

Behavior notes:

- Requires an interactive TTY
- Only failed replica rows are selectable

### `chaos`

Runs a conservative, serialized chaos loop aimed at replica churn rather than a
fully generic chaos monkey.

```bash
bin/manage-cluster chaos 7000
bin/manage-cluster chaos 7000 --categories replica-kill,replica-restart
bin/manage-cluster chaos 7000 --max-events 50
bin/manage-cluster chaos 7000 --interval 8 --watch
bin/manage-cluster chaos 7000 --dry-run
```

Useful options:

- `--categories LIST` limits event selection to `replica-kill`,
  `replica-restart`, `replica-remove`, `replica-add`, and `slot-migration`
- `--interval SECONDS` sets the minimum time between completed steps
- `--max-events N` stops after N completed events
- `--max-failures N` aborts after N consecutive planning or execution failures
- `--dry-run` prints the next planned event without mutating the cluster
- `--watch` prints compact state and convergence progress
- `--seed N` seeds the PRNG for reproducible event selection
- `--wait-timeout SECONDS` bounds the post-event convergence wait
- `--cooldown SECONDS` adds a quiet period after convergence
- `--unsafe` allows lower-redundancy actions that are otherwise skipped

Behavior notes:

- v1 actively executes `replica-kill`, `replica-restart`, and `replica-add`
- `replica-remove` and `slot-migration` are parsed for forward compatibility but
  remain disabled by the conservative v1 planner
- The loop keeps in-memory runtime history so follow-up actions can repair or
  extend earlier replica churn instead of choosing stateless random actions
- Only one mutation is in flight at a time, and each event must satisfy a
  topology-based postcondition before the next one can start
- `--dry-run` with no `--max-events` prints a single planned step and exits
- Requires saved managed-cluster metadata in the configured `--state-dir`

### `help`

Show top-level or command-specific help:

```bash
bin/manage-cluster help
bin/manage-cluster help start
bin/manage-cluster help fill
```

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
- Automatic compression prefers `bz2`, then `gz`, then uncompressed output
- Compressed PHARs require the matching runtime extension: `bz2` for bzip2,
  `zlib` for gzip

Run the built archive directly:

```bash
./dist/manage-cluster.phar start 7000 --replicas 1
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
