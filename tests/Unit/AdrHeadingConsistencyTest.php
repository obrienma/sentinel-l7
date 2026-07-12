<?php

uses(Tests\TestCase::class);

it('ADR heading number matches its filename number', function (string $path) {
    $filename = basename($path);
    preg_match('/^(\d{4})-/', $filename, $filenameMatch);
    expect($filenameMatch)->toHaveCount(2);
    $filenameNumber = $filenameMatch[1];

    $heading = collect(file($path))->first(fn (string $line) => str_starts_with(trim($line), '#'));
    expect($heading)->not->toBeNull();

    preg_match('/ADR[-\s](\d{4})\b/i', $heading, $headingMatch);
    expect($headingMatch)->toHaveCount(2);

    expect($headingMatch[1])->toBe($filenameNumber);
})->with(function () {
    foreach (glob(__DIR__.'/../../docs/adr/*.md') as $path) {
        yield basename($path) => [$path];
    }
});
