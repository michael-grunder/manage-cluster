# create-cluster

`bin/manage-cluster` starts, stops, and rebalances ephemeral local Redis Cluster instances.

## Requirements

- PHP 8.4+
- PhpRedis extension
- `redis-server`
- `redis-cli`
- `openssl` (only when `--tls` is used)

## Usage

Start a 9-node cluster with 3 replicas per master:

```bash
bin/manage-cluster --start {7000..7008} --replicas 3
```

Start with TLS:

```bash
bin/manage-cluster --start {7000..7005} --replicas 1 --tls
```

Stop a cluster by any member port (stops masters and replicas):

```bash
bin/manage-cluster --stop 7000
```

Rebalance a running cluster using a seed node:

```bash
bin/manage-cluster --rebalance 7000
```

## Build A Single PHAR Binary

Build an executable PHAR archive:

```bash
composer build-phar
```

Or choose a custom output path:

```bash
php -d phar.readonly=0 bin/build-phar --output dist/custom-name.phar
```

Run it directly:

```bash
./dist/manage-cluster.phar --start {7000..7005} --replicas 1
```

## Notes

- Cluster state and per-node ephemeral configs/logs are kept under `/tmp/manage-cluster` by default.
- Use `--state-dir` to change where metadata and temporary cluster directories are created.
- Start validates that requested ports are not already listening before launching nodes.
- TLS mode generates ephemeral CA and server cert/key material for local testing.
- PHAR builds require the `phar` extension with `phar.readonly=0` at build time.
