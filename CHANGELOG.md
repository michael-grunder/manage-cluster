# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased
### Added
- Added `bin/manage-cluster` for starting, stopping, and rebalancing ephemeral Redis Cluster instances.
- Added cluster state tracking in `/tmp/manage-cluster` (configurable via `--state-dir`) so `--stop` can terminate whole clusters by a seed port.
- Added automatic TLS material generation for test clusters started with `--tls`.
- Added modular PHP implementation under `src/` for CLI parsing, state management, TLS generation, and Redis node orchestration.
- Added `bin/build-phar` and `composer build-phar` for producing a single executable PHAR binary.
- Added `bin/build-phar-shim` to run PHAR builds with `phar.readonly=0` using the default PHP binary.
- Added a `Makefile` with `make build-phar` for the common PHAR build flow.
- Added positional action parsing so `bin/manage-cluster start|stop|rebalance ...` works alongside the existing `--start|--stop|--rebalance` flags.
- Added automatic single-port expansion for `start`: when one seed port is provided it now expands to contiguous ports (`4` ports for replicas `0`; otherwise `3 * (replicas + 1)` ports).
- Added `status`/`--status` action that reads `CLUSTER SHARDS` and prints a compact, terminal-width-aware shard/node overview.
- Added `--watch` mode for `status` that refreshes output every second.
- Added `ClusterShardsParser` and status DTOs in `src/` for parsing PhpRedis RESP2-style alternating key/value shard data.
- Added PHPUnit coverage for shard parsing and RESP2 key/value zipping behavior.
- Added `php-tui/php-tui` dependency for terminal UI rendering.

### Changed
- Added README documentation for the new management utility and command usage.
- Added README documentation for building and running the PHAR binary.
- Updated `composer build-phar` to use the shim script and documented manual `/path/to/php -d phar.readonly=0 bin/build-phar` for advanced use.
- Updated `bin/manage-cluster` wiring and CLI help text to include status/watch options.
- Updated `status`/`--watch` rendering to use a `php-tui` table when stdout is a TTY, with a defensive plain-text fallback for non-interactive output.
- Updated status TUI rows to visually indent replicas with a `↳` prefix and shortened displayed node IDs to improve column readability.

### Deprecated
- None.

### Removed
- None.

### Fixed
- Added defensive start-time checks for occupied ports and cluster shape validation before node launch.
- Fixed status TUI column sizing so node IDs no longer run into role labels, and reduced excess spacing between the `Node` and `ID` columns.
