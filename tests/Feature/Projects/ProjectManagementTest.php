<?php

use App\Models\ApiKey;
use App\Models\AppSession;
use App\Models\Event;
use App\Models\Project;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

describe('project list', function () {
    it('lists only my projects', function () {
        $mine = Project::factory()->for($this->user)->create(['name' => 'Mine']);
        Project::factory()->create(['name' => 'Someone elses']);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('projects/index')
                ->has('projects', 1)
                ->where('projects.0.id', $mine->id)
                ->where('projects.0.name', 'Mine'));
    });

    it('summarises each project and marks a regression in the newest version', function () {
        $project = Project::factory()->for($this->user)->create();
        foreach (['1.0.0' => 1, '1.1.0' => 20] as $version => $crashes) {
            AppSession::factory()->count(100)->for($project)->create(['app_version' => $version, 'started_at' => now()->subDay()]);
            Event::factory()->crash()->count($crashes)->for($project)->create(['app_version' => $version, 'occurred_at' => now()->subDay()]);
        }

        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('projects.0.sessions', 200)
            ->where('projects.0.crashes', 21)
            ->where('projects.0.latest_version', '1.1.0')
            ->where('projects.0.regression', true));
    });

    it('requires login', function () {
        auth()->logout();

        $this->get(route('dashboard'))->assertRedirect(route('login'));
    });
});

describe('creating a project', function () {
    it('creates it with a first API key shown once', function () {
        $this->post(route('projects.store'), ['name' => 'My app', 'min_ram_mb' => 4096, 'min_os' => 'Windows 10'])
            ->assertRedirect();

        $project = $this->user->projects()->sole();
        expect($project->name)->toBe('My app')
            ->and($project->min_ram_mb)->toBe(4096)
            ->and($project->apiKeys)->toHaveCount(1);

        $this->get(route('projects.settings', $project))
            ->assertInertia(fn (Assert $page) => $page->component('projects/settings')->has('keys', 1));
    });

    it('stores only the hash of the first key', function () {
        $response = $this->post(route('projects.store'), ['name' => 'My app']);

        $project = $this->user->projects()->sole();
        $response->assertRedirect(route('projects.settings', $project));

        $key = $project->apiKeys()->sole();
        expect($key->key_hash)->toHaveLength(64)->and($key->key_prefix)->toStartWith('apm_');
    });

    it('validates the fields', function () {
        $this->post(route('projects.store'), ['name' => '', 'min_ram_mb' => 'lots'])
            ->assertSessionHasErrors(['name', 'min_ram_mb']);

        expect(Project::count())->toBe(0);
    });
});

describe('settings', function () {
    it('shows the project and its keys without any secret', function () {
        $project = Project::factory()->for($this->user)->create();
        ApiKey::generate($project, 'prod');
        $project->apiKeys()->create(['name' => 'old', 'key_prefix' => 'apm_old', 'key_hash' => str_repeat('a', 64)])->revoke();

        $this->get(route('projects.settings', $project))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('projects/settings')
                ->where('project.id', $project->id)
                ->has('keys', 2)
                ->missing('keys.0.key_hash')
                ->missing('keys.1.key_hash')
                ->where('keys.0.name', 'old') // newest first
                ->where('keys.0.revoked_at', fn ($value) => $value !== null)
                ->where('keys.1.name', 'prod')
                ->where('keys.1.revoked_at', null));
    });

    it('updates the project and its alert thresholds', function () {
        $project = Project::factory()->for($this->user)->create();

        $this->patch(route('projects.update', $project), [
            'name' => 'Renamed',
            'min_ram_mb' => 8192,
            'min_os' => null,
            'regression_ratio' => 3,
            'regression_min_sessions' => 100,
        ])->assertRedirect(route('projects.settings', $project));

        expect($project->fresh())
            ->name->toBe('Renamed')
            ->min_ram_mb->toBe(8192)
            ->min_os->toBeNull()
            ->regression_min_sessions->toBe(100);
        expect((float) $project->fresh()->regression_ratio)->toBe(3.0);
    });

    it('rejects a ratio below 1, which would flag every version', function () {
        $project = Project::factory()->for($this->user)->create();

        $this->patch(route('projects.update', $project), ['name' => 'x', 'regression_ratio' => 0.5])
            ->assertSessionHasErrors('regression_ratio');
    });

    it('deletes the project with everything in it', function () {
        $project = Project::factory()->for($this->user)->create();
        ApiKey::generate($project);

        $this->delete(route('projects.destroy', $project))->assertRedirect(route('dashboard'));

        expect(Project::count())->toBe(0)->and(ApiKey::count())->toBe(0);
    });
});

describe('API keys', function () {
    it('creates a named key', function () {
        $project = Project::factory()->for($this->user)->create();

        $this->post(route('projects.keys.store', $project), ['name' => 'staging'])
            ->assertRedirect(route('projects.settings', $project));

        expect($project->apiKeys()->sole()->name)->toBe('staging');
    });

    it('revokes a key, which then stops working', function () {
        $project = Project::factory()->for($this->user)->create();
        [$key, $plain] = ApiKey::generate($project);

        $this->delete(route('keys.destroy', $key))->assertRedirect(route('projects.settings', $project));

        expect($key->fresh()->isActive())->toBeFalse();
        $this->postJson('/api/v1/events', ['events' => []], ['Authorization' => "Bearer $plain"])->assertUnauthorized();
    });
});

describe('other peoples projects', function () {
    it('answers 404 for every page and action', function (string $method, string $route, array $data) {
        $other = Project::factory()->create();
        [$key] = ApiKey::generate($other);

        $url = route($route, str_starts_with($route, 'keys.') ? $key : $other);

        $this->call($method, $url, $data)->assertNotFound();

        expect($other->fresh())->not->toBeNull()->and($key->fresh()->isActive())->toBeTrue();
    })->with([
        'overview' => ['GET', 'projects.show', []],
        'errors' => ['GET', 'projects.errors', []],
        'versions' => ['GET', 'projects.versions', []],
        'settings' => ['GET', 'projects.settings', []],
        'update' => ['PATCH', 'projects.update', ['name' => 'hacked']],
        'delete' => ['DELETE', 'projects.destroy', []],
        'create key' => ['POST', 'projects.keys.store', []],
        'revoke key' => ['DELETE', 'keys.destroy', []],
    ]);
});
