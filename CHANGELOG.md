# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased
### Added
- `chaos --config-file PATH` reads chaos settings from a YAML file, so a soak
  profile can be checked in instead of retyped. The file names the same settings
  as the options without the leading dashes (`categories`, `interval`,
  `max-events`, `max-failures`, `abort-on-failure`, `dry-run`, `watch`, `seed`,
  `wait-timeout`, `cooldown`, `unsafe`, `slot-strategy`, `slot-batch`), and
  unknown settings, wrong types, and out-of-range values are reported by name
  before the run starts. Options given on the command line override the file,
  and `--allow-<category>` flags add to whatever it listed. `categories` is a
  mapping of name to weight or a plain list of names, and must be enumerated:
  the `all` alias stays exclusive to `--categories` so a saved profile cannot
  silently pick up categories a later release adds. `chaos.dist.yml` in the
  repository root documents every setting.
- `chaos --watch` now opens a fullscreen `php-tui` dashboard: live cluster
  topology with slot ranges, slot counts, and per-shard replica health on top,
  and a scrolling chaos event log underneath. `j`/`k`, `Up`/`Down`, `PgUp`, and
  `PgDn` scroll the log, `f` follows the newest entries, and `q`, `Esc`, or
  `Ctrl-C` stops the run. Without a TTY on stdin and stdout, `--watch` keeps
  printing the line-by-line log, so redirecting to a file still works.
- `chaos --categories all` enables every event category without enumerating
  them, and `all` can also be mixed into a comma-separated list.
- `chaos --categories` entries accept a `:WEIGHT` suffix that biases how often
  a category is chosen, as in `--categories all,slot-migration:3` or
  `--categories replica-kill:0.5`. Weights default to `1`, accept integers and
  decimals, may be attached to `all`, and are preserved for categories that are
  also enabled with `--allow-<category>`. `chaos --watch` shows the weights next
  to the category list.
- `chaos` now prints the categories it may pick from when the run starts, one
  per line under a `Chaos categories (N):` header and in `--categories` token
  syntax so any non-default weight is visible, such as `replica-kill:0.5`. The
  list is vertical so it stays readable at any terminal width, rather than being
  truncated by the `--watch` log or wrapped mid-token without it. With `--watch`
  each line becomes its own event log entry, and the summary header keeps
  showing the comma-separated list.
- `chaos --abort-on-failure` stops the run at the first failed event, for
  scripted runs that want a failure to be fatal.
- An opt-in integration suite, `vendor/bin/phpunit --testsuite integration`,
  drives each destructive chaos category against a real cluster and asserts the
  topology it leaves behind. It provisions a throwaway cluster on a free port
  range by default, or attaches to an existing one through
  `MANAGE_CLUSTER_IT_PORTS`. `vendor/bin/phpunit` still runs the unit suite
  only.

### Fixed
- `chaos --categories` weights now change which event is chosen instead of only
  breaking ties. Selection previously shortlisted the highest-scoring candidates
  and applied the weights inside that shortlist, so `--categories
  all,slot-migration:4` had no effect whenever slot migration scored below the
  leader. The draw now covers every eligible candidate, each score point doubles
  a candidate's share, and a category weight can outbid a score.
- `chaos --categories all` no longer gets stuck repeating `primary-failover`
  (or `primary-add`/`primary-remove`) for a whole run. A candidate was only
  deprioritised when the immediately preceding event hit the same target, so a
  two-shard cluster could alternate shards and never trip the check, while a
  "restores a primary chaos demoted earlier" bonus applied to every later
  failback for the rest of the run. Repetition is now damped across the last
  several events, and the role-reversal and layout-restoring bonuses only count
  the most recent event that touched the node.
- `chaos --categories primary-failover,replica-reparent,primary-add,primary-remove`
  now runs those events instead of aborting after `--max-failures` with
  "<category> is missing a ... plan". The chosen event kept only slot-migration
  plans, so every other planned category lost its plan before execution.
