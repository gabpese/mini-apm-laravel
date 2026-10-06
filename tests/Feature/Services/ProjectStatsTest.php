<?php

use App\Models\AppSession;
use App\Models\ErrorGroup;
use App\Models\Event;
use App\Models\Project;
use App\Services\ProjectStats;

beforeEach(function () {
    $this->project = Project::factory()->create(['min_ram_mb' => 8192, 'min_os' => 'Windows 10']);
    $this->stats = app(ProjectStats::class);
    $this->since = $this->stats->since(7);
});

function makeSession(Project $project, array $attributes = []): AppSession
{
    return AppSession::factory()->for($project)->create($attributes + [
        'user_ref' => 'u_a',
        'app_version' => '1.0.0',
        'os' => 'Windows 11',
        'ram_mb' => 16384,
        'gpu' => 'RTX 3060',
        'started_at' => now()->subDay(),
    ]);
}

function makeEvent(Project $project, string $type, array $attributes = []): Event
{
    return Event::factory()->for($project)->create($attributes + [
        'type' => $type,
        'name' => $type === 'feature_used' ? 'exportar_pdf' : null,
        'app_version' => '1.0.0',
        'occurred_at' => now()->subDay(),
    ]);
}

it('counts sessions, users, errors and crashes in the period', function () {
    makeSession($this->project);
    makeSession($this->project, ['user_ref' => 'u_a']);
    makeSession($this->project, ['user_ref' => 'u_b']);
    makeSession($this->project, ['started_at' => now()->subDays(20)]); // outside the period
    makeEvent($this->project, 'error');
    makeEvent($this->project, 'crash');
    makeEvent($this->project, 'crash', ['occurred_at' => now()->subDays(20)]);

    expect($this->stats->totals($this->project, $this->since))->toBe([
        'sessions' => 3,
        'users' => 2,
        'errors' => 1,
        'crashes' => 1,
        'crash_rate' => 1 / 3,
    ]);
});

it('does not count another project', function () {
    makeSession(Project::factory()->create());
    makeEvent(Project::factory()->create(), 'crash');

    expect($this->stats->totals($this->project, $this->since))->toMatchArray(['sessions' => 0, 'crashes' => 0, 'crash_rate' => 0.0]);
});

it('gives one row per day, with zeros on quiet days', function () {
    makeSession($this->project, ['started_at' => now()->subDays(2)]);
    makeSession($this->project, ['started_at' => now()->subDays(2)]);
    makeEvent($this->project, 'crash', ['occurred_at' => now()->subDays(2)]);
    makeEvent($this->project, 'error', ['occurred_at' => now()]);

    $daily = collect($this->stats->daily($this->project, $this->since))->keyBy('date');

    expect($daily)->toHaveCount(7)
        ->and($daily[now()->subDays(2)->toDateString()])->toMatchArray(['sessions' => 2, 'crashes' => 1, 'errors' => 0])
        ->and($daily[now()->toDateString()])->toMatchArray(['sessions' => 0, 'crashes' => 0, 'errors' => 1])
        ->and($daily[now()->subDays(5)->toDateString()])->toMatchArray(['sessions' => 0, 'crashes' => 0, 'errors' => 0]);
});

it('ranks the most used features', function () {
    foreach (['a', 'a', 'a', 'b', 'b', 'c'] as $name) {
        makeEvent($this->project, 'feature_used', ['name' => $name]);
    }
    makeEvent($this->project, 'feature_used', ['name' => 'old', 'occurred_at' => now()->subDays(30)]);

    expect($this->stats->topFeatures($this->project, $this->since, 2))->toBe([
        ['name' => 'a', 'uses' => 3],
        ['name' => 'b', 'uses' => 2],
    ]);
});

it('lists error groups by occurrences in the period', function () {
    $busy = ErrorGroup::factory()->for($this->project)->create(['message' => 'busy']);
    $quiet = ErrorGroup::factory()->for($this->project)->create(['message' => 'quiet']);
    $old = ErrorGroup::factory()->for($this->project)->create(['message' => 'old']);

    foreach (range(1, 3) as $i) {
        makeEvent($this->project, 'error', ['error_group_id' => $busy->id]);
    }
    makeEvent($this->project, 'crash', ['error_group_id' => $busy->id]);
    makeEvent($this->project, 'error', ['error_group_id' => $quiet->id]);
    makeEvent($this->project, 'error', ['error_group_id' => $old->id, 'occurred_at' => now()->subDays(30)]);

    $groups = $this->stats->errorGroups($this->project, $this->since);

    expect(array_column($groups, 'message'))->toBe(['busy', 'quiet'])
        ->and($groups[0])->toMatchArray(['occurrences' => 4, 'crashes' => 1]);
});

