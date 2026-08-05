<?php

declare(strict_types=1);

namespace JTMcC\AiQueryBuilder\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * Scaffolds a query schema from an existing model and its table.
 *
 * The output is a draft with every column commented out. That is the point:
 * exposing a column to an AI agent should be a deliberate edit, not something
 * that happens because a generator guessed. A generator that emitted a working
 * permissive schema would make "secure by default" a documentation claim rather
 * than a property of the code.
 *
 * Columns whose names suggest secrets are not scaffolded at all, only counted in
 * a note, so an uncommenting spree cannot expose them.
 */
final class MakeSchemaCommand extends Command
{
    protected $signature = 'ai-query:make-schema
        {model : The Eloquent model class, e.g. "App\\Models\\Invoice"}
        {--namespace=App\\AiQueries : Namespace for the generated class}
        {--path= : Directory to write to, defaults to app/AiQueries}
        {--force : Overwrite an existing file}';

    protected $description = 'Scaffold a query schema draft from an Eloquent model';

    public function handle(Repository $config): int
    {
        $input = $this->argument('model');

        $class = is_string($input) ? $this->resolveModel($input) : null;

        if ($class === null) {
            $this->components->error('Could not resolve that argument to an Eloquent model.');

            return self::FAILURE;
        }

        $model = new $class;
        $table = $model->getTable();

        if (! Schema::hasTable($table)) {
            $this->components->error("The table [{$table}] does not exist.");

            return self::FAILURE;
        }

        /** @var list<string> $sensitive */
        $sensitive = $config->get('ai-query-builder.generator.sensitive_columns', []);

        $path = $this->targetPath($class);

        if (file_exists($path) && ! $this->option('force')) {
            $this->components->error("[{$path}] already exists. Pass --force to overwrite.");

            return self::FAILURE;
        }

        $contents = $this->render($class, $model, $table, $sensitive);

        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($path, $contents);

        $this->components->info("Schema draft written to [{$path}].");
        $this->components->warn('Every column is commented out. Uncomment only what an agent should reach.');

        return self::SUCCESS;
    }

    /**
     * @return class-string<Model>|null
     */
    private function resolveModel(string $input): ?string
    {
        $candidates = [$input, 'App\\Models\\'.$input];

        foreach ($candidates as $candidate) {
            if (class_exists($candidate) && is_subclass_of($candidate, Model::class)) {
                return $candidate;
            }
        }

        return null;
    }

    private function targetPath(string $class): string
    {
        $name = class_basename($class).'QuerySchema';

        $directory = $this->option('path');

        if (! is_string($directory) || $directory === '') {
            $directory = app_path('AiQueries');
        }

        return rtrim($directory, '/').'/'.$name.'.php';
    }

    /**
     * @param  class-string<Model>  $class
     * @param  list<string>  $sensitive
     */
    private function render(string $class, Model $model, string $table, array $sensitive): string
    {
        $lines = [];
        $skipped = [];

        foreach (Schema::getColumns($table) as $column) {
            /** @var string $name */
            $name = $column['name'];

            if ($this->isSensitive($name, $sensitive)) {
                $skipped[] = $name;

                continue;
            }

            /** @var string $type */
            $type = $column['type_name'];

            $lines[] = $this->renderColumn($name, $type);
        }

        if ($skipped !== []) {
            $lines[] = '';
            $lines[] = '            // Not scaffolded, names suggest secrets: '.implode(', ', $skipped).'.';
            $lines[] = '            // Add them by hand only if an agent genuinely needs them.';
        }

        $relations = $this->renderRelations($model);

        if ($relations !== []) {
            $lines[] = '';
            $lines[] = '            // Relations an agent may traverse. Only declared columns are reachable.';
            $lines = [...$lines, ...$relations];
        }

        return $this->file($class, $table, $lines);
    }

    private function renderColumn(string $name, string $type): string
    {
        $capabilities = $this->capabilitiesFor($type);

        if ($capabilities === []) {
            return "            // ->column('{$name}')";
        }

        $rendered = array_map(
            static fn (string $capability): string => '            //     ->'.$capability,
            $capabilities,
        );

        return implode("\n", [
            "            // ->column('{$name}', fn (ColumnDefinition \$c) => \$c",
            ...$rendered,
        ])."\n            // )";
    }