- `chaos --categories primary-add` no longer fails with "CLUSTER SETSLOT <slot>
  NODE failed on Redis node at port N". The new primary was announced to every
  reachable primary as soon as the donor had met it, so any node that had not
  yet learned the new node ID through gossip rejected the slot handover. Every
  node that gets told about the new owner is now waited for first.
- `chaos` now reloads managed cluster metadata before each event, so ports added
  or removed during a run are tracked instead of the run acting on the port list
  it started with.
- `chaos --categories slot-migration` now waits for unreachable, failed,
  loading, or syncing nodes to settle instead of exhausting `--max-failures`
  after five one-second eligibility checks. With `--watch`, the wait message
  identifies the exact blocking conditions and ports.

### Changed
- `chaos` no longer gives up after five consecutive failures. A failed event is
  now reported in red and the run continues, so one ineligible or broken event
  does not end a long soak. `--max-failures` still sets a ceiling but defaults
  to unlimited, and `--abort-on-failure` restores stop-on-first-failure.
- `chaos` now reports why it has nothing to do. A run that can never plan an
  event, such as `--categories replica-reparent` against a cluster whose
  primaries each have a single replica, prints the blocking reason instead of
  waiting silently until it is interrupted.
- `chaos --watch` now routes step and progress messages, including slot
  migration progress, into the event log instead of printing them over the
  live view.
- `help chaos` now documents the default event categories
  (`replica-kill,replica-restart,replica-add`) and lists every category
  `--categories` accepts, including `all`.
- Command help now widens the option column to fit the longest option name, so
  descriptions stay aligned for commands with long options such as
  `chaos --allow-primary-failover`.
- Help, usage, and error output now name the command as it was invoked instead
  of always printing `bin/manage-cluster`. A PHAR or script resolved through
  `PATH` prints `manage-cluster`, a command inside the current directory prints
  a relative path such as `bin/manage-cluster` or `./manage-cluster.phar`, and
  any other command prints the path as given, so printed examples stay
  copy-pasteable.
- `chaos --allow-slot-migration` now adds `slot-migration` to the allowed event
  categories instead of being an accepted no-op.
- `chaos` no longer refuses to start when `--categories` names only
  `slot-migration`.
- `status` now prints every slot range a primary owns as a comma-separated list
  and sizes the slot column to fit, so ownership fragmented by slot migration
  stays readable and aligned. A primary that owns no slots is still listed,
  with `[-]` in the slot column, instead of disappearing from the topology.

### Added
- Added a `version` command, also available as `-v` / `--version`, that prints
  the version, the commit it was built from, the build time, and the PHP version
  in use. A built PHAR reports the commit and UTC build time recorded by
  `composer build-phar`; a source checkout reports the commit `git` sees,
  suffixed with `-dirty` when the working tree has uncommitted changes.
- `composer build-phar` now embeds `build-info.json` with the version, commit,
  and UTC build time of the archive.
- Added `chaos --categories primary-add` and `chaos --categories primary-remove`
  (also enabled with `chaos --allow-primary-add` / `--allow-primary-remove`),
  which grow and shrink the primary inventory as multi-step events.
  `primary-add` starts and `CLUSTER MEET`s an empty managed primary, then
  migrates up to `--slot-batch` slots into it from the primary that owns the
  most. `primary-remove` reattaches the departing primary's replicas, drains
  every slot it owns to the remaining primaries, sends `CLUSTER FORGET` to every
  remaining node, stops the process, and drops the port from the cluster
  metadata. Both need at least three reachable slot-owning primaries, a removal
  must leave three behind, the seed port is never removed, and the cluster will
  not grow past six primaries.
- Added `chaos --categories replica-reparent` (also enabled with
  `chaos --allow-replica-reparent`), which moves a live replica to a different
  primary with `CLUSTER REPLICATE`. The replica keeps its process, port, and
  node ID, so a client that cached the old shard's replica list still reaches a
  server that answers normally but no longer belongs there. A move is only
  chosen when the replica is attached with its link up, the donor keeps another
  healthy replica (unless `--unsafe`), and the recipient is a different
  reachable primary that owns slots. The event completes only when the same node
  ID is listed by the new primary, the donor has dropped it, and its replication
  link to the new primary is up.
