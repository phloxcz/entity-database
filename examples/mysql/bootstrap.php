<?php
/**
 * Boots a Nette DI container for the MySQL example — mirrors example/bootstrap.php
 * but points the `database:` extension at a real MySQL server via env vars, so
 * plugging in your own instance is just a matter of setting them. Defaults match
 * the `mysql` service in docker-compose.yml at the repo root (`docker compose up -d`).
 *
 *   ENTITYDB_MYSQL_HOST      (default 127.0.0.1)
 *   ENTITYDB_MYSQL_PORT      (default 3316 — mapped from the mysql container's 3306,
 *                              a non-standard port so it doesn't clash with a local install)
 *   ENTITYDB_MYSQL_DB        (default entity_database_example)
 *   ENTITYDB_MYSQL_USER      (default root)
 *   ENTITYDB_MYSQL_PASSWORD  (default '')
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

$dbHost = getenv('ENTITYDB_MYSQL_HOST') ?: '127.0.0.1';
$dbPort = getenv('ENTITYDB_MYSQL_PORT') ?: '3316';
$dbName = getenv('ENTITYDB_MYSQL_DB') ?: 'entity_database_example';
$dbUser = getenv('ENTITYDB_MYSQL_USER') ?: 'root';
$dbPass = getenv('ENTITYDB_MYSQL_PASSWORD') ?: '';

$loader = new ContainerLoader($tempDir, true);
$class  = $loader->load(function (Compiler $compiler) use ($rootDir, $dbHost, $dbPort, $dbName, $dbUser, $dbPass): void {
    $compiler->addConfig([
        'parameters' => ['appDir' => $rootDir, 'rootDir' => $rootDir],
        'database'   => [
            'default' => [
                'dsn'      => "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4",
                'user'     => $dbUser,
                'password' => $dbPass,
            ],
        ],
        'entityDatabase' => [
            'connections' => [
                'default' => [
                    'entities' => [
                        [
                            'namespace' => 'Examples\Mysql\Entity',
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
    // Re-compiles automatically whenever the target host/port/db changes.
}, ['example-mysql-container', $dbHost, $dbPort, $dbName]);

return new $class();
