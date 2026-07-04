<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

uses(Tests\TestCase::class);

it('prints a valid EvalDataset-shaped JSON payload to stdout by default', function () {
    $exitCode = Artisan::call('sentinel:export-ground-truth', ['--count' => 5]);
    $payload = json_decode(Artisan::output(), true);

    expect($exitCode)->toBe(0)
        ->and($payload)->toHaveKey('examples')
        ->and($payload['examples'])->toHaveCount(5);
});

it('shapes each example as {input, expected_label} matching the analyze-transaction tool arguments', function () {
    Artisan::call('sentinel:export-ground-truth', ['--count' => 10]);
    $payload = json_decode(Artisan::output(), true);

    foreach ($payload['examples'] as $example) {
        expect($example)->toHaveKeys(['input', 'expected_label'])
            ->and($example['input'])->toHaveKeys(['id', 'amount', 'currency', 'merchant', 'category'])
            ->and($example['expected_label'])->toBeIn(['low', 'high']);
    }
});

it('labels threat merchants high and non-threat merchants low', function () {
    config(['sentinel.simulation.merchants' => [
        ['name' => 'Safe Co', 'category' => 'grocery', 'weight' => 1, 'amount_min' => 100, 'amount_max' => 200, 'currencies' => ['CAD'], 'is_threat' => false],
    ]]);
    config(['sentinel.simulation.messages' => ['grocery' => ['Test purchase']]]);

    Artisan::call('sentinel:export-ground-truth', ['--count' => 3]);
    $payload = json_decode(Artisan::output(), true);

    foreach ($payload['examples'] as $example) {
        expect($example['expected_label'])->toBe('low');
    }
});

it('writes to the given output path instead of stdout when --output is passed', function () {
    File::shouldReceive('put')
        ->once()
        ->with('storage/app/ground-truth.json', Mockery::on(function ($contents) {
            $decoded = json_decode($contents, true);

            return isset($decoded['examples']) && count($decoded['examples']) === 4;
        }));

    $exitCode = Artisan::call('sentinel:export-ground-truth', [
        '--count' => 4,
        '--output' => 'storage/app/ground-truth.json',
    ]);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('Exported 4 ground-truth examples to storage/app/ground-truth.json');
});
