<?php
/**
 * This file is part of phloxcz/entity-database.
 *
 * (c) Phlox <https://github.com/phloxcz>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */
namespace Phlox\EntityDatabase\Metadata;

/**
 * Metadata pro entitu
 */
final class EntityMeta
{
    public readonly string $class;
    public readonly string $table;
    public readonly string $file;

    /** @var PropertyMeta[] */
    public readonly array $properties;

    /** @var array<string, PropertyMeta> */
    public readonly array $byProp;

    /** @var array<string, PropertyMeta> */
    public readonly array $byCol;

    /** @var array<string, string> */
    public readonly array $searchables;

    public function __construct(array $attrs)
    {
        $searchables = [];
        $byProp = [];
        $byCol = [];

        foreach($attrs as $k => $v) {

            $this->{$k} = $v;

            if($k === 'properties') {
                foreach($v as $prop) {
                    /** @var PropertyMeta $prop */

                    if($prop->searchable ?? false) {
                        $searchables[$prop->name] = $prop->col;
                    }

                    $byProp[$prop->name] = $prop;
                    if ($prop->col !== null) {
                        $byCol[$prop->col] = $prop;
                    }
                }
            }
        }

        $this->searchables = $searchables;
        $this->byProp = $byProp;
        $this->byCol = $byCol;
    }    
}