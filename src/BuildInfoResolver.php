<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

use Closure;
use JsonException;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Resolves the running build's identity.
 *
 * A built PHAR carries `build-info.json` written by `bin/build-phar`, so it can
 * report the exact commit and build time. A source checkout has no such file
 * and is described from the working tree instead.
 */
final readonly class BuildInfoResolver
{
    public const string METADATA_FILE = 'build-info.json';

    private const float GIT_TIMEOUT_SECONDS = 5.0;

    /**
     * @param string $metadataPath Embedded metadata written at build time; absent in a checkout.
     * @param (Closure(): ?string)|null $commitReader Describes the checkout's commit; defaults to a `git` lookup.
     */
    public function __construct(
        private string $metadataPath,
        private ?Closure $commitReader = null,
    ) {
    }

    /**
     * Resolver for the build this code is running from, whether a PHAR or a checkout.
     */
    public static function forRunningBuild(): self
    {
        return new self(sprintf('%s/%s', dirname(__DIR__), self::METADATA_FILE));
    }

    public function resolve(): BuildInfo
    {
        if (is_file($this->metadataPath)) {
            return BuildInfo::fromMetadata($this->readMetadata());
        }

        $commitReader = $this->commitReader ?? static fn (): ?string => self::describeWorkingTree(dirname(__DIR__));

        return new BuildInfo(BuildInfo::VERSION, $commitReader(), null, BuildInfoSource::Checkout);
    }

    /**
     * Short commit for a working tree, suffixed with `-dirty` when it has
     * uncommitted changes, or null when git cannot describe it.
     */
    public static function describeWorkingTree(string $directory): ?string
    {
        $commit = self::git($directory, ['rev-parse', '--short', 'HEAD']);
        if ($commit === null) {
            return null;
        }

        $status = self::git($directory, ['status', '--porcelain']);

        return $status === null || $status === '' ? $commit : $commit . '-dirty';
    }

    /**
     * @param list<string> $arguments
     */
    private static function git(string $directory, array $arguments): ?string
    {
        $process = new Process(['git', '-C', $directory, ...$arguments]);
        $process->setTimeout(self::GIT_TIMEOUT_SECONDS);

        try {
            $process->run();
        } catch (Throwable) {
            return null;
        }

        if (!$process->isSuccessful()) {
            return null;
        }

        $output = trim($process->getOutput());

        return $output === '' ? null : $output;
    }

    /**
     * @return array<mixed>
     */
    private function readMetadata(): array
    {
        $contents = file_get_contents($this->metadataPath);
        if ($contents === false) {
            throw new RuntimeException(sprintf('Failed to read build metadata: %s', $this->metadataPath));
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(sprintf('Build metadata is not valid JSON (%s): %s', $this->metadataPath, $e->getMessage()), previous: $e);
        }

        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf('Build metadata must be a JSON object: %s', $this->metadataPath));
        }

        return $decoded;
    }
}
