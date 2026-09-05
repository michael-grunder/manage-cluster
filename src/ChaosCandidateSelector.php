<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

/**
 * Picks the next chaos event out of everything the planners found eligible.
 *
 * The draw happens in two stages: first a category, then one of that category's
 * candidates. Drawing over the flat candidate list instead would hand every
 * category a share proportional to how many targets it happened to enumerate,
 * which has nothing to do with how interesting the category is. On a cluster
 * with three primaries and nine replicas, replica-kill offers one candidate per
 * healthy replica while slot-migration and primary-add each offer exactly one
 * plan, so a flat draw gives replica churn nine tickets against their one and
 * the ownership categories effectively never run.
 *
 * Within each stage the share is `weight * SCORE_BASE ** score`: one score
 * point doubles the odds, and an operator weight can outbid one, so
 * `--categories all,slot-migration:4` buys slot migration two score points. A
 * category is represented by its best candidate, since that is the best move
 * the category can offer right now.
 */
final readonly class ChaosCandidateSelector
{
    /**
     * How much one score point is worth as a multiplier on the odds.
     */
    public const float SCORE_BASE = 2.0;

    /**
     * @param non-empty-list<ChaosCandidateEvent> $candidates
     */
    public function select(array $candidates, ChaosCategorySelection $categories): ChaosCandidateEvent
    {
        // Names and weights come from the same map so the two lists the draw
        // compares position by position cannot fall out of step.
        $categoryWeights = $this->categoryDrawWeights($candidates, $categories);
        $category = $this->draw(array_keys($categoryWeights), array_values($categoryWeights));

        $withinCategory = $this->groupByCategory($candidates)[$category];

        return $this->draw($withinCategory, $this->scoreWeights($withinCategory));
    }

    /**
     * Each category's share of the first stage, keyed by category name.
     *
     * @param non-empty-list<ChaosCandidateEvent> $candidates
     *
     * @return non-empty-array<string, float>
     */
    public function categoryDrawWeights(array $candidates, ChaosCategorySelection $categories): array
    {
        // Seeded from the first candidate so the map is provably non-empty.
        $bestByCategory = [$candidates[0]->category => $candidates[0]->score];
        foreach ($candidates as $candidate) {
            $best = $bestByCategory[$candidate->category] ?? null;
            if ($best === null || $candidate->score > $best) {
                $bestByCategory[$candidate->category] = $candidate->score;
            }
        }

        $best = max($bestByCategory);

        $weights = [];
        foreach ($bestByCategory as $category => $score) {
            $weights[$category] = $categories->weightFor($category) * self::SCORE_BASE ** ($score - $best);
        }

        return $weights;
    }

    /**
     * Scores are exponentiated relative to the best candidate, which keeps
     * every weight inside `(0, 1]` however far apart the raw scores are and
     * leaves the ratios between candidates untouched.
     *
     * @param non-empty-list<ChaosCandidateEvent> $candidates
     *
     * @return non-empty-list<float>
     */
    public function scoreWeights(array $candidates): array
    {
        $best = max(array_map(
            static fn (ChaosCandidateEvent $candidate): int => $candidate->score,
            $candidates,
        ));

        return array_map(
            static fn (ChaosCandidateEvent $candidate): float => self::SCORE_BASE ** ($candidate->score - $best),
            $candidates,
        );
    }

    /**
     * @param non-empty-list<ChaosCandidateEvent> $candidates
     *
     * @return non-empty-array<string, non-empty-list<ChaosCandidateEvent>>
     */
    private function groupByCategory(array $candidates): array
    {
        $byCategory = [];
        foreach ($candidates as $candidate) {
            $byCategory[$candidate->category][] = $candidate;
        }

        return $byCategory;
    }

    /**
     * Weighted draw over a list of choices. Weights are positional, so the two
     * lists must be the same length and in the same order.
     *
     * @template T
     *
     * @param non-empty-list<T>     $choices
     * @param non-empty-list<float> $weights
     *
     * @return T
     */
    private function draw(array $choices, array $weights): mixed
    {
        $total = array_sum($weights);
        if (!is_finite($total) || $total <= 0.0) {
            return $choices[mt_rand(0, count($choices) - 1)];
        }

        $threshold = $total * (mt_rand() / mt_getrandmax());
        $cumulative = 0.0;
        foreach ($choices as $index => $choice) {
            $cumulative += $weights[$index] ?? 0.0;
            if ($threshold < $cumulative) {
                return $choice;
            }
        }

        // Only reachable when floating point accumulation lands short of the
        // draw, in which case the last choice is the right answer.
        return $choices[count($choices) - 1];
    }
}
