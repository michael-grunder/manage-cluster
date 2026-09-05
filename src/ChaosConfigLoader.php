<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

use InvalidArgumentException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads a chaos run profile from a YAML file into a {@see ChaosConfig}.
 *
 * The file names the same knobs as the chaos command line options, minus the
 * leading dashes, so a profile reads like the invocation it replaces. Every
 * value is validated here rather than at the point of use: a run that will be
 * left churning a cluster for hours should fail on a typo immediately, and with
 * a message that names the file and the key.
 *
 * Categories must be enumerated. Unlike `--categories`, the `all` alias is not
 * accepted, because a file that outlives the version of chaos that wrote it
 * would silently pick up categories its author never considered.
 */
final readonly class ChaosConfigLoader
{
    public const string KEY_CATEGORIES = 'categories';
    public const string KEY_INTERVAL = 'interval';
    public const string KEY_MAX_EVENTS = 'max-events';
    public const string KEY_MAX_FAILURES = 'max-failures';
    public const string KEY_ABORT_ON_FAILURE = 'abort-on-failure';
    public const string KEY_DRY_RUN = 'dry-run';
    public const string KEY_WATCH = 'watch';
    public const string KEY_SEED = 'seed';
    public const string KEY_WAIT_TIMEOUT = 'wait-timeout';
    public const string KEY_COOLDOWN = 'cooldown';
    public const string KEY_UNSAFE = 'unsafe';
    public const string KEY_SLOT_STRATEGY = 'slot-strategy';
    public const string KEY_SLOT_BATCH = 'slot-batch';

    /**
     * @var list<string>
     */
    public const array SUPPORTED_KEYS = [
        self::KEY_CATEGORIES,
        self::KEY_INTERVAL,
        self::KEY_MAX_EVENTS,
        self::KEY_MAX_FAILURES,
        self::KEY_ABORT_ON_FAILURE,
        self::KEY_DRY_RUN,
        self::KEY_WATCH,
        self::KEY_SEED,
        self::KEY_WAIT_TIMEOUT,
        self::KEY_COOLDOWN,
        self::KEY_UNSAFE,
        self::KEY_SLOT_STRATEGY,
        self::KEY_SLOT_BATCH,
    ];

    public function load(string $path): ChaosConfig
    {
        return $this->parse($this->readDocument($path), $path);
    }

    /**
     * @return array<string, mixed>
     */
    private function readDocument(string $path): array
    {
        if (!is_file($path)) {
            throw new InvalidArgumentException(sprintf('Chaos config file not found: %s', $path));
        }

        if (!is_readable($path)) {
            throw new InvalidArgumentException(sprintf('Chaos config file is not readable: %s', $path));
        }

        try {
            $document = Yaml::parseFile($path);
        } catch (ParseException $exception) {
            throw new InvalidArgumentException(
                sprintf('Chaos config file %s is not valid YAML: %s', $path, $exception->getMessage()),
                previous: $exception,
            );
        }

        if (!is_array($document) || array_is_list($document)) {
            throw new InvalidArgumentException(sprintf(
                'Chaos config file %s must contain a YAML mapping of settings, such as "%s:".',
                $path,
                self::KEY_CATEGORIES,
            ));
        }

        $settings = [];
        foreach ($document as $key => $value) {
            if (!is_string($key)) {
                throw new InvalidArgumentException(sprintf(
                    'Chaos config file %s has a non-string setting name: %s',
                    $path,
                    var_export($key, true),
                ));
            }

            $settings[$key] = $value;
        }

        return $settings;
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function parse(array $settings, string $path): ChaosConfig
    {
        foreach (array_keys($settings) as $key) {
            if (!in_array($key, self::SUPPORTED_KEYS, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Unsupported chaos config setting "%s" in %s. Supported settings: %s.',
                    $key,
                    $path,
                    implode(', ', self::SUPPORTED_KEYS),
                ));
            }
        }

        $categories = array_key_exists(self::KEY_CATEGORIES, $settings)
            ? $this->parseCategories($settings[self::KEY_CATEGORIES], $path)
            : null;

        $strategy = null;
        if (array_key_exists(self::KEY_SLOT_STRATEGY, $settings)) {
            $strategy = SlotMigrationStrategy::parse(
                $this->stringValue($settings[self::KEY_SLOT_STRATEGY], self::KEY_SLOT_STRATEGY, $path),
            );
        }

        return new ChaosConfig(
            categories: $categories,
            intervalSeconds: $this->intValue($settings, self::KEY_INTERVAL, $path, minimum: 1),
            maxEvents: $this->intValue($settings, self::KEY_MAX_EVENTS, $path, minimum: 1),
            maxFailures: $this->intValue($settings, self::KEY_MAX_FAILURES, $path, minimum: 0),
            abortOnFailure: $this->boolValue($settings, self::KEY_ABORT_ON_FAILURE, $path),
            dryRun: $this->boolValue($settings, self::KEY_DRY_RUN, $path),
            watch: $this->boolValue($settings, self::KEY_WATCH, $path),
            seed: $this->intValue($settings, self::KEY_SEED, $path),
            waitTimeoutSeconds: $this->intValue($settings, self::KEY_WAIT_TIMEOUT, $path, minimum: 1),
            cooldownSeconds: $this->intValue($settings, self::KEY_COOLDOWN, $path, minimum: 0),
            unsafe: $this->boolValue($settings, self::KEY_UNSAFE, $path),
            slotMigrationStrategy: $strategy,
            slotMigrationBatch: $this->intValue(
                $settings,
                self::KEY_SLOT_BATCH,
                $path,
                minimum: 1,
                maximum: SlotRange::TOTAL_SLOTS,
            ),
        );
    }

    /**
     * Categories are written either as a mapping of name to weight, where a
     * null weight means the neutral 1.0, or as a plain sequence of names when
     * no weights are needed.
     */
    private function parseCategories(mixed $value, string $path): ChaosCategorySelection
    {
        if (!is_array($value) || $value === []) {
            throw new InvalidArgumentException(sprintf(
                'Chaos config setting "%s" in %s must list at least one category, as a sequence of names or a mapping of name to weight.',
                self::KEY_CATEGORIES,
                $path,
            ));
        }

        $weightByCategory = [];
        foreach ($value as $key => $weight) {
            $category = is_string($key) ? $key : $weight;
            if (!is_string($category)) {
                throw new InvalidArgumentException(sprintf(
                    'Chaos config setting "%s" in %s has a category name that is not a string: %s',
                    self::KEY_CATEGORIES,
                    $path,
                    var_export($category, true),
                ));
            }

            $category = strtolower(trim($category));
            if ($category === ChaosOptions::CATEGORY_ALIAS_ALL) {
                throw new InvalidArgumentException(sprintf(
                    'Chaos config setting "%s" in %s must enumerate categories explicitly; the "%s" alias is only accepted by --categories.',
                    self::KEY_CATEGORIES,
                    $path,
                    ChaosOptions::CATEGORY_ALIAS_ALL,
                ));
            }

            if (!in_array($category, ChaosOptions::SUPPORTED_CATEGORIES, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Unsupported chaos category "%s" in %s. Supported categories: %s.',
                    $category,
                    $path,
                    implode(', ', ChaosOptions::SUPPORTED_CATEGORIES),
                ));
            }

            if (array_key_exists($category, $weightByCategory)) {
                throw new InvalidArgumentException(sprintf(
                    'Chaos category "%s" is listed more than once in %s.',
                    $category,
                    $path,
                ));
            }

            $weightByCategory[$category] = is_string($key)
                ? $this->parseWeight($weight, $category, $path)
                : ChaosCategorySelection::DEFAULT_WEIGHT;
        }

        return new ChaosCategorySelection($weightByCategory);
    }

    private function parseWeight(mixed $weight, string $category, string $path): float
    {
        // "slot-migration:" with nothing after it is a category without a
        // weight, which is the neutral weight rather than an error.
        if ($weight === null) {
            return ChaosCategorySelection::DEFAULT_WEIGHT;
        }

        if (!is_int($weight) && !is_float($weight)) {
            throw new InvalidArgumentException(sprintf(
                'Chaos category weight for "%s" in %s must be a number greater than 0, got: %s',
                $category,
                $path,
                var_export($weight, true),
            ));
        }

        $weight = (float) $weight;
        if (!is_finite($weight) || $weight <= 0.0) {
            throw new InvalidArgumentException(sprintf(
                'Chaos category weight for "%s" in %s must be a finite number greater than 0, got: %s',
                $category,
                $path,
                ChaosCategorySelection::formatWeight($weight),
            ));
        }

        return $weight;
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function intValue(array $settings, string $key, string $path, ?int $minimum = null, ?int $maximum = null): ?int
    {
        if (!array_key_exists($key, $settings)) {
            return null;
        }

        $value = $settings[$key];
        if (!is_int($value)) {
            throw new InvalidArgumentException(sprintf(
                'Chaos config setting "%s" in %s must be an integer, got: %s',
                $key,
                $path,
                var_export($value, true),
            ));
        }

        if ($minimum !== null && $value < $minimum) {
            throw new InvalidArgumentException(sprintf(
                'Chaos config setting "%s" in %s must be >= %d, got %d.',
                $key,
                $path,
                $minimum,
                $value,
            ));
        }

        if ($maximum !== null && $value > $maximum) {
            throw new InvalidArgumentException(sprintf(
                'Chaos config setting "%s" in %s must be <= %d, got %d.',
                $key,
                $path,
                $maximum,
                $value,
            ));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function boolValue(array $settings, string $key, string $path): ?bool
    {
        if (!array_key_exists($key, $settings)) {
            return null;
        }

        $value = $settings[$key];
        if (!is_bool($value)) {
            throw new InvalidArgumentException(sprintf(
                'Chaos config setting "%s" in %s must be true or false, got: %s',
                $key,
                $path,
                var_export($value, true),
            ));
        }

        return $value;
    }

    private function stringValue(mixed $value, string $key, string $path): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf(
                'Chaos config setting "%s" in %s must be a string, got: %s',
                $key,
                $path,
                var_export($value, true),
            ));
        }

        return $value;
    }
}