it('summarises versions oldest first and flags a regression', function () {
    foreach (range(1, 100) as $i) {
        makeSession($this->project, ['app_version' => '1.9.0', 'user_ref' => "u_$i"]);
        makeSession($this->project, ['app_version' => '1.10.0', 'user_ref' => "u_$i"]);
    }
    makeEvent($this->project, 'crash', ['app_version' => '1.9.0']);
    foreach (range(1, 10) as $i) {
        makeEvent($this->project, 'crash', ['app_version' => '1.10.0']);
    }

    $versions = $this->stats->versions($this->project);

    expect(array_column($versions, 'version'))->toBe(['1.9.0', '1.10.0'])
        ->and($versions[0])->toMatchArray(['sessions' => 100, 'users' => 100, 'crashes' => 1, 'regression' => null])
        ->and($versions[1]['regression'])->toMatchArray(['previous_version' => '1.9.0'])
        ->and($versions[1]['regression']['ratio'])->toBe(10.0);
});

it('shows adoption by day and version', function () {
    makeSession($this->project, ['app_version' => '1.0.0', 'started_at' => now()->subDays(3)]);
    makeSession($this->project, ['app_version' => '1.1.0', 'started_at' => now()->subDay()]);
    makeSession($this->project, ['app_version' => '1.1.0', 'started_at' => now()->subDay()]);

    $adoption = $this->stats->adoption($this->project, $this->since);
    $rows = collect($adoption['rows'])->keyBy('date');

    expect($adoption['versions'])->toBe(['1.0.0', '1.1.0'])
        ->and($rows[now()->subDays(3)->toDateString()])->toMatchArray(['1.0.0' => 1, '1.1.0' => 0])
        ->and($rows[now()->subDay()->toDateString()])->toMatchArray(['1.0.0' => 0, '1.1.0' => 2]);
});

it('finds machines below the minimum requirements', function () {
    makeSession($this->project, ['user_ref' => 'ok', 'os' => 'Windows 11', 'ram_mb' => 16384]);
    makeSession($this->project, ['user_ref' => 'ok', 'os' => 'Windows 11', 'ram_mb' => 16384]);
    makeSession($this->project, ['user_ref' => 'low_ram', 'os' => 'Windows 11', 'ram_mb' => 4096]);
    makeSession($this->project, ['user_ref' => 'old_os', 'os' => 'Windows 7', 'ram_mb' => 16384]);
    makeSession($this->project, ['user_ref' => 'both', 'os' => 'Windows 7', 'ram_mb' => 2048]);
    makeSession($this->project, ['user_ref' => 'mac', 'os' => 'macOS 14', 'ram_mb' => 16384]);

    $environment = $this->stats->environment($this->project, $this->since);

    expect($environment)->toMatchArray([
        'users' => 5 + 1 - 1, // ok, low_ram, old_os, both, mac
        'below_minimum' => 3,
        'below_ram' => 2,
        'below_os' => 2,
    ]);
});

it('groups the environment and folds the long tail into Other', function () {
    foreach (range(1, 9) as $i) {
        makeSession($this->project, ['user_ref' => "u_$i", 'gpu' => "GPU $i"]);
    }
    makeSession($this->project, ['user_ref' => 'u_1', 'gpu' => 'GPU 1']);

    $gpu = collect($this->stats->environment($this->project, $this->since)['gpu']);

    expect($gpu)->toHaveCount(9)
        ->and($gpu->last())->toBe(['label' => 'Other', 'users' => 1])
        ->and($this->stats->environment($this->project, $this->since)['ram'])->toBe([['label' => '16 GB', 'users' => 9]]);
});

it('treats a missing minimum as nothing to check', function () {
    $project = Project::factory()->create(['min_ram_mb' => null, 'min_os' => null]);
    makeSession($project, ['ram_mb' => 512, 'os' => 'Windows 7']);

    expect($this->stats->environment($project, $this->since))->toMatchArray(['below_minimum' => 0]);
});
