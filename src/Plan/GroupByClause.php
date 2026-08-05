<?php

declare(strict_types=1);

namespace JTMcC\AiQueryBuilder\Plan;

use JTMcC\AiQueryBuilder\Schema\ColumnDefinition;
use JTMcC\AiQueryBuilder\Schema\Enums\DateBucket;

final readonly class GroupByClause
{
    public function __construct(
        public string $path,
        public ColumnDefinition $column,
        public ?DateBucket $bucket = null,
    ) {}

    /** @return array{column: string, bucket?: string} */
    public function toArray(): array
    {
        $clause = ['column' => $this->path];

        if ($this->bucket !== null) {
            $clause['bucket'] = $this->bucket->value;
        }

        return $clause;
    }
}
