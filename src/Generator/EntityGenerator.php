<?php
/**
 * This file is part of phloxcz/entity-database.
 *
 * (c) Phlox <https://github.com/phloxcz>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Phlox\EntityDatabase\Generator;

use Nette;
use Nette\Database\Explorer;
use Nette\IOException;
use Nette\Neon\Neon;
use Nette\Utils\Strings;
use Phlox\EntityDatabase\Generator\Adapter\GeneratorAdapter;
use Phlox\EntityDatabase\Generator\Naming\NamingStrategy;
use Phlox\EntityDatabase\Generator\Renderer\PhpEntityRenderer;
use Phlox\EntityDatabase\Generator\Schema\EntityDefinition;
use Phlox\EntityDatabase\Generator\Schema\EntityDefinitionBuilder;

/**
 * Orchestrates entity class generation from DB schema.
 */
final class EntityGenerator
{
    private array $connections;

    /**
     * Resolved table configs per connection and entity-set index — memoised.
     * Structure: [connectionName][entitySetIndex][tableName] = [...tableConfig...]
     *
     * @var array<string, array<int, array<string, array>>>
     */
    private array $resolvedTableConfigs = [];

    /** @var array<string, GeneratorAdapter> */
    private array $adapters = [];

    public function __construct(
        \stdClass              $config,
        private Nette\DI\Container $container,
        private PhpEntityRenderer  $renderer,
        private NamingStrategy     $namingStrategy,
    ) {
        $this->connections = $config->connections;
    }


    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Generate all configured entity sets.
     *
     * @return string[] Paths of written files.
     */
    public function generate(): array
    {
        $cleaned = [];
        $written = [];

        foreach ($this->connections as $connName => $connConfig) {
            $db = $this->container->getService("database.{$connName}.explorer");

            foreach ($connConfig->entities as $index => $entitySet) {
                $outputDir = rtrim($entitySet->output, '/\\');

                // Clean output directory once per unique dir
                if (!isset($cleaned[$outputDir])) {
                    $this->cleanOutputDir($outputDir, $entitySet->cleanSkip ?? []);
                    $cleaned[$outputDir] = true;
                }

                $tableConfigs = $this->resolveTableConfigs($connName, $index, $entitySet);

                foreach ($tableConfigs as $tName => $tEntry) {
                    $definition = $this->defineClass($db, $entitySet->namespace, $connName, $tName, $tEntry['config']);
                    $written[]  = $this->renderer->render($definition, $outputDir);
                }
            }
        }

        return $written;
    }


    // -------------------------------------------------------------------------
    // Config resolution
    // -------------------------------------------------------------------------

    /**
     * Resolve and merge all table configs for a given entity set.
     *
     * Each item in $entitySet->config can be:
     *   - string  → path to a NEON or JSON file: [tableName => tableConfig, ...]
     *   - array   → inline [tableName => tableConfig, ...] definitions
     *
     * Results are memoised by ($connectionName, $entitySetIndex).
     *
     * @return array<string, array{namespace: string, output: string, config: array}>
     */
    private function resolveTableConfigs(string $connectionName, int $entitySetIndex, object $entitySet): array
    {
        if (isset($this->resolvedTableConfigs[$connectionName][$entitySetIndex])) {
            return $this->resolvedTableConfigs[$connectionName][$entitySetIndex];
        }

        $merged = [];

        foreach ($entitySet->config as $configItem) {
            $tableDefs = [];

            if (is_string($configItem)) {
                if (!is_file($configItem)) {
                    throw new IOException("Entity configuration file '{$configItem}' not found!");
                }
                $ext = strtolower(pathinfo($configItem, PATHINFO_EXTENSION));
                $tableDefs = match ($ext) {
                    'json'  => json_decode(file_get_contents($configItem), true, 512, JSON_THROW_ON_ERROR),
                    default => Neon::decodeFile($configItem),
                };
                if (!is_array($tableDefs)) {
                    throw new IOException("Entity config file '{$configItem}' must contain a table-name → definition mapping.");
                }
            } elseif (is_array($configItem)) {
                $tableDefs = $configItem;
            }

            foreach ($tableDefs as $tableName => $tableDef) {
                $merged[$tableName] = [
                    'namespace' => $entitySet->namespace,
                    'output'    => $entitySet->output,
                    'config'    => $tableDef,
                ];
            }
        }

        $this->resolvedTableConfigs[$connectionName][$entitySetIndex] = $merged;
        return $merged;
    }

    /**
     * Find the resolved config entry for a table across all entity sets of a connection.
     *
     * @return array{namespace: string, output: string, config: array}|null
     */
    private function findTableConfig(string $connectionName, string $table): ?array
    {
        if (empty($this->connections[$connectionName])) {
            return null;
        }
        foreach ($this->connections[$connectionName]->entities as $index => $entitySet) {
            $resolved = $this->resolveTableConfigs($connectionName, $index, $entitySet);
            if (isset($resolved[$table])) {
                return $resolved[$table];
            }
        }
        return null;
    }


