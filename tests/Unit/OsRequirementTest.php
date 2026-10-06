<?php

use App\Support\OsRequirement;

it('finds an older version of the same OS', function (string $os, string $minimum, bool $below) {
    expect(OsRequirement::isBelow($os, $minimum))->toBe($below);
})->with([
    'older windows' => ['Windows 7', 'Windows 10', true],
    'same windows' => ['Windows 10', 'Windows 10', false],
    'newer windows' => ['Windows 11', 'Windows 10', false],
    'compares numbers, not text' => ['Windows 9', 'Windows 10', true],
    'older ubuntu' => ['Ubuntu 20.04', 'Ubuntu 22.04', true],
    'newer ubuntu' => ['Ubuntu 24.04', 'Ubuntu 22.04', false],
    'ignores case' => ['windows 7', 'Windows 10', true],
]);

it('never calls a different OS family below the minimum', function () {
    expect(OsRequirement::isBelow('macOS 14', 'Windows 10'))->toBeFalse()
        ->and(OsRequirement::isBelow('Linux', 'Windows 10'))->toBeFalse();
});

it('ignores missing or unreadable values', function () {
    expect(OsRequirement::isBelow(null, 'Windows 10'))->toBeFalse()
        ->and(OsRequirement::isBelow('Windows 7', null))->toBeFalse()
        ->and(OsRequirement::isBelow('Windows 7', 'whatever'))->toBeFalse()
        ->and(OsRequirement::isBelow('', ''))->toBeFalse();
});
