<?php

use App\Models\ErrorGroup;

it('gives the same fingerprint to the same message and location', function () {
    $a = ErrorGroup::fingerprintFor('Undefined method for nil', "app.rb:10:in `run`\napp.rb:3");
    $b = ErrorGroup::fingerprintFor('Undefined method for nil', "app.rb:10:in `run`\nother.rb:99");

    expect($a)->toBe($b)->toHaveLength(64);
});

it('gives different fingerprints to different messages or locations', function () {
    $base = ErrorGroup::fingerprintFor('Boom', 'a.rb:1');

    expect(ErrorGroup::fingerprintFor('Other', 'a.rb:1'))->not->toBe($base)
        ->and(ErrorGroup::fingerprintFor('Boom', 'b.rb:2'))->not->toBe($base)
        ->and(ErrorGroup::fingerprintFor('Boom'))->not->toBe($base);
});
