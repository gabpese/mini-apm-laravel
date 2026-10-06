<?php

use App\Models\ApiKey;
use App\Models\AppSession;
use App\Models\ErrorGroup;
use App\Models\Event;
use App\Models\Project;
use App\Models\User;

it('belongs to a user', function () {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();

    expect($project->user->is($user))->toBeTrue()
        ->and($user->projects)->toHaveCount(1);
});

it('uses the default regression thresholds', function () {
    $project = Project::factory()->create()->fresh();

    expect((float) $project->regression_ratio)->toBe(2.0)
        ->and($project->regression_min_sessions)->toBe(50);
});

it('deletes everything it owns when deleted', function () {
    $project = Project::factory()->create();
    ApiKey::generate($project);
    $session = AppSession::factory()->for($project)->create();
    $group = ErrorGroup::factory()->for($project)->create();
    Event::factory()->crash()->for($project)->create([
        'session_id' => $session->id,
        'error_group_id' => $group->id,
    ]);

    $project->delete();

    expect(ApiKey::count())->toBe(0)
        ->and(AppSession::count())->toBe(0)
        ->and(ErrorGroup::count())->toBe(0)
        ->and(Event::count())->toBe(0);
});

it('casts event payload to an array', function () {
    $event = Event::factory()->crash()->create()->fresh();

    expect($event->payload)->toBeArray()->toHaveKey('message')
        ->and($event->occurred_at)->toBeInstanceOf(DateTimeInterface::class);
});
