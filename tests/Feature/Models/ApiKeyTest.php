<?php

use App\Models\ApiKey;
use App\Models\Project;

it('generates a key and stores only its hash', function () {
    $project = Project::factory()->create();

    [$apiKey, $plain] = ApiKey::generate($project, 'production');

    expect($plain)->toStartWith('apm_')->toHaveLength(44)
        ->and($apiKey->key_prefix)->toBe(substr($plain, 0, 12))
        ->and($apiKey->key_hash)->toBe(hash('sha256', $plain))
        ->and($apiKey->key_hash)->not->toBe($plain)
        ->and($apiKey->project->is($project))->toBeTrue();
});

it('never serializes the hash', function () {
    [$apiKey] = ApiKey::generate(Project::factory()->create());

    expect($apiKey->toArray())->not->toHaveKey('key_hash');
});

it('finds an active key by its plain text', function () {
    [$apiKey, $plain] = ApiKey::generate(Project::factory()->create());

    expect(ApiKey::findActive($plain)?->is($apiKey))->toBeTrue()
        ->and(ApiKey::findActive('apm_wrong'))->toBeNull();
});

it('does not find a revoked key', function () {
    [$apiKey, $plain] = ApiKey::generate(Project::factory()->create());

    $apiKey->revoke();

    expect($apiKey->fresh()->isActive())->toBeFalse()
        ->and(ApiKey::findActive($plain))->toBeNull();
});