    /**
     * Suggestions only, and only ones that make sense for the column's type.
     *
     * @return list<string>
     */
    private function capabilitiesFor(string $type): array
    {
        $type = strtolower($type);

        return match (true) {
            $this->matches($type, ['int', 'bigint', 'smallint', 'decimal', 'numeric', 'float', 'double', 'real']) => [
                "aggregatable(['sum', 'avg', 'min', 'max'])",
                "filterable(['=', '>', '<', '>=', '<=', 'between'])",
                'sortable()',
            ],
            $this->matches($type, ['date', 'datetime', 'timestamp']) => [
                "filterable(['=', '>', '<', '>=', '<=', 'between'])",
                "groupableBy(['day', 'week', 'month', 'quarter', 'year'])",
                'sortable()',
            ],
            $this->matches($type, ['bool']) => ["filterable(['='])", 'groupable()'],
            $this->matches($type, ['char', 'text', 'string', 'varchar', 'enum']) => [
                "// enum(['...'])  // declare the closed set if there is one",
                "filterable(['=', 'in', 'like'])",
                'groupable()',
                'sortable()',
            ],
            default => [],
        };
    }

    /**
     * @param  list<string>  $needles
     */
    private function matches(string $type, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($type, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * One level deep only. Nesting every relation of every relation produces a
     * file nobody reads, and depth is capped by maxRelationDepth anyway.
     *
     * @return list<string>
     */
    private function renderRelations(Model $model): array
    {
        $lines = [];

        foreach ($this->relationsOf($model) as $name => $relation) {
            $related = $relation->getRelated();
            $lines[] = "            // ->relation('{$name}', fn (RelationDefinition \$r) => \$r";

            if (Schema::hasTable($related->getTable())) {
                foreach (Schema::getColumns($related->getTable()) as $column) {
                    /** @var string $columnName */
                    $columnName = $column['name'];
                    $lines[] = "            //     ->column('{$columnName}')";
                }
            }

            $lines[] = '            // )';
        }

        return $lines;
    }

    /**
     * @return array<string, Relation<Model, Model, mixed>>
     */
    private function relationsOf(Model $model): array
    {
        $relations = [];

        foreach ((new ReflectionClass($model))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->getNumberOfParameters() > 0) {
                continue;
            }

            if ($method->getDeclaringClass()->getName() !== $model::class) {
                continue;
            }

            $type = $method->getReturnType();

            if (! $type instanceof ReflectionNamedType || ! is_subclass_of($type->getName(), Relation::class)) {
                continue;
            }

            try {
                $relation = $model->{$method->getName()}();
            } catch (Throwable) {
                // A relation needing loaded state is not scaffoldable; skip it.
                continue;
            }

            if ($relation instanceof Relation) {
                $relations[$method->getName()] = $relation;
            }
        }

        return $relations;
    }

    /**
     * @param  list<string>  $sensitive
     */
    private function isSensitive(string $name, array $sensitive): bool
    {
        foreach ($sensitive as $pattern) {
            if (Str::is($pattern, $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  class-string<Model>  $class
     * @param  list<string>  $body
     */
    private function file(string $class, string $table, array $body): string
    {
        $namespace = $this->option('namespace');
        $namespace = is_string($namespace) && $namespace !== '' ? $namespace : 'App\\AiQueries';
        $name = class_basename($class).'QuerySchema';

        return implode("\n", [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
            "namespace {$namespace};",
            '',
            'use Illuminate\\Contracts\\Auth\\Authenticatable;',
            'use Illuminate\\Database\\Eloquent\\Builder;',
            'use JTMcC\\AiQueryBuilder\\Schema\\ColumnDefinition;',
            'use JTMcC\\AiQueryBuilder\\Schema\\Contracts\\DefinesQuerySchema;',
            'use JTMcC\\AiQueryBuilder\\Schema\\RelationDefinition;',
            'use JTMcC\\AiQueryBuilder\\Schema\\ResourceSchema;',
            "use {$class};",
            '',
            "final class {$name} implements DefinesQuerySchema",
            '{',
            '    public function define(ResourceSchema $schema): ResourceSchema',
            '    {',
            '        return $schema',
            '            ->for('.class_basename($class).'::class)',
            "            ->name('{$table}')",
            "            // ->describe('What one row of this resource represents.')",
            '',
            '            // Nothing below is active. Uncomment only what an AI agent should',
            '            // be able to select, filter, group or sort on. A column that is',
            '            // never declared does not exist as far as an agent is concerned.',
            '',
            ...$body,
            '',
            '            // Required before this resource is safe to expose. Mandatory scopes',
            '            // are applied before anything in a plan and no plan can remove them.',
            '            // ->alwaysScope(fn (Builder $query, ?Authenticatable $user) => $query',
            "            //     ->where('{$table}.tenant_id', \$user?->tenant_id))",
            '',
            '            ->defaultLimit(100)',
            '            ->maxLimit(1000);',
            '    }',
            '}',
            '',
        ]);
    }
}
