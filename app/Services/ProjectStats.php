<?php

namespace App\Services;

use App\Models\ErrorGroup;
use App\Models\Event;
use App\Models\Project;
use App\Support\OsRequirement;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The numbers shown on a project's dashboard. Everything is computed with
 * aggregate queries, so the cost does not grow with the number of events.
 */
class ProjectStats
{
    /** @var list<int> periods the dashboard offers, in days */
    public const PERIODS = [7, 30, 90];

    public const DEFAULT_PERIOD = 30;

    public function __construct(private readonly RegressionDetector $regressions) {}

    /**
     * Start of the first day of a period that ends today.
     */
    public function since(int $days): CarbonImmutable
    {
        return now()->toImmutable()->startOfDay()->subDays($days - 1);
    }

    /**
     * @return array{sessions: int, users: int, errors: int, crashes: int, crash_rate: float}
     */
    public function totals(Project $project, CarbonImmutable $since): array
    {
        $sessions = $project->appSessions()->where('started_at', '>=', $since);

        $count = $sessions->count();
        $crashes = $this->events($project, $since)->where('type', Event::TYPE_CRASH)->count();

        return [
            'sessions' => $count,
            'users' => (int) (clone $sessions)->distinct()->count('user_ref'),
            'errors' => $this->events($project, $since)->where('type', Event::TYPE_ERROR)->count(),
            'crashes' => $crashes,
            'crash_rate' => $count > 0 ? (float) ($crashes / $count) : 0.0,
        ];
    }

