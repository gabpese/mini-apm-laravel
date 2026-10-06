<?php

use App\Models\AppSession;
use App\Models\ErrorGroup;
use App\Models\Event;
use App\Models\Project;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->for($this->user)->create(['min_ram_mb' => 8192, 'min_os' => 'Windows 10']);
    $this->actingAs($this->user);
});

it('shows the overview with totals, a daily series, features and environment', function () {
    AppSession::factory()->count(3)->for($this->project)->create(['started_at' => now()->subDay(), 'ram_mb' => 4096]);
    Event::factory()->count(2)->for($this->project)->create(['name' => 'export_pdf', 'occurred_at' => now()->subDay()]);

    $this->get(route('projects.show', $this->project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/overview')
            ->where('project.name', $this->project->name)
            ->where('days', 30)
            ->where('periods', [7, 30, 90])
            ->where('totals.sessions', 3)
            ->has('daily', 30)
            ->has('features', 1)
            ->where('features.0.uses', 2)
            ->has('environment.os')
            ->where('regression', null)
            ->where('min_ram_mb', 8192));
});

it('limits the overview to the chosen period', function () {
    AppSession::factory()->for($this->project)->create(['started_at' => now()->subDays(2)]);
    AppSession::factory()->for($this->project)->create(['started_at' => now()->subDays(20)]);

    $this->get(route('projects.show', [$this->project, 'days' => 7]))
        ->assertInertia(fn (Assert $page) => $page->where('days', 7)->where('totals.sessions', 1)->has('daily', 7));

    $this->get(route('projects.show', [$this->project, 'days' => 90]))
        ->assertInertia(fn (Assert $page) => $page->where('totals.sessions', 2));
});

it('falls back to 30 days for an unsupported period', function () {
    $this->get(route('projects.show', [$this->project, 'days' => 5]))
        ->assertInertia(fn (Assert $page) => $page->where('days', 30));
});

it('shows the regression of the newest version on the overview and the versions page', function () {
    foreach (['1.0.0' => 1, '1.1.0' => 20] as $version => $crashes) {
        AppSession::factory()->count(100)->for($this->project)->create(['app_version' => $version, 'started_at' => now()->subDay()]);
        Event::factory()->crash()->count($crashes)->for($this->project)->create(['app_version' => $version, 'occurred_at' => now()->subDay()]);
    }

    $this->get(route('projects.show', $this->project))
        ->assertInertia(fn (Assert $page) => $page
            ->where('regression.version', '1.1.0')
            ->where('regression.previous_version', '1.0.0')
            ->where('regression.ratio', 20));

    $this->get(route('projects.versions', $this->project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/versions')
            ->has('versions', 2)
            ->where('versions.0.version', '1.0.0')
            ->where('versions.0.regression', null)
            ->where('versions.1.regression.previous_version', '1.0.0')
            ->where('regression.version', '1.1.0')
            ->where('thresholds', ['ratio' => 2, 'min_sessions' => 50])
            ->has('adoption.rows', 30)
            ->where('adoption.versions', ['1.0.0', '1.1.0']));
});

it('does not alert about a regression that the newest version already fixed', function () {
    foreach (['1.0.0' => 1, '1.1.0' => 20, '1.2.0' => 1] as $version => $crashes) {
        AppSession::factory()->count(100)->for($this->project)->create(['app_version' => $version, 'started_at' => now()->subDay()]);
        Event::factory()->crash()->count($crashes)->for($this->project)->create(['app_version' => $version, 'occurred_at' => now()->subDay()]);
    }

    $this->get(route('projects.versions', $this->project))
        ->assertInertia(fn (Assert $page) => $page
            ->where('regression', null)
            ->where('versions.1.regression.previous_version', '1.0.0')); // still shown as history
});

it('lists the error groups of the period', function () {
    $group = ErrorGroup::factory()->for($this->project)->create(['message' => 'Boom']);
    Event::factory()->count(2)->for($this->project)->create(['type' => 'error', 'name' => null, 'error_group_id' => $group->id, 'occurred_at' => now()->subDay()]);

    $this->get(route('projects.errors', $this->project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/errors')
            ->has('groups', 1)
            ->where('groups.0.message', 'Boom')
            ->where('groups.0.occurrences', 2));
});

it('requires login for the reports', function (string $route) {
    auth()->logout();

    $this->get(route($route, $this->project))->assertRedirect(route('login'));
})->with(['projects.show', 'projects.errors', 'projects.versions']);
