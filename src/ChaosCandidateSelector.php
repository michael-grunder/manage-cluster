<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

/**
 * Picks the next chaos event out of everything the planners found eligible.
 *
 * The draw covers every candidate rather than a shortlist of the best-scoring
 * ones. Each candidate's share of the draw is
 * `categoryWeight * SCORE_BASE ** score`, so a score point still doubles a
 * candidate's odds while an operator weight can outbid one:
 * `--categories all,slot-migration:4` buys slot migration two score points.
 *
 * Covering the whole list matters as much as the weighting. Shortlisting the
 * top scorers lets a category that keeps scoring itself back to the top starve
 * every other category for the rest of a run, and no `--categories` weight can
 * rescue a candidate that was cut before the weights were applied.
 */
final readonly class ChaosCandidateSelector
{
    /**
     * How much one score point is worth as a multiplier on a candidate's odds.
     */
    public const float SCORE_BASE = 2.0;

    /**
     * @param non-empty-list<ChaosCandidateEvent> $candidates
     */
    public function select(array $candidates, ChaosCategorySelection $categories): ChaosCandidateEvent
    {
        $weights = $this->drawWeights($candidates, $categories);

        $total = array_sum($weights);
        if (!is_finite($total) || $total <= 0.0) {
            return $candidates[mt_rand(0, count($candidates) - 1)];
        }

        $threshold = $total * (mt_rand() / mt_getrandmax());
        $cumulative = 0.0;
        foreach ($candidates as $index => $candidate) {
            $cumulative += $weights[$index];
            if ($threshold < $cumulative) {
                return $candidate;
            }
        }

        // Only reachable when floating point accumulation lands short of the
        // draw, in which case the last candidate is the right answer.
        return $candidates[count($candidates) - 1];
    }

    /**
     * Scores are exponentiated relative to the best candidate, which keeps
     * every weight inside `(0, categoryWeight]` however far apart the raw
     * scores are and leaves the ratios between candidates untouched.
     *
     * @param non-empty-list<ChaosCandidateEvent> $candidates
     *
     * @return non-empty-list<float>
     */
    public function drawWeights(array $candidates, ChaosCategorySelection $categories): array
    {
        $best = max(array_map(
            static fn (ChaosCandidateEvent $candidate): int => $candidate->score,
            $candidates,
        ));

        return array_map(
            static fn (ChaosCandidateEvent $candidate): float => $categories->weightFor($candidate->category)
                * self::SCORE_BASE ** ($candidate->score - $best),
            $candidates,
        );
    }
}
