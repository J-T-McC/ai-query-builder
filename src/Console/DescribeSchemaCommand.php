<?php

declare(strict_types=1);

namespace JTMcC\AiQueryBuilder\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
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
        {--json : Print the JSON Schema instead of the prompt text}';

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

        $this->output->writeln($this->option('json')
            ? (string) json_encode($contract->toJsonSchema(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            : $contract->toPrompt());

        return self::SUCCESS;
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