    /**
     * One row per day of the period, including days with no data.
     *
     * @return list<array{date: string, sessions: int, errors: int, crashes: int}>
     */
    public function daily(Project $project, CarbonImmutable $since): array
    {
        $sessions = $project->appSessions()
            ->where('started_at', '>=', $since)
            ->selectRaw('date(started_at) as day, count(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $events = $this->events($project, $since)
            ->whereIn('type', [Event::TYPE_ERROR, Event::TYPE_CRASH])
            ->selectRaw('date(occurred_at) as day, type, count(*) as total')
            ->groupBy('day', 'type')
            ->get();

        $rows = [];
        for ($date = $since; $date->lte(now()); $date = $date->addDay()) {
            $day = $date->toDateString();

            $rows[] = [
                'date' => $day,
                'sessions' => (int) ($sessions[$day] ?? 0),
                'errors' => (int) $events->where('day', $day)->where('type', Event::TYPE_ERROR)->sum('total'),
                'crashes' => (int) $events->where('day', $day)->where('type', Event::TYPE_CRASH)->sum('total'),
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{name: string, uses: int}>
     */
    public function topFeatures(Project $project, CarbonImmutable $since, int $limit = 8): array
    {
        $rows = $this->events($project, $since)
            ->where('type', Event::TYPE_FEATURE_USED)
            ->toBase()
            ->selectRaw('name, count(*) as uses')
            ->groupBy('name')
            ->orderByDesc('uses')
            ->limit($limit)
            ->get()
            ->map(fn (object $row) => ['name' => (string) $row->name, 'uses' => (int) $row->uses])
            ->all();

        return array_values($rows);
    }

    /**
     * Error groups with at least one occurrence in the period, most frequent first.
     *
     * @return list<array{id: int, message: string, occurrences: int, crashes: int, first_seen_at: string, last_seen_at: string}>
     */
    public function errorGroups(Project $project, CarbonImmutable $since, int $limit = 50): array
    {
        $groups = $project->errorGroups()
            ->withCount([
                'events as period_occurrences' => fn ($query) => $query->where('occurred_at', '>=', $since),
                'events as period_crashes' => fn ($query) => $query->where('occurred_at', '>=', $since)->where('type', Event::TYPE_CRASH),
            ])
            ->whereHas('events', fn ($query) => $query->where('occurred_at', '>=', $since))
            ->orderByDesc('period_occurrences')
            ->limit($limit)
            ->get()
            ->map(fn (ErrorGroup $group) => [
                'id' => $group->id,
                'message' => $group->message,
                'occurrences' => (int) $group->getAttribute('period_occurrences'),
                'crashes' => (int) $group->getAttribute('period_crashes'),
                'first_seen_at' => $group->first_seen_at->toIso8601String(),
                'last_seen_at' => $group->last_seen_at->toIso8601String(),
            ])
            ->all();

        return array_values($groups);
    }

    /**
     * Sessions, users and crash rate per version over the whole life of the
     * project, oldest first, with the regression alert already applied.
     *
     * @return list<array{version: string, sessions: int, users: int, crashes: int, crash_rate: float, regression: array{previous_version: string, previous_rate: float, ratio: float|null}|null}>
     */
    public function versions(Project $project): array
    {
        $sessions = $project->appSessions()
            ->toBase()
            ->selectRaw('app_version, count(*) as sessions, count(distinct user_ref) as users')
            ->groupBy('app_version')
            ->get()
            ->keyBy('app_version');

        $crashes = $project->events()
            ->where('type', Event::TYPE_CRASH)
            ->toBase()
            ->selectRaw('app_version, count(*) as total')
            ->groupBy('app_version')
            ->pluck('total', 'app_version');

        $versions = [];
        foreach ($sessions as $version => $row) {
            $versions[] = [
                'version' => (string) $version,
                'sessions' => (int) $row->sessions,
                'crashes' => (int) ($crashes[$version] ?? 0),
            ];
        }

        return array_map(
            fn (array $row) => $row + ['users' => (int) $sessions[$row['version']]->users],
            $this->regressions->detect($project, $versions),
        );
    }

    /**
     * The regression of the newest version, if it has one. Older flagged versions
     * are history: the alert is about what users run today.
     *
     * @param  list<array{version: string, sessions: int, users: int, crashes: int, crash_rate: float, regression: array{previous_version: string, previous_rate: float, ratio: float|null}|null}>  $versions
     * @return array{version: string, crash_rate: float, previous_version: string, previous_rate: float, ratio: float|null}|null
     */
    public function latestRegression(array $versions): ?array
    {
        $latest = end($versions);

        if ($latest === false || $latest['regression'] === null) {
            return null;
        }

        return ['version' => $latest['version'], 'crash_rate' => $latest['crash_rate']] + $latest['regression'];
    }

    /**
     * Sessions per day and version, to show how fast each release is adopted.
     *
     * @return array{versions: list<string>, rows: list<array<string, int|string>>}
     */
    public function adoption(Project $project, CarbonImmutable $since): array
    {
        $data = $project->appSessions()
            ->where('started_at', '>=', $since)
            ->toBase()
            ->selectRaw('date(started_at) as day, app_version, count(*) as total')
            ->groupBy('day', 'app_version')
            ->get();

        $versions = array_values($data
            ->map(fn (object $row) => (string) $row->app_version)
            ->unique()
            ->sort(fn (string $a, string $b) => version_compare($a, $b))
            ->all());

        $rows = [];
        for ($date = $since; $date->lte(now()); $date = $date->addDay()) {
            $day = $date->toDateString();
            $row = ['date' => $day];

            foreach ($versions as $version) {
                $row[$version] = (int) $data->where('day', $day)->where('app_version', $version)->sum('total');
            }

            $rows[] = $row;
        }

        return ['versions' => $versions, 'rows' => $rows];
    }

    /**
     * What the machines running the app look like, and who is under the
     * project's minimum requirements.
     *
     * @return array{
     *     users: int,
     *     below_minimum: int,
     *     below_ram: int,
     *     below_os: int,
     *     os: list<array{label: string, users: int}>,
     *     ram: list<array{label: string, users: int}>,
     *     gpu: list<array{label: string, users: int}>,
     * }
     */
    public function environment(Project $project, CarbonImmutable $since): array
    {
        $machines = $project->appSessions()
            ->where('started_at', '>=', $since)
            ->toBase()
            ->selectRaw('os, ram_mb, gpu, count(distinct user_ref) as users')
            ->groupBy('os', 'ram_mb', 'gpu')
            ->get();

        $total = $belowRam = $belowOs = $below = 0;
        $os = $ram = $gpu = [];

        foreach ($machines as $machine) {
            $users = (int) $machine->users;
            $lowRam = $project->min_ram_mb !== null && $machine->ram_mb !== null && (int) $machine->ram_mb < $project->min_ram_mb;
            $oldOs = OsRequirement::isBelow($machine->os, $project->min_os);

            $total += $users;
            $belowRam += $lowRam ? $users : 0;
            $belowOs += $oldOs ? $users : 0;
            $below += $lowRam || $oldOs ? $users : 0;

            $this->count($os, $machine->os ?? 'Unknown', $users);
            $this->count($ram, $machine->ram_mb === null ? 'Unknown' : $this->ramLabel((int) $machine->ram_mb), $users);
            $this->count($gpu, $machine->gpu ?? 'Unknown', $users);
        }

        return [
            'users' => $total,
            'below_minimum' => $below,
            'below_ram' => $belowRam,
            'below_os' => $belowOs,
            'os' => $this->distribution($os),
            'ram' => $this->distribution($ram),
            'gpu' => $this->distribution($gpu, limit: 8),
        ];
    }

    /**
     * @return Builder<Event>
     */
    private function events(Project $project, CarbonImmutable $since)
    {
        return Event::query()->where('project_id', $project->id)->where('occurred_at', '>=', $since);
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function count(array &$counts, string $label, int $users): void
    {
        $counts[$label] = ($counts[$label] ?? 0) + $users;
    }

    /**
     * The biggest groups, most users first, with the long tail folded into "Other".
     *
     * @param  array<string, int>  $counts
     * @return list<array{label: string, users: int}>
     */
    private function distribution(array $counts, int $limit = 6): array
    {
        arsort($counts);

        $rows = [];
        foreach ($counts as $label => $users) {
            $rows[] = ['label' => (string) $label, 'users' => $users];
        }

        $top = array_slice($rows, 0, $limit);
        $other = array_sum(array_column(array_slice($rows, $limit), 'users'));

        if ($other > 0) {
            $top[] = ['label' => 'Other', 'users' => $other];
        }

        return $top;
    }

    private function ramLabel(int $mb): string
    {
        return $mb >= 1024 ? round($mb / 1024).' GB' : $mb.' MB';
    }
}
