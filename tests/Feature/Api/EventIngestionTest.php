<?php

use App\Models\ApiKey;
use App\Models\AppSession;
use App\Models\ErrorGroup;
use App\Models\Event;
use App\Models\Project;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->project = Project::factory()->create();
    [$this->apiKey, $this->plainKey] = ApiKey::generate($this->project);
});

function ingest(string $key, array $events, array $headers = []): TestResponse
{
    return test()->postJson('/api/v1/events', ['events' => $events], ['Authorization' => 'Bearer '.$key] + $headers);
}

function sessionStart(array $overrides = []): array
{
    return array_merge([
        'type' => 'session_start',
        'occurred_at' => '2026-10-20T14:03:00Z',
        'app_version' => '1.2.0',
        'user_ref' => 'u_8f3a',
        'env' => ['os' => 'Windows 11', 'ram_mb' => 16384, 'gpu' => 'GTX 1660'],
    ], $overrides);
}

function crash(array $overrides = []): array
{
    return array_merge([
        'type' => 'crash',
        'occurred_at' => '2026-10-20T14:07:40Z',
        'app_version' => '1.2.0',
        'user_ref' => 'u_8f3a',
        'message' => 'Undefined method for nil',
        'stack' => "app.rb:10:in `run`\napp.rb:3",
    ], $overrides);
}

describe('authentication', function () {
    it('rejects a request without a key', function () {
        $this->postJson('/api/v1/events', ['events' => [sessionStart()]])
            ->assertUnauthorized();
    });

    it('rejects an unknown key', function () {
        ingest('apm_unknown', [sessionStart()])->assertUnauthorized();
    });

    it('rejects a revoked key', function () {
        $this->apiKey->revoke();

        ingest($this->plainKey, [sessionStart()])->assertUnauthorized();
        expect(Event::count())->toBe(0);
    });

    it('accepts the key in the X-API-Key header', function () {
        $this->postJson('/api/v1/events', ['events' => [sessionStart()]], ['X-API-Key' => $this->plainKey])
            ->assertStatus(202);
    });

    it('records when the key was last used', function () {
        expect($this->apiKey->last_used_at)->toBeNull();

        ingest($this->plainKey, [sessionStart()])->assertStatus(202);

        expect($this->apiKey->fresh()->last_used_at)->not->toBeNull();
    });
});

describe('validation', function () {
    it('requires at least one event', function () {
        ingest($this->plainKey, [])->assertUnprocessable()->assertJsonValidationErrors('events');
    });

    it('rejects more than 100 events in a batch', function () {
        ingest($this->plainKey, array_fill(0, 101, sessionStart()))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('events');
    });

    it('rejects an unknown event type', function () {
        ingest($this->plainKey, [sessionStart(['type' => 'bogus'])])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('events.0.type');
    });

    it('requires the common fields', function () {
        ingest($this->plainKey, [['type' => 'session_start']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['events.0.occurred_at', 'events.0.app_version']);
    });

    it('requires a name for feature_used events', function () {
        ingest($this->plainKey, [sessionStart(['type' => 'feature_used'])])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('events.0.name');
    });

    it('requires a message for error and crash events', function () {
        ingest($this->plainKey, [crash(['message' => null]), crash(['type' => 'error', 'message' => null])])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['events.0.message', 'events.1.message']);
    });

    it('stores nothing when any event in the batch is invalid', function () {
        ingest($this->plainKey, [sessionStart(), sessionStart(['type' => 'bogus'])])->assertUnprocessable();

        expect(Event::count())->toBe(0)->and(AppSession::count())->toBe(0);
    });
});

