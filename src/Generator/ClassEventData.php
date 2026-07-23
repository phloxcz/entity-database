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

use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\PhpFile;
use Nette\PhpGenerator\PhpNamespace;
use Phlox\EntityDatabase\Generator\Schema\EntityDefinition;

/**
 * Data passed to per-table onClass callbacks.
 *
 * Gives full access to the Nette PhpGenerator objects so callbacks
 * can add methods, traits, constants, comments, etc.
 */
final class ClassEventData
{
    public function __construct(
        /** The generated class — add methods, traits, constants here. */
        public readonly ClassType      $class,

        /** The PHP namespace wrapping the class. */
        public readonly PhpNamespace   $namespace,

        /** The whole PHP file (useful for adding top-level use statements). */
        public readonly PhpFile        $phpFile,

        /** The entity definition that drove generation (table, properties, …). */
        public readonly EntityDefinition $entity,
    ) {
    }
}
