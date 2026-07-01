# Agent Instructions

## Scope

These instructions apply to the whole repository. Keep this file focused on
durable project guidance: repository shape, coding standards, verification
steps, and documentation expectations.

## Project Snapshot

`manage-cluster` is a PHP 8.4+ CLI for managing local Redis or Valkey Cluster
instances used in development and testing. The main executable is
`bin/manage-cluster`; implementation code lives in `src/`; PHPUnit tests live
in `tests/`.

Composer dependencies are intentional project choices. Prefer idiomatic,
maintained Composer packages when they fit the problem better than custom code,
but avoid adding heavy dependencies for small or isolated tasks.

## Architecture

Preserve the existing modular design. New behavior should live behind cohesive
classes, value objects, parsers, renderers, or orchestration methods that match
the surrounding code instead of scattered feature-specific conditionals.

When a feature pressures the existing design, prefer a clean redesign over
tacking special handling onto unrelated modules. Keep CLI parsing, Redis
process orchestration, state storage, rendering, and test-support behavior in
their existing ownership boundaries unless the change explicitly requires
moving those boundaries.

Wrapping `phpredis` in helper classes is fine, but do not dispatch Redis methods
through `__call`, especially methods that take references such as `scan`.
Expose explicit wrapper methods so signatures and reference behavior stay clear
to PHPStan and readers.

## PHP Style

Use modern PHP 8.4+ syntax and idioms. Prefer typed properties, constructor
promotion, `readonly` where useful, enums for closed sets, strict value objects,
and clear return types.

Favor generic, reusable code over duplication unless there is a concrete
performance or readability reason to keep the code local.

Code defensively. Check return values from operations that can fail, validate
external data from Redis, the filesystem, JSON, subprocesses, and user input,
and surface actionable errors instead of silently continuing.

## Tests and Verification

After changing PHP source, make sure changed files compile. Useful checks are:

```bash
php -l bin/manage-cluster
php -l src/*.php
vendor/bin/phpstan analyze
vendor/bin/phpunit
```

Run the smallest relevant checks while iterating, then run the broader PHPStan
and PHPUnit checks before handing off when the change touches source code. Fix
reported issues rather than documenting them away.

For non-PHP files, use the appropriate project or ecosystem checker when one is
available, such as `bash -n` for shell scripts or a JS/TS linter if JavaScript
or TypeScript is introduced.

## Documentation

Update `README.md` when a change affects documented behavior, commands,
options, setup, examples, or release/build instructions.

Keep `CHANGELOG.md` updated for every repository-visible change. Add entries
under `## Unreleased` and group them with Keep a Changelog section headings:
`### Added`, `### Changed`, `### Deprecated`, `### Removed`, and `### Fixed`.

Documentation-only changes do not require PHP compilation checks, but still
review the rendered Markdown structure for clarity and broken formatting.
