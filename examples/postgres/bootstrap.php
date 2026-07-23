<?php
/**
 * Boots a Nette DI container for the Postgres example — mirrors
 * example-mysql/bootstrap.php but points at PostgreSQL. Defaults match the
 * `postgres` service in docker-compose.yml at the repo root (`docker compose up -d`).
 *
 *   ENTITYDB_PG_HOST      (default 127.0.0.1)
 *   ENTITYDB_PG_PORT      (default 5442 — mapped from the postgres container's 5432,
 *                          a non-standard port so it doesn't clash with a local install)
 *   ENTITYDB_PG_DB        (default entity_database_example)
 *   ENTITYDB_PG_USER      (default postgres)
 *   ENTITYDB_PG_PASSWORD  (default postgres)
 */

declare(strict_types=1);

use Nette\Bridges\CacheDI\CacheExtension;
use Nette\Bridges\DatabaseDI\DatabaseExtension;
use Nette\DI\Compiler;
use Nette\DI\ContainerLoader;
use Phlox\EntityDatabase\DI\Extension as EntityDatabaseExtension;

require __DIR__ . '/../../vendor/autoload.php';

$rootDir = __DIR__;
$tempDir = $rootDir . '/temp';

$dbHost = getenv('ENTITYDB_PG_HOST') ?: '127.0.0.1';
$dbPort = getenv('ENTITYDB_PG_PORT') ?: '5442';
$dbName = getenv('ENTITYDB_PG_DB') ?: 'entity_database_example';
$dbUser = getenv('ENTITYDB_PG_USER') ?: 'postgres';
$dbPass = getenv('ENTITYDB_PG_PASSWORD') ?: 'postgres';

$loader = new ContainerLoader($tempDir, true);
$class  = $loader->load(function (Compiler $compiler) use ($rootDir, $dbHost, $dbPort, $dbName, $dbUser, $dbPass): void {
    $compiler->addConfig([
        'parameters' => ['appDir' => $rootDir, 'rootDir' => $rootDir],
        'database'   => [
            'default' => [
                'dsn'      => "pgsql:host={$dbHost};port={$dbPort};dbname={$dbName}",
                'user'     => $dbUser,
                'password' => $dbPass,
            ],
        ],
        'entityDatabase' => [
            'connections' => [
                'default' => [
                    'entities' => [
                        [
                            'namespace' => 'Examples\Postgres\Entity',
                            'output'    => $rootDir . '/Entity',
                            'config'    => [$rootDir . '/config/entities.neon'],
                        ],
                    ],
                ],
            ],
        ],
    ]);
    $compiler->addExtension('cache', new CacheExtension($rootDir . '/temp'));
    $compiler->addExtension('database', new DatabaseExtension());
    $compiler->addExtension('entityDatabase', new EntityDatabaseExtension());
}, ['example-postgres-container', $dbHost, $dbPort, $dbName]);

return new $class();