    // -------------------------------------------------------------------------
    // Class definition
    // -------------------------------------------------------------------------

    private function defineClass(Explorer $db, string $namespace, string $connectionName, string $table, array $tConfig): EntityDefinition
    {
        if (!isset($tConfig['class'])) {
            throw new Nette\InvalidArgumentException("Missing key 'class' for table '{$table}'.");
        }

        $builder = EntityDefinitionBuilder::forTable($table, $tConfig['class'], $namespace);
        $builder->withComment($tConfig['comment'] ?? null);
        $builder->withExtends($tConfig['extends'] ?? null);
        $builder->withOnClass($this->resolveCallbacks($tConfig['onClass'] ?? []));

        $onDataType = $this->resolveCallbacks($tConfig['onDataType'] ?? []);
        $this->addClassProperties($builder, $db, $table, $tConfig, $onDataType);
        $this->addClassVirtualProperties($builder, $tConfig);
        $this->addClassRefProperties($builder, $db, $table, $connectionName, $tConfig['references'] ?? []);
        $this->addClassHasManyProperties($builder, $table, $connectionName, $tConfig['hasMany'] ?? []);

        return $builder->build();
    }

    /**
     * Resolve a per-table callback config (onClass / onDataType) into real callables.
     *
     * Accepted formats in entities.neon:
     *
     *   Single callback:
     *     onClass: [App\Generator\MyHook, onUser]
     *
     *   Multiple callbacks:
     *     onClass:
     *       - [App\Generator\MyHook, onUser]
     *       - [App\Generator\OtherHook, enhance]
     *
     * @return callable[]
     */
    private function resolveCallbacks(array $raw): array
    {
        if (empty($raw)) {
            return [];
        }

        // Single callback: ['ClassName', 'method']
        if (is_string($raw[0])) {
            $raw = [$raw];
        }

        $callbacks = [];
        foreach ($raw as [$serviceClass, $method]) {
            $service     = $this->container->getByType($serviceClass);
            $callbacks[] = [$service, $method];
        }

        return $callbacks;
    }


    // -------------------------------------------------------------------------
    // Property builders
    // -------------------------------------------------------------------------

    /**
     * Add properties derived from DB columns.
     *
     * New flat config keys consumed from $tConfig:
     *   skip:       string[]         — column names to omit entirely
     *   searchable: string[]         — column names to mark as searchable
     *   rename:     {col: propName}  — override generated property name per column
     *   override:   {col: {type?, format?, access?, comment?, json?}}
     *
     * @param callable[] $onDataType Resolved per-table onDataType hooks — called for
     *                               every column whose type wasn't explicitly overridden,
     *                               letting a custom adapter/hook adjust the DB→PHP type mapping.
     */
    private function addClassProperties(EntityDefinitionBuilder $builder, Explorer $db, string $table, array $tConfig, array $onDataType = []): void
    {
        $skip       = $tConfig['skip']       ?? [];
        $searchable = $tConfig['searchable'] ?? [];
        $rename     = $tConfig['rename']     ?? [];
        $override   = $tConfig['override']   ?? [];

        $adapter    = $this->getGeneratorAdapter($db);
        $structure  = $db->getStructure();
        $columns    = $structure->getColumns($table);

        foreach ($columns as $col) {
            $colName = $col['name'];

            if (in_array($colName, $skip, true)) {
                continue;
            }

            $ov         = $override[$colName] ?? [];
            $nativeType = strtolower($col['nativetype']);

            // JSON columns: the PHP type must match what the generated get hook's
            // json_decode() actually returns (array vs stdClass) — the generic
            // DB-type → PHP-type map can't know that, so it's derived from the
            // resolved decode mode instead, unless explicitly overridden.
            $json = in_array($nativeType, ['json', 'jsonb'], true)
                ? (isset($ov['json']) && in_array($ov['json'], ['object', 'array'], true) ? $ov['json'] : 'array')
                : null;

            $dataType = $ov['type'] ?? ($json !== null
                ? ($json === 'array' ? 'array' : '\stdClass')
                : $adapter->convertType($col['nativetype'], $col['vendor']));

            if (!isset($ov['type']) && $onDataType) {
                $event = new DataTypeEventData($adapter->getDatabaseIdent(), $table, $colName, $dataType, $col['vendor']);
                foreach ($onDataType as $callback) {
                    $callback($event);
                }
                $dataType = $event->getDataType();
            }

            $builder->addProperty(
                column:     $colName,
                nullable:   $col['nullable'],
                key:        $col['primary'],
                type:       $dataType,
                nativeType: $nativeType,
                name:       $rename[$colName] ?? $this->namingStrategy->columnToProperty($colName),
                access:     $ov['access']  ?? 'rw',
                searchable: in_array($colName, $searchable, true),
                format:     $ov['format']  ?? null,
                comment:    $ov['comment'] ?? null,
                json:       $json,
                virtual:    false,
            );
        }
    }

