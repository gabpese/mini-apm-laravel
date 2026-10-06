<?php

use App\Models\Project;
use App\Services\RegressionDetector;

function detect(array $versions, int $minSessions = 50, float $ratio = 2.0): array
{
    $project = new Project(['regression_ratio' => $ratio, 'regression_min_sessions' => $minSessions]);
    $project->regression_ratio = (string) $ratio;
    $project->regression_min_sessions = $minSessions;

    $stats = array_map(
        fn (array $v) => ['version' => $v[0], 'sessions' => $v[1], 'crashes' => $v[2]],
        $versions,
    );

    return collect((new RegressionDetector)->detect($project, $stats))->keyBy('version')->all();
}

it('flags a version whose crash rate reaches twice the previous one', function () {
    $result = detect([['1.0.0', 200, 2], ['1.1.0', 200, 4]]); // 1% -> 2%

    expect($result['1.1.0']['regression'])->toMatchArray(['previous_version' => '1.0.0'])
        ->and($result['1.1.0']['regression']['ratio'])->toBe(2.0);
});

it('does not flag a version below the ratio', function () {
    $result = detect([['1.0.0', 200, 2], ['1.1.0', 200, 3]]); // 1% -> 1.5%

    expect($result['1.1.0']['regression'])->toBeNull();
});

it('never flags the oldest version', function () {
    expect(detect([['1.0.0', 200, 100]])['1.0.0']['regression'])->toBeNull();
});

it('does not flag a version with too few sessions', function () {
    $result = detect([['1.0.0', 200, 2], ['1.1.0', 49, 10]]);

    expect($result['1.1.0']['regression'])->toBeNull();
});

it('does not trust a previous version with too few sessions', function () {
    $result = detect([['1.0.0', 20, 0], ['1.1.0', 200, 20]]);

    expect($result['1.1.0']['regression'])->toBeNull();
});

it('compares each version with the one before it', function () {
    $result = detect([['1.0.0', 200, 2], ['1.1.0', 200, 2], ['1.2.0', 200, 10]]);

    expect($result['1.1.0']['regression'])->toBeNull()
        ->and($result['1.2.0']['regression'])->toMatchArray(['previous_version' => '1.1.0']);
});

it('orders versions as versions, not as text', function () {
    $result = detect([['1.10.0', 200, 20], ['1.9.0', 200, 2]]);

    expect(array_keys($result))->toBe(['1.9.0', '1.10.0'])
        ->and($result['1.10.0']['regression'])->not->toBeNull();
});

it('needs a few crashes to flag a version after a clean one', function () {
    expect(detect([['1.0.0', 200, 0], ['1.1.0', 200, 2]])['1.1.0']['regression'])->toBeNull();

    $flagged = detect([['1.0.0', 200, 0], ['1.1.0', 200, 3]])['1.1.0']['regression'];

    expect($flagged)->not->toBeNull()->and($flagged['ratio'])->toBeNull();
});

it('uses the thresholds of the project', function () {
    $versions = [['1.0.0', 200, 2], ['1.1.0', 200, 3]]; // 1.5x

    expect(detect($versions, ratio: 1.5)['1.1.0']['regression'])->not->toBeNull()
        ->and(detect($versions, minSessions: 500, ratio: 1.5)['1.1.0']['regression'])->toBeNull();
});

it('reports the crash rate of every version', function () {
    $result = detect([['1.0.0', 200, 5], ['1.1.0', 0, 0]]);

    expect($result['1.0.0']['crash_rate'])->toBe(0.025)
        ->and($result['1.1.0']['crash_rate'])->toBe(0.0);
});
