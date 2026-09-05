<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use RuntimeException;

/**
 * Identity of the running build: the declared version plus, when known, the
 * commit it was built from and when it was built.
 *
 * The declared version lives here rather than in `composer.json`, which
 * Composer asks projects to leave out so published versions stay derived from
 * VCS tags.
 */
final readonly class BuildInfo
{
    /** Declared version of the CLI; bump this when cutting a release. */
    public const string VERSION = '0.1.0';

    public function __construct(
        public string $version,
        public ?string $commit,
        public ?DateTimeImmutable $builtAt,
        public BuildInfoSource $source,
    ) {
        if (trim($version) === '') {
            throw new InvalidArgumentException('Build version must not be empty.');
        }

        if ($commit !== null && trim($commit) === '') {
            throw new InvalidArgumentException('Build commit must be null or a non-empty identifier.');
        }
    }

    /**
     * Build info as recorded by `bin/build-phar` and read back out of the archive.
     *
     * @param array<mixed> $data Decoded metadata; treated as untrusted input.
     */
    public static function fromMetadata(array $data): self
    {
        $version = $data['version'] ?? null;
        if (!is_string($version) || trim($version) === '') {
            throw new RuntimeException('Build metadata is missing a "version" string.');
        }

        $commit = $data['commit'] ?? null;
        if ($commit !== null && (!is_string($commit) || trim($commit) === '')) {
            throw new RuntimeException('Build metadata "commit" must be null or a non-empty string.');
        }

        $builtAt = $data['built_at'] ?? null;
        if ($builtAt !== null && !is_string($builtAt)) {
            throw new RuntimeException('Build metadata "built_at" must be null or an ISO 8601 string.');
        }

        return new self($version, $commit, $builtAt === null ? null : self::parseTimestamp($builtAt), BuildInfoSource::Build);
    }

    /**
     * @return array{version: string, commit: string|null, built_at: string|null}
     */
    public function toMetadataArray(): array
    {
        return [
            'version' => $this->version,
            'commit' => $this->commit,
            'built_at' => $this->builtAt?->format(DateTimeInterface::ATOM),
        ];
    }

    private static function parseTimestamp(string $value): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $value);
        if ($parsed === false) {
            throw new RuntimeException(sprintf('Build metadata "built_at" is not an ISO 8601 timestamp: %s', $value));
        }

        return $parsed;
    }
}
