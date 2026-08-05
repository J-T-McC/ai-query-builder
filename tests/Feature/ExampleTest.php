<?php

declare(strict_types=1);

use JTMcC\AiQueryBuilder\AiQueryBuilder;

it('resolves the singleton', function () {
    expect(app(AiQueryBuilder::class))->toBeInstanceOf(AiQueryBuilder::class);
});

it('returns the same instance from the container', function () {
    expect(app(AiQueryBuilder::class))->toBe(app(AiQueryBuilder::class));
});

it('merges the package config', function () {
    expect(config('ai-query-builder.resources'))->toBe([]);
});
