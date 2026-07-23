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

namespace Phlox\EntityDatabase\Entity;

use Nette\Database\Table\ActiveRow;
use Phlox\EntityDatabase\Database\Table\Selection;
use Phlox\EntityDatabase\Metadata\EntityMeta;

/**
 * Internal state bag for an Entity instance.
 *
 * Kept in a separate object so that Entity subclasses have a single
 * protected property ($_ctx) and no risk of name-collision with generated
 * entity properties.
 */
final class EntityContext
{
    /** The underlying Nette ActiveRow (null when entity was constructed from array). */
    public ?ActiveRow  $activeRow = null;

    /** The Selection this entity was fetched from (null when constructed from array). */
    public ?Selection  $selection = null;

    /** Cached entity metadata (table name, property map, …). */
    public ?EntityMeta $meta      = null;

    /**
     * Tracks which properties have been modified via setters.
     * Keys are property names, values are always true.
     *
     * @var array<string, true>
     */
    public array $modified = [];

    /**
     * Stores values written via property hook setters, indexed by property name.
     * On read the hook returns from here when $modified[$prop] is set,
     * otherwise it falls back to $activeRow.
     *
     * @var array<string, mixed>
     */
    public array $values = [];
}
