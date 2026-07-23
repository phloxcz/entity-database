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

use Phlox\EntityDatabase\Entity\Entity;

final class EntityDefinitionBuilder
{
    private string $extends;
    private ?string $comment;
    private array $properties  = [];
    private array $references  = [];
    private array $collections = [];
    private array $onClass     = [];

    private function __construct(
        private string $table,
        private string $name,
        private string $namespace,
    ) {
    }

    public static function forTable(string $table, string $entityName, string $namespace): self
    {
        return new self($table, $entityName, $namespace);
    }

    public function withComment(?string $comment): self
    {
        $this->comment = $comment;
        return $this;
    }

    public function withExtends(?string $className): self
    {
        $this->extends = $className ?? Entity::class;
        return $this;
    }

    /**
     * @param callable[] $callbacks Already-resolved callables.
     */
    public function withOnClass(array $callbacks): self
    {
        $this->onClass = $callbacks;
        return $this;
    }

    public function addProperty(...$args): self
    {
        $prop = new EntityPropDefinition(...$args);
        $this->properties[$prop->name] = $prop;
        return $this;
    }

    public function addReference(...$args): self
    {
        $ref = new EntityRefDefinition(...$args);
        $this->references[$ref->name] = $ref;
        return $this;
    }

    public function addCollection(...$args): self
    {
        $collection = new EntityRelatedDefinition(...$args);
        $this->collections[$collection->name] = $collection;
        return $this;
    }

    public function hasMember(string $name): bool
    {
        return isset($this->properties[$name])
            || isset($this->references[$name])
            || isset($this->collections[$name]);
    }

    public function build(): EntityDefinition
    {
        return new EntityDefinition(
            table:       $this->table,
            name:        $this->name,
            namespace:   $this->namespace,
            comment:     $this->comment,
            extends:     $this->extends,
            properties:  $this->properties,
            references:  $this->references,
            collections: $this->collections,
            onClass:     $this->onClass,
        );
    }
}
