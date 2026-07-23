<?php
/**
 * Runs EntityGenerator against the Postgres connection.
 */

declare(strict_types=1);

use Phlox\EntityDatabase\Generator\EntityGenerator;

/** @var Nette\DI\Container $container */
$container = require __DIR__ . '/bootstrap.php';

$generator = $container->getByType(EntityGenerator::class);
$written   = $generator->generate();

if (empty($written)) {
    echo "No entities were generated.\n";
    exit(1);
}

foreach ($written as $file) {
    echo "  ✔ {$file}\n";
}
echo "\nDone. " . count($written) . " file(s) written.\n";