describe('storing events', function () {
    it('answers 202 and stores a session and its events', function () {
        ingest($this->plainKey, [
            sessionStart(),
            ['type' => 'feature_used', 'name' => 'export_pdf', 'occurred_at' => '2026-10-20T14:05:12Z', 'app_version' => '1.2.0', 'user_ref' => 'u_8f3a'],
            crash(),
        ])->assertStatus(202)->assertExactJson(['accepted' => 3]);

        $session = AppSession::sole();
        expect($session->project_id)->toBe($this->project->id)
            ->and($session->os)->toBe('Windows 11')
            ->and($session->ram_mb)->toBe(16384)
            ->and($session->gpu)->toBe('GTX 1660')
            ->and(Event::count())->toBe(3)
            ->and(Event::where('type', 'feature_used')->sole()->name)->toBe('export_pdf')
            ->and(Event::where('session_id', $session->id)->count())->toBe(3);
    });

    it('links events to the session even when the batch is out of order', function () {
        ingest($this->plainKey, [crash(), sessionStart()])->assertStatus(202);

        expect(Event::where('type', 'crash')->sole()->session_id)->toBe(AppSession::sole()->id);
    });

    it('does not link an event to a session of another version or user', function () {
        ingest($this->plainKey, [
            sessionStart(),
            crash(['app_version' => '1.0.0']),
            crash(['user_ref' => 'u_other']),
        ])->assertStatus(202);

        expect(Event::whereNotNull('session_id')->count())->toBe(1)
            ->and(Event::where('type', 'crash')->whereNotNull('session_id')->count())->toBe(0);
    });

    it('keeps the stack and message in the payload', function () {
        ingest($this->plainKey, [crash()])->assertStatus(202);

        expect(Event::sole()->payload)->toMatchArray([
            'message' => 'Undefined method for nil',
            'stack' => "app.rb:10:in `run`\napp.rb:3",
        ]);
    });

    it('groups equal errors and counts their occurrences', function () {
        ingest($this->plainKey, [
            crash(['occurred_at' => '2026-10-20T14:07:40Z']),
            crash(['occurred_at' => '2026-10-21T09:00:00Z', 'stack' => "app.rb:10:in `run`\nother.rb:99"]),
            crash(['message' => 'A different error']),
        ])->assertStatus(202);

        $group = ErrorGroup::where('message', 'Undefined method for nil')->sole();

        expect(ErrorGroup::count())->toBe(2)
            ->and($group->occurrences)->toBe(2)
            ->and($group->first_seen_at->toDateTimeString())->toBe('2026-10-20 14:07:40')
            ->and($group->last_seen_at->toDateTimeString())->toBe('2026-10-21 09:00:00')
            ->and(Event::where('error_group_id', $group->id)->count())->toBe(2);
    });

    it('keeps counting occurrences across batches', function () {
        ingest($this->plainKey, [crash()])->assertStatus(202);
        ingest($this->plainKey, [crash(['occurred_at' => '2026-10-19T08:00:00Z'])])->assertStatus(202);

        $group = ErrorGroup::sole();

        expect($group->occurrences)->toBe(2)
            ->and($group->first_seen_at->toDateTimeString())->toBe('2026-10-19 08:00:00');
    });

    it('stores data only for the project that owns the key', function () {
        $other = Project::factory()->create();
        [, $otherKey] = ApiKey::generate($other);

        ingest($otherKey, [crash()])->assertStatus(202);

        expect($this->project->events()->count())->toBe(0)
            ->and($other->events()->count())->toBe(1)
            ->and($other->errorGroups()->count())->toBe(1);
    });
});

describe('rate limiting', function () {
    it('answers 429 after 120 requests in a minute with the same key', function () {
        RateLimiter::clear('key:'.sha1($this->plainKey));

        foreach (range(1, 120) as $i) {
            ingest($this->plainKey, [sessionStart()])->assertStatus(202);
        }

        ingest($this->plainKey, [sessionStart()])->assertStatus(429);
    });

    it('limits invalid keys too', function () {
        foreach (range(1, 120) as $i) {
            ingest('apm_unknown', [sessionStart()])->assertUnauthorized();
        }

        ingest('apm_unknown', [sessionStart()])->assertStatus(429);
    });
});
