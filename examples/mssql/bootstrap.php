<?php
/**
 * Boots a Nette DI container for the SQL Server example. Defaults match the
 * `mssql` service in docker-compose.yml at the repo root (`docker compose up -d`).
 *
 * Requires the pdo_sqlsrv extension (Microsoft Drivers for PHP for SQL Server) —
 * not bundled with PHP, see https://learn.microsoft.com/sql/connect/php/download-drivers-php-sql-server
 *
 *   ENTITYDB_MSSQL_HOST      (default 127.0.0.1)
 *   ENTITYDB_MSSQL_PORT      (default 1435 — mapped from the mssql container's 1433,
 *                              a non-standard port so it doesn't clash with a local install)
 *   ENTITYDB_MSSQL_DB        (default entity_database_example)
 *   ENTITYDB_MSSQL_USER      (default sa)
 *   ENTITYDB_MSSQL_PASSWORD  (default matches docker-compose.yml's MSSQL_SA_PASSWORD)
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

$dbHost = getenv('ENTITYDB_MSSQL_HOST') ?: '127.0.0.1';
$dbPort = getenv('ENTITYDB_MSSQL_PORT') ?: '1435';
$dbName = getenv('ENTITYDB_MSSQL_DB') ?: 'entity_database_example';
$dbUser = getenv('ENTITYDB_MSSQL_USER') ?: 'sa';
$dbPass = getenv('ENTITYDB_MSSQL_PASSWORD') ?: 'EntityDb!Passw0rd';

$loader = new ContainerLoader($tempDir, true);
$class  = $loader->load(function (Compiler $compiler) use ($rootDir, $dbHost, $dbPort, $dbName, $dbUser, $dbPass): void {
    $compiler->addConfig([
        'parameters' => ['appDir' => $rootDir, 'rootDir' => $rootDir],
        'database'   => [
            'default' => [
                // Encrypt=no: the container uses a self-signed cert — fine for local dev, not for production.
                'dsn'      => "sqlsrv:Server={$dbHost},{$dbPort};Database={$dbName};Encrypt=no",
                'user'     => $dbUser,
                'password' => $dbPass,
            ],
        ],
        'entityDatabase' => [
            'connections' => [
                'default' => [
                    'entities' => [
                        [
                            'namespace' => 'Examples\Mssql\Entity',
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
}, ['example-mssql-container', $dbHost, $dbPort, $dbName]);

return new $class();
