<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

use InvalidArgumentException;

/**
 * The set of chaos event categories a run may pick from, together with the
 * relative weight the operator gave each one. A weight of 1.0 is neutral, 3.0
 * makes a category three times as likely to win a tie, and 0.5 halves it.
 */
final readonly class ChaosCategorySelection
{
    public const float DEFAULT_WEIGHT = 1.0;

    /**
     * @var array<string, float>
     */
    public array $weightByCategory;

    /**
     * @param array<string, float> $weightByCategory
     */
    public function __construct(array $weightByCategory)
    {
        $validated = [];
        foreach ($weightByCategory as $category => $weight) {
            if (!in_array($category, ChaosOptions::SUPPORTED_CATEGORIES, true)) {
                throw new InvalidArgumentException(sprintf('Unsupported chaos category: %s', $category));
            }

            if (!is_finite($weight) || $weight <= 0.0) {
                throw new InvalidArgumentException(sprintf(
                    'Chaos category weight for %s must be a finite number greater than 0.',
                    $category,
                ));
            }

            $validated[$category] = $weight;
        }

        $this->weightByCategory = $validated;
    }

    /**
     * @param list<string> $categories
     */
    public static function fromCategories(array $categories, float $weight = self::DEFAULT_WEIGHT): self
    {
        $weightByCategory = [];
        foreach ($categories as $category) {
            $weightByCategory[$category] = $weight;
        }

        return new self($weightByCategory);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->weightByCategory);
    }

    public function isEmpty(): bool
    {
        return $this->weightByCategory === [];
    }

    public function has(string $category): bool
    {
        return array_key_exists($category, $this->weightByCategory);
    }

    /**
     * Categories that were never enabled still answer with the neutral weight,
     * so callers can weigh a candidate without first checking membership.
     */
    public function weightFor(string $category): float
    {
        return $this->weightByCategory[$category] ?? self::DEFAULT_WEIGHT;
    }

    /**
     * @param list<string> $categories
     *
     * @return list<string>
     */
    public function intersect(array $categories): array
    {
        return array_values(array_filter($categories, fn (string $category): bool => $this->has($category)));
    }

    /**
     * @param list<string> $categories
     */
    public function hasAny(array $categories): bool
    {
        return $this->intersect($categories) !== [];
    }

    /**
     * Add a category without disturbing a weight it was already given, which is
     * what `--allow-<category>` needs when `--categories` already weighted it.
     */
    public function with(string $category, ?float $weight = null): self
    {
        if ($weight === null && $this->has($category)) {
            return $this;
        }

        $weightByCategory = $this->weightByCategory;
        $weightByCategory[$category] = $weight ?? self::DEFAULT_WEIGHT;

        return new self($weightByCategory);
    }

    /**
     * One `--categories` token per entry, with neutral weights left implicit,
     * for callers that list the selection vertically rather than on one line.
     *
     * @return list<string>
     */
    public function tokens(): array
    {
        $tokens = [];
        foreach ($this->weightByCategory as $category => $weight) {
            $tokens[] = $weight === self::DEFAULT_WEIGHT
                ? $category
                : sprintf('%s:%s', $category, self::formatWeight($weight));
        }

        return $tokens;
    }

    /**
     * Render back into `--categories` syntax, leaving neutral weights implicit.
     */
    public function describe(): string
    {
        return implode(',', $this->tokens());
    }

    /**
     * Keep weights readable in help and watch output: `3` rather than `3.000`,
     * `1.5` rather than `1.500`.
     */
    public static function formatWeight(float $weight): string
    {
        $formatted = rtrim(rtrim(sprintf('%.4F', $weight), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }
}
