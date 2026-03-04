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

### Changed
- Added README documentation for the new management utility and command usage.
- Added README documentation for building and running the PHAR binary.

### Deprecated
- None.

### Removed
- None.

### Fixed
- Added defensive start-time checks for occupied ports and cluster shape validation before node launch.
