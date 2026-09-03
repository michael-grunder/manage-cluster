# Agent Instructions

## Scope

These instructions apply to the whole repository. `CLAUDE.md` is a symlink to
this file, so edit `AGENTS.md` only. Keep this file focused on durable project
guidance: repository shape, coding standards, verification steps, and
documentation expectations. Do not record task-specific notes, changelog
entries, or transient status here.

## Project Snapshot

`manage-cluster` is a PHP 8.4+ CLI for managing local Redis or Valkey Cluster
instances used in development and testing. It starts, stops, kills, fills,
rebalances, and monitors ephemeral clusters, and includes a `chaos` mode for
deliberate topology churn.

| Path | Contents |
| --- | --- |
| `bin/manage-cluster` | Entry point: parses `$argv`, wires dependencies, dispatches one action |
| `bin/build-phar`, `bin/build-phar-shim` | PHAR builder and its `phar.readonly=0` wrapper |
| `src/` | All implementation, PSR-4 under `Mgrunder\CreateCluster\` |
| `tests/` | PHPUnit tests, namespace `Mgrunder\CreateCluster\Tests` |
| `chaos.md` | Design spec for the `chaos` command |
| `project.yml` | Experimental `swoole/typephp` native build config; not part of normal verification |

Composer dependencies are intentional project choices. Prefer idiomatic,
maintained Composer packages when they fit the problem better than custom code,
but avoid adding heavy dependencies for small or isolated tasks. Runtime
dependencies today are `symfony/process` and `php-tui/php-tui`.

## Commands

```bash
composer install                # dev dependencies, needed for the checks below
php -l bin/manage-cluster       # syntax check a changed file
vendor/bin/phpstan analyze      # level max over src/, bin/, tests/
vendor/bin/phpunit              # full suite
vendor/bin/phpunit --filter ClusterShardsParser   # while iterating
composer build-phar             # writes dist/manage-cluster.phar
```

## Architecture

Preserve the existing modular design. New behavior should live behind cohesive
classes, value objects, parsers, renderers, or orchestration methods that match
the surrounding code instead of scattered feature-specific conditionals.

Keep the established ownership boundaries unless the change explicitly requires
moving them:

- `CommandLineParser` / `CommandLineOptions` own argument parsing, usage text,
  and validation. `bin/manage-cluster` stays a thin wiring and dispatch layer.
- `ClusterManager` orchestrates actions; it should not reimplement parsing,
  rendering, or state persistence inline.
- `ClusterStateStore` owns the on-disk state directory (`/tmp/manage-cluster`
  by default, overridable with `--state-dir`).
- `RedisNodeClient`, `StartScriptGenerator`, and `TlsMaterialGenerator` own
  Redis connections, generated start scripts, and TLS material.
- `*Parser` classes turn raw Redis output into typed values; `*Renderer`
  classes turn typed values into output. Plain and `php-tui` renderers are
  separate classes, and neither should acquire orchestration logic.
- `ShellCompletionGenerator` mirrors the CLI surface; when you add or rename a
  command or option, update it and its test.

When a feature pressures the existing design, prefer a clean redesign over
tacking special handling onto unrelated modules.

Wrapping `phpredis` in helper classes is fine, but do not dispatch Redis methods
through `__call`, especially methods that take references such as `scan`.
Expose explicit wrapper methods so signatures and reference behavior stay clear
to PHPStan and readers.

## PHP Style

Use modern PHP 8.4+ syntax and idioms. Prefer typed properties, constructor
promotion, `readonly` where useful, enums for closed sets, strict value objects,
and clear return types. Every file declares `strict_types=1`, and classes are
`final` unless there is a reason to extend them. Use named arguments when
calling constructors with many parameters, as `bin/manage-cluster` does.

Favor generic, reusable code over duplication unless there is a concrete
performance or readability reason to keep the code local.

Code defensively. Check return values from operations that can fail, validate
external data from Redis, the filesystem, JSON, subprocesses, and user input,
and surface actionable errors instead of silently continuing. Throw
`InvalidArgumentException` for bad user input and `RuntimeException` for failed
operations; `bin/manage-cluster` converts both into a message and exit code 1.

PHPStan runs at level `max` with `bleedingEdge`, so annotate array shapes and
list types (`@param list<int>`, `@return iterable<string, array{...}>`) rather
than leaving `array` unspecified. Fix reported issues instead of adding
`@phpstan-ignore` or baseline entries.

## Tests and Verification

Tests are unit tests and must run without a live Redis server, without opening
network sockets, and without leaving files outside a temporary directory they
clean up. PHPUnit is configured with `failOnRisky`, `failOnWarning`, and
`failOnPhpunitDeprecation`, so warnings and risky tests fail the suite.

Conventions to follow in `tests/`:

- One `final class <Subject>Test extends TestCase` per class under test.
- `#[DataProvider]` with a `static` provider returning `iterable`, keyed by a
  descriptive case name, for table-driven cases.
- `#[RequiresFunction]` to skip tests that depend on optional platform
  functions.
- Prefer injecting fakes through constructors and `php://temp` streams over
  reflection or global state.

After changing PHP source, make sure changed files compile, run the smallest
relevant checks while iterating, then run the full PHPStan and PHPUnit checks
before handing off. CI runs both on PHP 8.4 and 8.5, so keep changes compatible
with both.

For non-PHP files, use the appropriate project or ecosystem checker when one is
available, such as `bash -n` for shell scripts or a JS/TS linter if JavaScript
or TypeScript is introduced.

## Documentation

Update `README.md` when a change affects documented behavior, commands,
options, setup, examples, or release/build instructions.

Keep `CHANGELOG.md` updated for every repository-visible change. Add entries
under `## Unreleased` and group them with Keep a Changelog section headings:
`### Added`, `### Changed`, `### Deprecated`, `### Removed`, and `### Fixed`.
Write entries from the user's point of view and name the command or option
involved.

Documentation-only changes do not require PHP compilation checks, but still
review the rendered Markdown structure for clarity and broken formatting.

## Definition of Done

1. Behavior implemented in the module that owns it.
2. Tests added or updated for new behavior and edge cases.
3. `vendor/bin/phpstan analyze` and `vendor/bin/phpunit` both pass.
4. `README.md` updated if documented behavior changed.
5. `CHANGELOG.md` updated under `## Unreleased`.
