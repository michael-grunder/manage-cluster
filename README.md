# create-cluster

`bin/manage-cluster` starts, stops, rebalances, inspects, flushes, fills, and adds replicas to ephemeral local Redis Cluster instances.

## Requirements

- PHP 8.4+
- PhpRedis extension
- `redis-server`
- `redis-cli`
- `openssl` (only when `--tls` is used)

## Usage

Start a 9-node cluster with 2 replicas per master:

```bash
bin/manage-cluster start 7000 --replicas 2
```

Generate a standalone shell script for starting the requested cluster later:

```bash
bin/manage-cluster --gen-script start-cluster.sh start {7000..7002}
./start-cluster.sh
```

Start with TLS:

```bash
bin/manage-cluster start 7000 --replicas 1 --tls
```

Start with additional raw `redis-server`/`valkey-server` arguments passed through after `--`:

```bash
bin/manage-cluster start {7000..7002} -- --enable-debug-command local
```

Stop a cluster by any member port (stops masters and replicas):

```bash
bin/manage-cluster stop 7000
```

Rebalance a running cluster using a seed node:

```bash
bin/manage-cluster rebalance 7000
```

Add a replica to an existing primary (auto-selecting a new port outside current cluster range):

```bash
bin/manage-cluster add-replica 7000
bin/manage-cluster add-replica 7000 --port 7010
```

Flush DB data on every primary node in one or more clusters:

```bash
bin/manage-cluster flush 7000
bin/manage-cluster flush 7000 8000
```

Fill a cluster with synthetic keys until total primary `used_memory` reaches a target:

```bash
bin/manage-cluster fill --size 1g
bin/manage-cluster fill --size 5g --keys 20000
bin/manage-cluster fill 7000 --size 256m --types string,set --members 32 --member-size 2048
bin/manage-cluster fill 7000 --size 512m --pin-primary 7003
```

Inspect cluster shard/node status from a seed node:

```bash
bin/manage-cluster status 7000
```

Watch continuously (refresh every second):

```bash
bin/manage-cluster --status 7000 --watch
```

## Build A Single PHAR Binary

Build an executable PHAR archive:

```bash
bin/build-phar-shim
```

Or via make:

```bash
make build-phar
```

Or via composer:

```bash
composer build-phar
```

Or choose a custom output path:

```bash
make build-phar OUTPUT=dist/custom-name.phar
```

If you need more control (for example a specific PHP binary/version), invoke the
builder directly:

```bash
/path/to/php -d phar.readonly=0 bin/build-phar --output dist/custom-name.phar
```

Run it directly:

```bash
./dist/manage-cluster.phar start 7000 --replicas 1
```

## Notes

- Cluster state and per-node ephemeral configs/logs are kept under `/tmp/manage-cluster` by default.
- Use `--state-dir` to change where metadata and temporary cluster directories are created.
- Start validates that requested ports are not already listening before launching nodes.
- Start prints the resolved server flavor/version in a concise form such as `Redis 8.0.0 (e91a340e)` or `Valkey 8.1.0 (67c86837)`.
- For `start`, a single seed port auto-expands to contiguous ports:
  `7000..7003` for default replicas (`0`), or `3 * (replicas + 1)` ports when replicas are `>= 1`.
- `start` accepts extra raw server arguments after `--`; they are appended to every `redis-server`/`valkey-server` launch command for that cluster.
- `start --gen-script PATH` writes an executable shell script instead of starting immediately. The generated script performs preflight checks for the requested executables and ports before launching nodes, emits progress messages while it runs, and preserves the cluster directory on failure for log inspection.
- TLS mode generates ephemeral CA and server cert/key material for local testing.
- `status` uses `CLUSTER SHARDS` and renders an interactive terminal table via `php-tui` (with a plain-text fallback when stdout is not a TTY).
- `--watch` is supported for `status` and refreshes the terminal once per second.
- `flush` sends `FLUSHDB` to primary nodes only (replicas are not targeted directly).
- `add-replica` starts a new Redis node, runs `CLUSTER MEET`, then `CLUSTER REPLICATE` to attach it to the specified primary.
- Lifecycle commands such as `start`, `stop`, `rebalance`, `flush`, `fill`, and `add-replica` emit step-by-step progress; when stdout is a TTY the CLI uses ANSI color, bold labels, and rich symbols, with plain log-style output as a fallback.
- For `add-replica`, when `--port` is omitted, the tool discovers existing cluster ports and picks the first available listening-free port above the current cluster range.
- `add-replica` reads `CONFIG GET dir` from the target primary; when the node directory is under `/tmp/manage-cluster`, new node files are created in the same cluster directory.
- `fill` can run with no explicit seed port when exactly one managed cluster exists in the state store.
- `fill` supports `--size` units: raw bytes or `k|m|g|t` suffixes (optional trailing `b`), for example `1048576`, `512m`, `1gb`.
- `fill` defaults to random key generation across `string,set,list,hash,zset`; use `--types` CSV to constrain types.
- When both `--members` and `--member-size` are omitted, `fill` derives both from `--size` using a 5,000-key target by default.
- `--keys` overrides that adaptive key-count target (for example `--size 5g --keys 20000` yields smaller per-key payloads than the 5,000-key default); if either `--members` or `--member-size` is provided, those explicit values are used as-is.
- For container types (`set`, `list`, `hash`, `zset`), each key gets `--members` entries and each entry uses `max(8, ceil(--member-size / --members))` bytes.
- `fill` prints periodic progress (memory vs target, keys added, elapsed time); when stdout is a TTY it updates one line in place, otherwise it emits log-style lines.
- `--pin-primary PORT` pins generated keys to one primary by finding a matching Redis Cluster hash tag and prefixing key names with that tag.
- PHAR builds require the `phar` extension and `phar.readonly=0` at build time.