- Added `chaos --categories primary-failover` (also enabled with
  `chaos --allow-primary-failover`), which promotes a caught-up replica over its
  own primary with a coordinated `CLUSTER FAILOVER` and waits until the promoted
  node owns every slot the old primary had and the old primary has
  resynchronized as its replica. A shard is only chosen when the primary is
  managed, reachable, and owns slots, the replica is attached with its
  replication link up and within 1 MiB of the primary's offset, and a majority
  of slot-owning primaries is reachable to authorize the promotion. A failover
  that does not converge fails the event and is never escalated to
  `CLUSTER FAILOVER FORCE` or `TAKEOVER`. Unlike `slot-migration`, failover can
  run alongside replica churn, and `--watch` reports the promotion, demotion,
  and slot handover as they land.
- Added a `chaos` client stress report reviewing current coverage and proposing
  failover, replica reassignment, staged migration, and recovery improvements.
- Added working `chaos --categories slot-migration`, which moves a bounded batch
  of slots between primaries with `CLUSTER SETSLOT` and `MIGRATE` and waits until
  the destination owns every migrated slot with no open migration state.
- Added `chaos --slot-strategy balanced|random`. `balanced` (the default) is
  weighted so primaries owning more slots give them up and primaries owning
  fewer receive them, never drains its source, and never moves slots toward the
  heavier primary, keeping the cluster roughly even over a long run. `random`
  ignores the distribution and hands a randomly sized, randomly positioned
  window of one primary's slots to another, deliberately producing fragmented
  and lopsided topologies.
- Added `chaos --slot-batch N` to bound how many slots a single slot-migration
  event moves (default: 16).
- Added `chaos --seed N` coverage for slot planning, so a seeded run reproduces
  both event selection and the slots each migration moves.
- Added an interactive `php-tui` overview for `status --watch` without a seed
  port, allowing Up/Down selection of a running managed cluster and Enter to
  open that cluster's `status PORT --watch` view.
- Added `completions bash|zsh` to generate shell completion scripts for the
  CLI.
- Added `kill --method METHOD` with `shutdown`, `nosave`, and selected
  process-signal methods for deliberately crashing managed Redis nodes.
- Added persistence for `start -- NAME VALUE` Redis config directive pairs by
  writing them into each generated `redis.conf` while still passing them to the
  started server processes.
- Added repeated `restart-replica --config NAME=VALUE` overrides that run
  `CONFIG SET` after restart and persist the result with `CONFIG REWRITE`.
- Added `--replica PORT` to `kill` and `restart-replica` for noninteractive
  targeting of a specific existing replica, with topology-aware validation
  messages that list valid replicas grouped by primary.
- Added `--primary PORT` to `kill --replica` and `restart-replica --replica`
  to reject a replica unless it belongs to the expected primary.
- Added `--all` to `kill` and `restart-replica` so all replicas, or all
  replicas under `--primary PORT`, can be stopped or restarted in one command.
- Added `--wait` to `kill` and `restart-replica` so replica operations can block
  until Redis cluster state reports each target as down or healthy.
- Added replication offset progress to `restart-replica --wait` output while
  Redis cluster state is converging.
- Added `start --primaries N` to choose the primary count for new clusters, with single-port expansion now using `primaries * (replicas + 1)` and defaulting to 3 primaries.
- Added concurrent Redis node launch and batch readiness waiting for `start`, reducing startup time for larger local clusters.
- Added `chaos`/`--chaos` action for serialized, stateful replica churn, with
  conservative v1 support for `replica-kill`, `replica-restart`, and
  `replica-add`, plus watch/dry-run/event-budget controls.
- Added explicit chaos runtime DTOs for cluster snapshots, node/primary state,
  candidate events, and in-memory event history used to plan follow-up actions
  safely.
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
- Restructured `AGENTS.md` with a repository map, a copy-paste command
  reference, per-class ownership boundaries, PHPStan/PHPUnit expectations, test
  conventions, and a definition-of-done checklist.
