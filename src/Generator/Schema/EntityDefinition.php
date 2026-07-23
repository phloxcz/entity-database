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

namespace Phlox\EntityDatabase\Generator\Schema;

final class EntityDefinition
{
    public readonly string $table;
    public readonly string $name;
    public readonly string $namespace;
    public readonly ?string $comment;
    public readonly ?string $extends;

    /** @var EntityPropDefinition[] */
    public readonly array $properties;

    /** @var EntityRefDefinition[] */
    public readonly array $references;

    /** @var EntityRelatedDefinition[] */
    public readonly array $collections;

    /**
     * Per-table onClass callbacks, already resolved to real callables.
     * @var callable[]
     */
    public readonly array $onClass;

    public function __construct(
        string $table,
        string $name,
        string $namespace,
        ?string $comment,
        ?string $extends,
        array $properties,
        array $references,
        array $collections,
        array $onClass = [],
    ) {
        $this->table       = $table;
        $this->name        = $name;
        $this->namespace   = $namespace;
        $this->comment     = $comment;
        $this->extends     = $extends;
        $this->properties  = $properties;
        $this->references  = $references;
        $this->collections = $collections;
        $this->onClass     = $onClass;
    }
}
