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

namespace Phlox\EntityDatabase\DI;

use Nette;
use Nette\Schema\Expect;
use Phlox\EntityDatabase\Console\EntityDatabaseApplication;
use Phlox\EntityDatabase\Database\Explorer;
use Phlox\EntityDatabase\Entity\EntityFactory;
use Phlox\EntityDatabase\Generator\EntityGenerator;
use Phlox\EntityDatabase\Generator\Naming\CamelCaseNamingStrategy;
use Phlox\EntityDatabase\Generator\Renderer\PhpEntityRenderer;
use Phlox\EntityDatabase\Metadata\MetaStorage;

class Extension extends Nette\DI\CompilerExtension
{
    public function getConfigSchema(): Nette\Schema\Schema
    {
        $entityStructure = Expect::structure([
            'namespace'  => Expect::string()->required(),
            'output'     => Expect::string()->required(),
            'config'     => Expect::anyOf(
                Expect::string(),
                Expect::arrayOf(
                    Expect::anyOf(
                        Expect::string(),  // cesta k NEON/JSON souboru
                        Expect::array()    // inline definice: [tableName => [...]]
                    )
                )
            )->required()->before(fn($v) => is_array($v) ? $v : [$v]),
            'cleanSkip'  => Expect::arrayOf(Expect::string())->default([]),
        ]);

        return Expect::structure([
            'connections' => Expect::arrayOf(
                Expect::structure([
                    'schema'        => Expect::string(),
                    'namingStrategy' => Expect::string()->nullable()->default(null),
                    'entities'      => Expect::anyOf(
                        Expect::arrayOf($entityStructure),
                        $entityStructure
                    )->required()->before(fn($v) => array_is_list($v) ? $v : [$v]),
                ])
            )->required(),
        ]);
    }

    public function loadConfiguration(): void
    {
        $builder = $this->getContainerBuilder();

        foreach ($this->config->connections as $name => $config) {
            $this->setupDatabase($builder, $config, $name);
        }

        $builder->addDefinition($this->prefix('entityRenderer'))
            ->setFactory(PhpEntityRenderer::class)
            ->setAutowired(true);

        $builder->addDefinition($this->prefix('entityGenerator'))
            ->setFactory(EntityGenerator::class, [$this->config])
            ->setAutowired(true);

        $builder->addDefinition($this->prefix('namingStrategy'))
            ->setFactory($this->resolveNamingStrategy())
            ->setAutowired(true);
    }

    private function resolveNamingStrategy(): string
    {
        // Allow per-connection override; fall back to global or default
        foreach ($this->config->connections as $config) {
            if ($config->namingStrategy !== null) {
                return $config->namingStrategy;
            }
        }
        return CamelCaseNamingStrategy::class;
    }

    private function setupDatabase(Nette\DI\ContainerBuilder $builder, object $config, string $name): void
    {
        $explorer = $builder->getDefinition("database.{$name}.explorer");

        $entityFactoryDef = $builder->addDefinition($this->prefix("{$name}.entityFactory"))
            ->setFactory(EntityFactory::class, [$this->config->connections[$name]])
            ->setAutowired(true);

        $builder->addDefinition($this->prefix("{$name}.explorer"))
            ->setFactory(Explorer::class, [$name, $config, $entityFactoryDef, $explorer])
            ->setAutowired(true);
    }

    public function beforeCompile(): void
    {
        $builder = $this->getContainerBuilder();

        foreach ($this->config->connections as $name => $config) {
            $connection = $builder->getDefinition("database.{$name}.connection");
            $connection->addSetup([MetaStorage::class, 'initialize'], [$config, '@cache.storage']);
        }
    }

    public function afterCompile(Nette\PhpGenerator\ClassType $class): void
    {
        $initialize = $class->getMethod('initialize');
        $initialize->addBody(
            "// entity-database\n" .
            "(function () {\n" .
            "\tif (PHP_SAPI === 'cli' && defined('_ENTITY_DATABASE_CLI_COMMAND_')) {\n" .
            "\t\t\$app = new \\" . EntityDatabaseApplication::class . "(\n" .
            "\t\t\t\$this->getService('" . $this->prefix('entityGenerator') . "')\n" .
            "\t\t);\n" .
            "\t\t\$app->run();\n" .
            "\t\texit;\n" .
            "\t}\n" .
            "})();"
        );
    }
}
