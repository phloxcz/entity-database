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
 * Metadata pro jednu property
 */
final class PropertyMeta
{
    public readonly string $name;
    public readonly ?string $col;
    public readonly string $type;
    public readonly bool $nullable;
    public readonly bool $key;
    public readonly ?string $related;
    public readonly ?string $through;
    public readonly bool $virtual;
    public readonly bool $searchable;
    public readonly ?string $format;
    public readonly ?string $nativeType;
    public readonly ?string $access;
    public readonly ?string $comment;
    public readonly ?string $ref;
    public readonly ?string $condition;
    public readonly ?string $json;

    public function __construct(array $attrs)
    {
        foreach ($attrs as $k => $v) {
            $this->{$k} = $v;
        }
    }
}