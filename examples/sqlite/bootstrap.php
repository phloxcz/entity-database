<?php
/**
 * Boots a Nette DI container for the example app without depending on
 * nette/bootstrap (not a dependency of this library) — assembled directly
 * from Nette\DI\Compiler using only packages entity-database already requires.
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

$loader = new ContainerLoader($tempDir, true);
$class  = $loader->load(function (Compiler $compiler) use ($rootDir): void {
    $compiler->addConfig(['parameters' => ['appDir' => $rootDir, 'rootDir' => $rootDir]]);
    $compiler->addExtension('cache', new CacheExtension($rootDir . '/temp'));
    $compiler->addExtension('database', new DatabaseExtension());
    $compiler->addExtension('entityDatabase', new EntityDatabaseExtension());
    $compiler->loadConfig($rootDir . '/config/config.neon');
}, 'example-container');

return new $class();
