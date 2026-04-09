# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased
### Added
- Added `list` action to show managed clusters that still appear to be running, including concise seed and port-range summaries from the saved state index.
- Added `restart-replica`/`--restart-replica` action with an interactive filtered tree view that shows only primaries with failed replicas and restarts the selected failed replica from its existing node config.
- Added `kill`/`--kill` action with an interactive cluster tree view for selecting and shutting down a single primary or replica from any seed node.
- Added an interactive primary-selection view for `add-replica`, so the command can start from any cluster seed instead of requiring the target primary port.
- Added GitHub Actions workflows for PHP CI on `8.4` and `8.5`, plus automated PHAR build artifacts and tagged release attachments.
- Added `phpunit.xml` so `vendor/bin/phpunit` discovers the default test suite and Composer bootstrap without extra CLI arguments.
- Added `bin/manage-cluster` for starting, stopping, and rebalancing ephemeral Redis Cluster instances.
- Added cluster state tracking in `/tmp/manage-cluster` (configurable via `--state-dir`) so `--stop` can terminate whole clusters by a seed port.
- Added automatic TLS material generation for test clusters started with `--tls`.
- Added `start --gen-script PATH` to emit an executable shell script that performs preflight checks, starts the requested Redis nodes, creates the cluster, and preserves logs/state when startup fails.
- Added modular PHP implementation under `src/` for CLI parsing, state management, TLS generation, and Redis node orchestration.
- Added `bin/build-phar` and `composer build-phar` for producing a single executable PHAR binary.
- Added `bin/build-phar-shim` to run PHAR builds with `phar.readonly=0` using the default PHP binary.
- Added a `Makefile` with `make build-phar` for the common PHAR build flow.
- Added positional action parsing so `bin/manage-cluster start|stop|rebalance ...` works alongside the existing `--start|--stop|--rebalance` flags.
- Added automatic single-port expansion for `start`: when one seed port is provided it now expands to contiguous ports (`4` ports for replicas `0`; otherwise `3 * (replicas + 1)` ports).
- Added `status`/`--status` action that reads `CLUSTER SHARDS` and prints a compact, terminal-width-aware shard/node overview.
- Added `--watch` mode for `status` that refreshes output every second.
- Added `flush`/`--flush` action that sends `FLUSHDB` to each primary node in the specified cluster(s).
- Added `fill`/`--fill` action that populates keys until cluster primary memory usage reaches `--size`, with optional key type/member knobs and `--pin-primary` support.
- Added periodic progress output for `fill`, including memory usage vs target, keys added, and time remaining estimates, with single-line TTY refresh and log-style non-TTY output.
- Added `ClusterShardsParser` and status DTOs in `src/` for parsing PhpRedis RESP2-style alternating key/value shard data.
- Added PHPUnit coverage for shard parsing and RESP2 key/value zipping behavior.
- Added PHPUnit coverage for command line parsing of the `flush` action.
- Added `php-tui/php-tui` dependency for terminal UI rendering.
- Added `add-replica`/`--add-replica` action to start a new node and attach it to a specified primary with `CLUSTER MEET` + `CLUSTER REPLICATE`, including optional `--port` override.
- Added `start` support for passing arbitrary raw `redis-server`/`valkey-server` arguments after `--`, applying them to each started node.

### Changed
- Updated `status` so `bin/manage-cluster status` with no seed port now summarizes all managed clusters discovered in the configured state directory, including `--watch` refreshes for that overview.
- Updated `status`/`--watch` output to drop the redundant `Role` column by default and show per-node used memory, with `-` for unreachable nodes.
- Updated `status --watch` TUI rendering to use a fullscreen boxed layout with unicode borders and a full-frame repaint on each refresh.
- Reworked `README.md` into a release-ready guide with installation, quick
  start, command reference, state/TLS notes, PHAR build instructions, and
  development checks ahead of the planned `v0.1.0` release.
- Updated `bin/build-phar` to exclude dev-only Composer packages from PHAR builds, trim vendor payloads to runtime files, and support `--compression auto|none|gz|bz2` with automatic compressed builds by default.
- Updated README PHAR build documentation with the new compression controls and runtime extension requirements for compressed archives.
- Updated the default `bin/manage-cluster` help screen to a more concise command-oriented layout with aligned command summaries, examples, and command-specific `help` output.
- Updated underspecified command failures to print command-specific help with focused usage examples instead of always falling back to the generic CLI help text.
- Updated README and CLI help text for the new `restart-replica` action.
- Updated README and CLI help text for the new `kill` command and the interactive `add-replica` flow.
- Added README documentation for the new management utility and command usage.
- Added README documentation for building and running the PHAR binary.
- Updated `composer build-phar` to use the shim script and documented manual `/path/to/php -d phar.readonly=0 bin/build-phar` for advanced use.
- Updated `bin/build-phar` to emit timestamped progress messages while scanning files, adding archive contents, writing the stub, and finalizing the PHAR.
- Updated `bin/manage-cluster` wiring and CLI help text to include status/watch options.
- Updated `bin/manage-cluster` wiring and CLI help text to include fill options and usage examples.
- Updated lifecycle command output to report step-by-step progress, with rich ANSI colors/symbols on TTYs and plain log-style fallbacks otherwise.
- Updated `start` output to show the resolved Redis/Valkey server flavor and version in a concise `Name x.y.z (sha)` form before node launch.
- Updated README with `fill` examples, defaults, memory-size units, and primary pinning behavior.
- Updated `fill` default sizing so when both `--members` and `--member-size` are omitted it derives larger per-key payloads from `--size` using a 5,000-key target.
- Updated `fill` sizing to accept `--keys` as an adaptive key-count target, influencing derived `--members` and `--member-size` when both are omitted.
- Updated `fill` progress lines to a compact format: `[HH:MM:SS XX%] used/target, N keys`.
- Updated `fill` progress timestamps to show an ETA-style countdown after enough progress has been observed, instead of elapsed time.
- Updated `status`/`--watch` rendering to use a `php-tui` table when stdout is a TTY, with a defensive plain-text fallback for non-interactive output.
- Updated status TUI rows to visually indent replicas with a `↳` prefix and shortened displayed node IDs to improve column readability.
- Updated replica provisioning to inspect `CONFIG GET dir` on the primary node and reuse the same `/tmp/manage-cluster/...` cluster directory when applicable.

### Deprecated
- None.

### Removed
- None.

### Fixed
- Added defensive start-time checks for occupied ports and cluster shape validation before node launch.
- Fixed `--binary`/`--redis-cli` explicit filesystem paths so existing executable files are accepted instead of being rejected by command-name lookup.
- Fixed status TUI column sizing so node IDs no longer run into role labels, and reduced excess spacing between the `Node` and `ID` columns.
- Fixed status TUI column widths for `ID`, `Role`, `Slots`, and `Offset` so adjacent values always have visible separation.
- Fixed `CLUSTER SHARDS` parsing to preserve full node IDs instead of truncating them during parsing.
- Fixed redundant type checks in `RedisNodeClient::discoverClusterPorts()` flagged by PHPStan (`function.alreadyNarrowedType`).
