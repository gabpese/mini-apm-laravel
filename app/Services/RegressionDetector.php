<?php

namespace App\Services;

use App\Models\Project;

/**
 * Flags a version whose crash rate is much higher than the version before it.
 *
 * Crash rate = crashes / sessions of the version. A version is flagged when its
 * rate reaches `regression_ratio` times the previous one, and only when both
 * versions have at least `regression_min_sessions` sessions, so a handful of
 * sessions cannot raise a false alarm.
 *
 * When the previous version had no crashes at all the ratio is infinite, so the
 * version is flagged only if it has at least MIN_CRASHES_WHEN_PREVIOUS_IS_ZERO.
 */
class RegressionDetector
{
    public const MIN_CRASHES_WHEN_PREVIOUS_IS_ZERO = 3;

    /**
     * Returns the versions oldest first, each with its crash rate and, when flagged, the regression.
     *
     * @param  list<array{version: string, sessions: int, crashes: int}>  $versions  in any order
     * @return list<array{version: string, sessions: int, crashes: int, crash_rate: float, regression: array{previous_version: string, previous_rate: float, ratio: float|null}|null}>
     */
    public function detect(Project $project, array $versions): array
    {
        usort($versions, fn (array $a, array $b) => version_compare($a['version'], $b['version']));

        $ratio = (float) $project->regression_ratio;
        $result = [];
        $previous = null;

        foreach ($versions as $current) {
            // Cast: PHP gives the int 0, not 0.0, for 0 / 200, and the strict checks below need a float.
            $rate = $current['sessions'] > 0 ? (float) ($current['crashes'] / $current['sessions']) : 0.0;

            $result[] = $current + [
                'crash_rate' => $rate,
                'regression' => $previous === null ? null : $this->compare($project, $ratio, $previous, $current + ['crash_rate' => $rate]),
            ];

            $previous = $current + ['crash_rate' => $rate];
        }

        return $result;
    }

    /**
     * @param  array{version: string, sessions: int, crashes: int, crash_rate: float}  $previous
     * @param  array{version: string, sessions: int, crashes: int, crash_rate: float}  $current
     * @return array{previous_version: string, previous_rate: float, ratio: float|null}|null
     */
    private function compare(Project $project, float $ratio, array $previous, array $current): ?array
    {
        $min = $project->regression_min_sessions;

        if ($previous['sessions'] < $min || $current['sessions'] < $min || $current['crashes'] === 0) {
            return null;
        }

        if ($previous['crash_rate'] === 0.0) {
            $flagged = $current['crashes'] >= self::MIN_CRASHES_WHEN_PREVIOUS_IS_ZERO;
        } else {
            $flagged = $current['crash_rate'] >= $ratio * $previous['crash_rate'];
        }

        if (! $flagged) {
            return null;
        }

        return [
            'previous_version' => $previous['version'],
            'previous_rate' => $previous['crash_rate'],
            'ratio' => $previous['crash_rate'] > 0 ? $current['crash_rate'] / $previous['crash_rate'] : null,
        ];
    }
}