- Reworked `AGENTS.md` into scoped agent guidance covering repository layout,
  architecture, PHP style, verification, and documentation expectations.
- Updated `fill --size` parsing to accept decimal amounts such as `2.5g` and
  `18.5m`.
- Changed generated node configs to set `cluster-allow-replica-migration no` by
  default so repeated replica kill/restart operations keep replicas attached to
  their original primaries unless explicitly overridden.
- Changed `restart-replica --all` to start all selected failed replica
  processes before waiting, allowing parallel replica resynchronization.
- Made `stop` output more concise by collapsing sequential port lists and grouping identical SHUTDOWN warnings across ports.
- Updated `start` output to collapse sequential port lists into compact ranges, reusing the same shared port-range formatter as `stop`.
- Updated `stop` to issue Redis shutdown commands in parallel and then wait for all targeted nodes to exit as a batch.
- Reworked `stop` so hung `redis-cli SHUTDOWN` calls time out quickly and managed nodes that remain up are forced down via escalating process signals instead of stalling the full stop operation.
- Updated `status --watch` to render an independently probed per-node latency column, using background async probes so latency checks do not block TUI frame rendering.
- Updated the CLI parser, help text, and README command reference to include the
  new `chaos` command and document its current v1 safety limits.
- Updated PHAR build documentation to use `composer build-phar` as the supported build entry point instead of `make build-phar`.
- Updated dependency management to track `composer.lock`, making CI and release PHAR builds reproducible instead of resolving floating package versions on each run.
- Updated cluster node columns in `status` views and interactive selectors to collapse loopback-only host:port values down to just the port when every discovered node is local.
- Updated `status` so `bin/manage-cluster status` with no seed port now summarizes all managed clusters discovered in the configured state directory, including `--watch` refreshes for that overview.
- Updated `status`/`--watch` output to drop the redundant `Role` column by default and show per-node used memory, with `-` for unreachable nodes.
- Updated `status --watch` TUI rendering to use a fullscreen boxed layout with unicode borders and a full-frame repaint on each refresh.
- Updated `status --watch` TUI headers to remove the extra metadata rows and show a concise `Cluster Status [watch]` title with a right-aligned clock in the border.
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
- Removed the redundant `Makefile`; PHAR builds now go through `composer build-phar`.

### Fixed
- Fixed current PHPStan findings around redundant parser/manager checks and TLS
  metadata return-shape narrowing.
- Fixed PHPStan max-level issues by validating decoded JSON/Redis data, narrowing
  resource and reflection helper types, and declaring non-empty fill key types.
- Fixed replica runtime config changes being lost across `kill` plus
  `restart-replica` by attempting `CONFIG REWRITE` before shutdown.
- Fixed `bin/build-phar` so `composer build-phar` works after `composer install --no-dev` even when Composer omits optional metadata files such as `vendor/composer/autoload_files.php`.
- Added defensive start-time checks for occupied ports and cluster shape validation before node launch.
- Fixed the initial `status --watch` draw so fullscreen TUI mode clears stale terminal content before rendering the first frame.
- Fixed fullscreen `status --watch` rendering to avoid clearing the entire terminal before every redraw, which caused visible frame flashing.
- Fixed `--binary`/`--redis-cli` explicit filesystem paths so existing executable files are accepted instead of being rejected by command-name lookup.
- Fixed status TUI column sizing so node IDs no longer run into role labels, and reduced excess spacing between the `Node` and `ID` columns.
- Fixed status TUI column widths for `ID`, `Role`, `Slots`, and `Offset` so adjacent values always have visible separation.
- Fixed `status --watch` table sizing to cap the `Node` column to actual content width so long replication offsets can use available terminal space instead of bleeding into `Memory`.
- Fixed status TUI table rendering to keep `Offset` content inside its column by using fixed content-width columns plus explicit inter-column spacing.
- Fixed `CLUSTER SHARDS` parsing to preserve full node IDs instead of truncating them during parsing.
- Fixed redundant type checks in `RedisNodeClient::discoverClusterPorts()` flagged by PHPStan (`function.alreadyNarrowedType`).
