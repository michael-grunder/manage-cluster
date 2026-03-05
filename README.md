# create-cluster

`bin/manage-cluster` starts, stops, rebalances, and inspects ephemeral local Redis Cluster instances.

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

Start with TLS:

```bash
bin/manage-cluster start 7000 --replicas 1 --tls
```

Stop a cluster by any member port (stops masters and replicas):

```bash
bin/manage-cluster stop 7000
```

Rebalance a running cluster using a seed node:

```bash
bin/manage-cluster rebalance 7000
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
- For `start`, a single seed port auto-expands to contiguous ports:
  `7000..7003` for default replicas (`0`), or `3 * (replicas + 1)` ports when replicas are `>= 1`.
- TLS mode generates ephemeral CA and server cert/key material for local testing.
- `status` uses `CLUSTER SHARDS` and renders an interactive terminal table via `php-tui` (with a plain-text fallback when stdout is not a TTY).
- `--watch` is supported for `status` and refreshes the terminal once per second.
- PHAR builds require the `phar` extension and `phar.readonly=0` at build time.
