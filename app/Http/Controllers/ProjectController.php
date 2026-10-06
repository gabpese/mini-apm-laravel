<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProjectRequest;
use App\Models\ApiKey;
use App\Models\Project;
use App\Services\ProjectStats;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public function __construct(private readonly ProjectStats $stats) {}

    /**
     * The user's projects, each with a small summary of the last 30 days.
     */
    public function index(Request $request): Response
    {
        $since = $this->stats->since(ProjectStats::DEFAULT_PERIOD);

        $projects = $request->user()->projects()->latest()->get()->map(function (Project $project) use ($since) {
            $totals = $this->stats->totals($project, $since);
            $versions = $this->stats->versions($project);

            return [
                'id' => $project->id,
                'name' => $project->name,
                'sessions' => $totals['sessions'],
                'crashes' => $totals['crashes'],
                'crash_rate' => $totals['crash_rate'],
                'latest_version' => $versions === [] ? null : end($versions)['version'],
                'regression' => $this->stats->latestRegression($versions) !== null,
            ];
        });

        return Inertia::render('projects/index', [
            'projects' => $projects,
            'period' => ProjectStats::DEFAULT_PERIOD,
        ]);
    }

    /**
     * Create a project and its first API key, shown once on the settings page.
     */
    public function store(ProjectRequest $request): RedirectResponse
    {
        $project = $request->user()->projects()->create($request->validated());

        [, $plain] = ApiKey::generate($project, 'Default');

        Inertia::flash('new_key', ['name' => 'Default', 'key' => $plain]);

        return to_route('projects.settings', $project);
    }

    public function edit(Project $project): Response
    {
        Gate::authorize('update', $project);

        return Inertia::render('projects/settings', [
            'project' => $this->project($project),
            // The id breaks ties between keys created in the same second.
            'keys' => $project->apiKeys()->latest()->latest('id')->get()->map(fn (ApiKey $key) => [
                'id' => $key->id,
                'name' => $key->name,
                'prefix' => $key->key_prefix,
                'last_used_at' => $key->last_used_at?->toIso8601String(),
                'revoked_at' => $key->revoked_at?->toIso8601String(),
                'created_at' => $key->created_at?->toIso8601String(),
            ]),
        ]);
    }

    public function update(ProjectRequest $request, Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);

        $project->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Project updated.')]);

        return to_route('projects.settings', $project);
    }

    public function destroy(Project $project): RedirectResponse
    {
        Gate::authorize('delete', $project);

        $project->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Project deleted.')]);

        return to_route('dashboard');
    }

    /**
     * @return array{id: int, name: string, min_ram_mb: int|null, min_os: string|null, regression_ratio: float, regression_min_sessions: int}
     */
    private function project(Project $project): array
    {
        return [
            'id' => $project->id,
            'name' => $project->name,
            'min_ram_mb' => $project->min_ram_mb,
            'min_os' => $project->min_os,
            'regression_ratio' => (float) $project->regression_ratio,
            'regression_min_sessions' => $project->regression_min_sessions,
        ];
    }
}
