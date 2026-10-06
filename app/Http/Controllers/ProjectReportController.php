<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\ProjectStats;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The read-only pages of a project. All of them accept ?days=7|30|90.
 */
class ProjectReportController extends Controller
{
    public function __construct(private readonly ProjectStats $stats) {}

    public function overview(Request $request, Project $project): Response
    {
        Gate::authorize('view', $project);

        $days = $this->days($request);
        $since = $this->stats->since($days);

        return Inertia::render('projects/overview', $this->shared($project, $days) + [
            'totals' => $this->stats->totals($project, $since),
            'daily' => $this->stats->daily($project, $since),
            'features' => $this->stats->topFeatures($project, $since),
            'environment' => $this->stats->environment($project, $since),
            'regression' => $this->stats->latestRegression($this->stats->versions($project)),
            'min_ram_mb' => $project->min_ram_mb,
            'min_os' => $project->min_os,
        ]);
    }

    public function errors(Request $request, Project $project): Response
    {
        Gate::authorize('view', $project);

        $days = $this->days($request);

        return Inertia::render('projects/errors', $this->shared($project, $days) + [
            'groups' => $this->stats->errorGroups($project, $this->stats->since($days)),
        ]);
    }

    public function versions(Request $request, Project $project): Response
    {
        Gate::authorize('view', $project);

        $days = $this->days($request);
        $versions = $this->stats->versions($project);

        return Inertia::render('projects/versions', $this->shared($project, $days) + [
            'versions' => $versions,
            'adoption' => $this->stats->adoption($project, $this->stats->since($days)),
            'regression' => $this->stats->latestRegression($versions),
            'thresholds' => [
                'ratio' => (float) $project->regression_ratio,
                'min_sessions' => $project->regression_min_sessions,
            ],
        ]);
    }

    /**
     * @return array{project: array{id: int, name: string}, days: int, periods: list<int>}
     */
    private function shared(Project $project, int $days): array
    {
        return [
            'project' => ['id' => $project->id, 'name' => $project->name],
            'days' => $days,
            'periods' => ProjectStats::PERIODS,
        ];
    }

    private function days(Request $request): int
    {
        $days = $request->integer('days', ProjectStats::DEFAULT_PERIOD);

        return in_array($days, ProjectStats::PERIODS, true) ? $days : ProjectStats::DEFAULT_PERIOD;
    }
}