    /**
     * Add virtual properties (no DB column).
     *
     * Config key: virtual: {propName: {type, access, comment, nullable}}
     *
     * Example:
     *   virtual:
     *     fullName: { type: string, access: r }
     *     orderCount: { type: int, access: r, comment: "Počet objednávek" }
     */
    private function addClassVirtualProperties(EntityDefinitionBuilder $builder, array $tConfig): void
    {
        foreach (($tConfig['virtual'] ?? []) as $propName => $propConfig) {
            $builder->addProperty(
                column:     null,
                nullable:   $propConfig['nullable'] ?? true,
                key:        false,
                type:       $propConfig['type']    ?? 'mixed',
                nativeType: null,
                name:       $propName,
                access:     $propConfig['access']  ?? 'r',
                searchable: false,
                format:     null,
                comment:    $propConfig['comment'] ?? null,
                json:       null,
                virtual:    true,
            );
        }
    }

    /**
     * Auto-detect BelongsTo (N:1) references from DB foreign keys.
     *
     * Names can be overridden via the 'references' config key:
     *   references:
     *     role_id: { name: role, comment: "..." }
     */
    private function addClassRefProperties(EntityDefinitionBuilder $builder, Explorer $db, string $table, string $connectionName, array $referencesConfig): void
    {
        $structure = $db->getStructure();
        $belongsTo = $structure->getBelongsToReference($table);

        foreach ($belongsTo as $btColumn => $btTableFull) {
            $btConfig    = $referencesConfig[$btColumn] ?? [];
            $btTableParts = explode('.', $btTableFull);
            $btTable     = end($btTableParts);

            $btTableConfig = $this->findTableConfig($connectionName, $btTable);
            if (!isset($btTableConfig)) {
                continue; // referenced table not configured — skip silently
            }

            if (!isset($btTableConfig['config']['class'])) {
                throw new Nette\InvalidArgumentException("Missing 'class' for referenced table '{$btTable}'.");
            }

            $builder->addReference(
                column:  $btColumn,
                ref:     $btTable,
                type:    '\\' . trim($btTableConfig['namespace'], '\\') . '\\' . ltrim($btTableConfig['config']['class'], '\\'),
                name:    $btConfig['name']    ?? $this->namingStrategy->refToProperty($btColumn),
                comment: $btConfig['comment'] ?? null,
            );
        }
    }

    /**
     * Add HasMany (1:N) collection properties.
     *
     * New flat config key 'hasMany' (replaces nested 'collections'):
     *
     *   hasMany:
     *     orders: { through: user_id }
     *     activeOrders: { table: orders, through: user_id, condition: "status != 'cancelled'" }
     *
     * The key is the property name. 'table' defaults to the property name when omitted.
     */
    private function addClassHasManyProperties(EntityDefinitionBuilder $builder, string $table, string $connectionName, array $hasMany): void
    {
        foreach ($hasMany as $propName => $def) {
            $relatedTable = $def['table']   ?? $propName;
            $through      = $def['through'] ?? null;

            if ($through === null) {
                throw new Nette\InvalidArgumentException(
                    "Missing 'through' for hasMany '{$propName}' on table '{$table}'."
                );
            }

            $colTableConfig = $this->findTableConfig($connectionName, $relatedTable);
            if (!isset($colTableConfig)) {
                continue; // referenced table not configured — skip silently
            }

            if (!isset($colTableConfig['config']['class'])) {
                throw new Nette\InvalidArgumentException("Missing 'class' for hasMany table '{$relatedTable}'.");
            }

            $builder->addCollection(
                column:    $through,
                related:   $relatedTable,
                type:      '\\' . trim($colTableConfig['namespace'], '\\') . '\\' . ltrim($colTableConfig['config']['class'], '\\'),
                name:      $propName,
                comment:   $def['comment']   ?? null,
                condition: $def['condition'] ?? null,
            );
        }
    }


    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function getGeneratorAdapter(Explorer $db): GeneratorAdapter
    {
        $type = explode(':', $db->getConnection()->getDsn())[0];

        if (isset($this->adapters[$type])) {
            return $this->adapters[$type];
        }

        $adapterClass = __NAMESPACE__ . '\\Adapter\\' . ucfirst($type) . 'GeneratorAdapter';
        if (!class_exists($adapterClass)) {
            $adapterClass = __NAMESPACE__ . '\\Adapter\\GenericGeneratorAdapter';
        }

        $this->adapters[$type] = new $adapterClass();
        return $this->adapters[$type];
    }

    private function cleanOutputDir(string $dir, array $cleanSkip): void
    {
        foreach (glob($dir . '/*.php') as $file) {
            $fileSlash = Strings::lower(str_replace('\\', '/', $file));
            foreach ($cleanSkip as $skipName) {
                if (str_ends_with($fileSlash, '/' . Strings::lower($skipName))) {
                    continue 2;
                }
            }
            unlink($file);
        }
    }
}
