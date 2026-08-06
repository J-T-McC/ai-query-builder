<?php

declare(strict_types=1);

namespace JTMcC\AiQueryBuilder\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\ObjectType;
use Illuminate\JsonSchema\Types\Type;
use JTMcC\AiQueryBuilder\Ai\PlanSchemaDetail;
use JTMcC\AiQueryBuilder\Ai\PlanToolSchema;
use JTMcC\AiQueryBuilder\Contract\SchemaContract;
use JTMcC\AiQueryBuilder\Schema\SchemaRegistry;

/**
 * Prints exactly what an agent would be told about a resource.
 *
 * Contracts are built per user, so `--user` is the way to confirm that a gated
 * column really is absent for someone who should not see it — reading the
 * schema definition cannot tell you that.
 */
final class DescribeSchemaCommand extends Command
{
    protected $signature = 'ai-query:describe
        {resource? : The registered resource name. Omit to list them all}
        {--user= : Describe it as this user sees it, by primary key}
        {--json : Print the JSON Schema instead of the prompt text}
        {--cost : Print what the contract costs on the wire instead of the contract}';

    protected $description = 'Print the contract an AI agent receives for a resource';

    public function handle(SchemaRegistry $registry, Repository $config): int
    {
        $name = $this->argument('resource');

        if (! is_string($name) || $name === '') {
            return $this->listResources($registry);
        }

        if (! $registry->has($name)) {
            $this->components->error("No resource named [{$name}] is registered.");
            $this->listResources($registry);

            return self::FAILURE;
        }

        $user = $this->resolveUser($config);

        if ($user === false) {
            return self::FAILURE;
        }

        $contract = SchemaContract::for($registry->get($name), $user);

        if ($this->option('cost')) {
            return $this->reportCost($contract);
        }

        $this->output->writeln($this->option('json')
            ? (string) json_encode($contract->toJsonSchema(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            : $contract->toPrompt());

        return self::SUCCESS;
    }

    /**
     * What this contract costs an agent, in the bytes a provider actually receives.
     *
     * Both halves are resent on every step of an agent loop, so a resource that
     * is expensive to describe is expensive on turns that never query it.
     */
    private function reportCost(SchemaContract $contract): int
    {
        $description = strlen($contract->toPrompt());
        $properties = $this->schemaProperties($contract, PlanToolSchema::DEFAULT_FILTER_DEPTH);
        $schema = strlen($this->encode($properties));

        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>section</>', '<fg=gray>chars / est. tokens</>');
        $this->components->twoColumnDetail('description', $this->size($description));
        $this->components->twoColumnDetail('input_schema', $this->size($schema));
        $this->components->twoColumnDetail('<options=bold>every step</>', '<options=bold>'.$this->size($description + $schema).'</>');

        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>input_schema by plan property</>', '<fg=gray>chars / share</>');

        $accounted = 0;

        foreach ($this->propertySizes($properties) as $property => $size) {
            $accounted += $size;
            $this->components->twoColumnDetail($property, $this->share($size, $schema));
        }

        $this->components->twoColumnDetail('<fg=gray>envelope</>', $this->share($schema - $accounted, $schema));

        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>input_schema by filter depth</>', '<fg=gray>chars / against current</>');

        foreach (range(1, PlanToolSchema::DEFAULT_FILTER_DEPTH + 1) as $depth) {
            $at = strlen($this->encode($this->schemaProperties($contract, $depth)));

            $this->components->twoColumnDetail(
                'depth '.$depth,
                $depth === PlanToolSchema::DEFAULT_FILTER_DEPTH
                    ? number_format($at).' <fg=gray>current</>'
                    : $this->delta($at, $schema),
            );
        }

        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>input_schema by detail level</>', '<fg=gray>chars / against current</>');

        foreach (PlanSchemaDetail::cases() as $detail) {
            $at = strlen($this->encode($this->schemaProperties($contract, PlanToolSchema::DEFAULT_FILTER_DEPTH, $detail)));

            $this->components->twoColumnDetail(
                lcfirst($detail->name),
                $detail === PlanSchemaDetail::Enumerated
                    ? number_format($at).' <fg=gray>current</>'
                    : $this->delta($at, $schema),
            );
        }

        $this->newLine();
        $this->components->info('Token counts are a chars/4 estimate, not a tokenizer count.');

        // Shrinking the payload is the second lever, not the first. A warm
        // prompt cache bills this whole block at roughly a tenth, which is
        // worth more than anything the numbers above can be reduced to.
        $this->components->info('Enable prompt caching before optimising this. It bills a warm prefix at ~10% — see the README.');

        return self::SUCCESS;
    }

    /**
     * @return array<string, Type>
     */
    private function schemaProperties(
        SchemaContract $contract,
        int $depth,
        PlanSchemaDetail $detail = PlanSchemaDetail::Enumerated,
    ): array {
        return (new PlanToolSchema($contract, $depth, $detail))->build(new JsonSchemaTypeFactory);
    }

    private function delta(int $chars, int $against): string
    {
        return sprintf(
            '%s <fg=gray>%s%s</>',
            number_format($chars),
            $chars > $against ? '+' : '-',
            number_format(abs($chars - $against)),
        );
    }

    /**
     * The schema exactly as an AI SDK puts it on the wire.
     *
     * Composing it through `ObjectType` reproduces what `laravel/ai` sends byte
     * for byte, so measuring here needs no AI SDK installed.
     *
     * @param  array<string, Type>  $properties
     */
    private function encode(array $properties): string
    {
        $schema = (new ObjectType($properties))->withoutAdditionalProperties()->toArray();

        return (string) json_encode([
            'type' => 'object',
            'properties' => (object) $this->arrayValue($schema, 'properties'),
            'required' => $this->arrayValue($schema, 'required'),
        ]);
    }

    /**
     * @param  array<string, Type>  $properties
     * @return array<string, int>
     */
    private function propertySizes(array $properties): array
    {
        $composed = (new ObjectType($properties))->withoutAdditionalProperties()->toArray();
        $sizes = [];

        foreach ($this->arrayValue($composed, 'properties') as $property => $definition) {
            $sizes[(string) $property] = strlen((string) json_encode($definition));
        }

        arsort($sizes);

        return $sizes;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<array-key, mixed>
     */
    private function arrayValue(array $schema, string $key): array
    {
        $value = $schema[$key] ?? [];

        return is_array($value) ? $value : [];
    }

    private function size(int $chars): string
    {
        return sprintf('%s <fg=gray>~%s</>', number_format($chars), number_format(intdiv($chars, 4)));
    }

    private function share(int $chars, int $total): string
    {
        return sprintf('%s <fg=gray>%d%%</>', number_format($chars), $total === 0 ? 0 : (int) round($chars / $total * 100));
    }

    private function listResources(SchemaRegistry $registry): int
    {
        $names = $registry->names();

        if ($names === []) {
            $this->components->warn('No resources are registered. Add them to the ai-query-builder.resources config.');

            return self::SUCCESS;
        }

        $this->components->bulletList($names);

        return self::SUCCESS;
    }

    /**
     * @return Authenticatable|null|false False signals a failure already reported.
     */
    private function resolveUser(Repository $config): Authenticatable|null|false
    {
        $id = $this->option('user');

        // A string from the CLI, an int when called programmatically.
        if (! is_string($id) && ! is_int($id)) {
            return null;
        }

        $id = (string) $id;

        if ($id === '') {
            return null;
        }

        /** @var class-string<Model>|null $class */
        $class = $config->get('auth.providers.users.model');

        if ($class === null || ! class_exists($class)) {
            $this->components->error('No auth user model is configured, so --user cannot be resolved.');

            return false;
        }

        $user = $class::query()->find($id);

        if (! $user instanceof Authenticatable) {
            $this->components->error("No user found with key [{$id}].");

            return false;
        }

        return $user;
    }
}
