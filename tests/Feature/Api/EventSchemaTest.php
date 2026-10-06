<?php

use App\Models\ApiKey;
use App\Models\Project;
use Opis\JsonSchema\Validator;

/*
 * events.schema.json describes a batch of events. The same file, and the same
 * list of cases below, live in the Rails implementation (mini-apm-rails), so the
 * two APIs are held to one contract: for every case the schema and this API must
 * give the same verdict.
 */

function schemaEvent(array $overrides = []): array
{
    return array_merge([
        'type' => 'session_start',
        'occurred_at' => '2026-10-20T14:03:00Z',
        'app_version' => '1.2.0',
    ], $overrides);
}

function schemaBatch(array ...$events): array
{
    return ['events' => $events];
}

function schemaAccepts(array $payload): bool
{
    $schema = json_decode(file_get_contents(base_path('events.schema.json')));

    return (new Validator)->validate(json_decode(json_encode($payload)), $schema)->isValid();
}

dataset('event batches', [
    'a minimal session' => [fn () => schemaBatch(schemaEvent()), true],
    'every event type' => [fn () => schemaBatch(
        schemaEvent(['user_ref' => 'u_1', 'env' => ['os' => 'Windows 11', 'ram_mb' => 8192, 'gpu' => 'GTX 1660']]),
        schemaEvent(['type' => 'feature_used', 'name' => 'export_pdf', 'properties' => ['pages' => 3]]),
        schemaEvent(['type' => 'error', 'message' => 'Boom', 'stack' => 'app.rb:1']),
        schemaEvent(['type' => 'crash', 'message' => 'Boom']),
    ), true],
    'nulls on the optional fields' => [fn () => schemaBatch(
        schemaEvent(['user_ref' => null, 'name' => null, 'message' => null, 'stack' => null, 'properties' => null, 'env' => null]),
    ), true],
    'a date with an offset' => [fn () => schemaBatch(schemaEvent(['occurred_at' => '2026-10-20T11:03:00-03:00'])), true],
    'exactly 100 events' => [fn () => ['events' => array_fill(0, 100, schemaEvent())], true],

    'no events key' => [fn () => [], false],
    'an empty batch' => [fn () => ['events' => []], false],
    'more than 100 events' => [fn () => ['events' => array_fill(0, 101, schemaEvent())], false],
    'an unknown type' => [fn () => schemaBatch(schemaEvent(['type' => 'bogus'])), false],
    'no type' => [fn () => schemaBatch(['occurred_at' => '2026-10-20T14:03:00Z', 'app_version' => '1.0.0']), false],
    'no occurred_at' => [fn () => schemaBatch(['type' => 'session_start', 'app_version' => '1.0.0']), false],
    'no app_version' => [fn () => schemaBatch(['type' => 'session_start', 'occurred_at' => '2026-10-20T14:03:00Z']), false],
    'a version over 32 characters' => [fn () => schemaBatch(schemaEvent(['app_version' => str_repeat('1', 33)])), false],
    'a feature without a name' => [fn () => schemaBatch(schemaEvent(['type' => 'feature_used'])), false],
    'a feature with a blank name' => [fn () => schemaBatch(schemaEvent(['type' => 'feature_used', 'name' => '  '])), false],
    'a crash without a message' => [fn () => schemaBatch(schemaEvent(['type' => 'crash'])), false],
    'an error with an empty message' => [fn () => schemaBatch(schemaEvent(['type' => 'error', 'message' => ''])), false],
    'a message over 2000 characters' => [fn () => schemaBatch(schemaEvent(['type' => 'error', 'message' => str_repeat('x', 2001)])), false],
    'a negative amount of memory' => [fn () => schemaBatch(schemaEvent(['env' => ['ram_mb' => -1]])), false],
    'an environment that is not an object' => [fn () => schemaBatch(schemaEvent(['env' => 'Windows'])), false],
    'a user_ref that is not text' => [fn () => schemaBatch(schemaEvent(['user_ref' => 42])), false],
]);

it('gives the same verdict as the schema', function (Closure $payload, bool $valid) {
    $project = Project::factory()->create();
    [, $key] = ApiKey::generate($project);

    expect(schemaAccepts($payload()))->toBe($valid);

    $this->postJson('/api/v1/events', $payload(), ['Authorization' => "Bearer $key"])
        ->assertStatus($valid ? 202 : 422);
})->with('event batches');
