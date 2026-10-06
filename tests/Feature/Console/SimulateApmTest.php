<?php

use App\Models\ApiKey;
use App\Models\Event;
use App\Models\Project;
use App\Models\User;
use App\Services\DemoDataGenerator;
use Carbon\CarbonImmutable;

function crashRate(Project $project, string $version): float
{
    $sessions = $project->appSessions()->where('app_version', $version)->count();
    $crashes = $project->events()->where('type', Event::TYPE_CRASH)->where('app_version', $version)->count();

    return $crashes / max($sessions, 1);
}

it('fails when there is no user to own the demo project', function () {
    $this->artisan('apm:simulate')->assertFailed();
});

it('creates the demo project with data and a regression in the newest version', function () {
    $user = User::factory()->create();

    $this->artisan('apm:simulate')->assertSuccessful();

    $project = $user->projects()->where('name', 'Demo App')->sole();

    expect($project->events()->count())->toBeGreaterThan(1000)
        ->and($project->errorGroups()->count())->toBeGreaterThan(0)
        ->and($project->appSessions()->distinct()->pluck('app_version')->sort()->values()->all())
        ->toBe(DemoDataGenerator::VERSIONS)
        ->and($project->appSessions()->where('app_version', '1.2.0')->count())->toBeGreaterThanOrEqual(50)
        ->and(crashRate($project, '1.2.0'))->toBeGreaterThanOrEqual(2 * crashRate($project, '1.1.0'));
});

it('creates an API key only the first time', function () {
    User::factory()->create();

    $this->artisan('apm:simulate --days=3 --users=20')->expectsOutputToContain('API key created');
    $this->artisan('apm:simulate --days=3 --users=20')->doesntExpectOutputToContain('API key created');

    expect(Project::sole()->apiKeys()->count())->toBe(1);
});

it('replaces the data when run with --fresh', function () {
    User::factory()->create();

    $this->artisan('apm:simulate --days=3 --users=20')->assertSuccessful();
    $first = Event::count();

    $this->artisan('apm:simulate --days=3 --users=20 --fresh')->assertSuccessful();

    expect(Event::count())->toBe($first);
});

it('generates the same data for the same seed', function () {
    $generator = new DemoDataGenerator;
    $until = now();

    expect($generator->generate(5, 30, 7, $until))->toBe($generator->generate(5, 30, 7, $until))
        ->and($generator->generate(5, 30, 7, $until))->not->toBe($generator->generate(5, 30, 8, $until));
});

it('never generates events after the moment it runs', function () {
    // Early in the day, so most of "today" is still ahead and would leak into the future.
    $until = now()->setTime(4, 30);

    $events = (new DemoDataGenerator)->generate(5, 40, 42, $until);

    expect($events)->not->toBeEmpty()
        ->and(collect($events)->every(fn (array $event) => CarbonImmutable::parse($event['occurred_at'])->lte($until)))->toBeTrue();
});

describe('with --api-key', function () {
    $key = 'apm_publicdemokey0123456789';

    it('registers that exact key, and it works on the API', function () use ($key) {
        User::factory()->create();

        $this->artisan("apm:simulate --days=3 --users=20 --api-key=$key")
            ->doesntExpectOutputToContain('API key created')
            ->assertSuccessful();

        $project = Project::sole();
        expect($project->apiKeys)->toHaveCount(1)
            ->and(ApiKey::findActive($key)?->project_id)->toBe($project->id);

        $this->postJson('/api/v1/events', ['events' => [[
            'type' => 'session_start',
            'occurred_at' => now()->toIso8601String(),
            'app_version' => '1.0.0',
        ]]], ['Authorization' => "Bearer $key"])->assertStatus(202);
    });

    it('does not register the key twice when run again', function () use ($key) {
        User::factory()->create();

        $this->artisan("apm:simulate --days=2 --users=10 --api-key=$key")->assertSuccessful();
        $this->artisan("apm:simulate --days=2 --users=10 --fresh --api-key=$key")->assertSuccessful();

        expect(ApiKey::count())->toBe(1);
    });

    it('rejects a key in the wrong format', function (string $bad) {
        User::factory()->create();

        $this->artisan("apm:simulate --days=2 --users=10 --api-key=$bad")->assertFailed();

        expect(Project::count())->toBe(1)->and(Event::count())->toBe(0);
    })->with(['no prefix' => 'publicdemokey0123456789012', 'too short' => 'apm_short', 'bad characters' => 'apm_publicdemo-key-0123456789']);

    it('refuses a key that belongs to another project', function () use ($key) {
        User::factory()->create();
        ApiKey::fromPlain(Project::factory()->create(), $key);

        $this->artisan("apm:simulate --days=2 --users=10 --api-key=$key")->assertFailed();

        expect(Event::count())->toBe(0);
    });
});
