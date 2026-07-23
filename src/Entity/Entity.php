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
use Phlox\EntityDatabase\Exception\EntityException;
use Phlox\EntityDatabase\Metadata\MetaStorage;

/**
 * Abstract base class for all generated entities.
 *
 * Entities wrap a Nette ActiveRow via composition.  All DB access is delegated
 * to the underlying row; generated subclasses add typed property hooks on top.
 *
 * The single protected property $_ctx keeps the internal state isolated so that
 * generated property names never collide with framework internals.
 */
abstract class Entity implements \ArrayAccess, \IteratorAggregate
{
    /** @internal Internal state — do not access from application code. */
    protected readonly EntityContext $_ctx;

    /**
     * @param ActiveRow|array|null $data   ActiveRow when fetched from DB, or key→value array
     *                                     when constructing manually (e.g. in tests).
     * @param Selection|null       $selection  The Selection this row was fetched from.
     */
    public function __construct(ActiveRow|array|null $data = null, ?Selection $selection = null)
    {
        $this->_ctx           = new EntityContext();
        $this->_ctx->meta     = MetaStorage::get(static::class);
        $this->_ctx->selection = $selection;

        if ($data instanceof ActiveRow) {
            $this->_ctx->activeRow = $data;
        } elseif (is_array($data)) {
            foreach ($data as $prop => $val) {
                $this->{$prop} = $val;
            }
        }
    }


    // -------------------------------------------------------------------------
    // IteratorAggregate
    // -------------------------------------------------------------------------

    public function getIterator(): \Iterator
    {
        return new \ArrayIterator($this->toArray());
    }


    // -------------------------------------------------------------------------
    // ArrayAccess
    // -------------------------------------------------------------------------

    public function offsetGet(mixed $offset): mixed
    {
        return $this->{$offset};
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->{$offset} = $value;
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->{$offset});
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->{$offset});
    }


    // -------------------------------------------------------------------------
    // Magic accessors — fallback for properties without hooks (e.g. extra SELECTed columns)
    // -------------------------------------------------------------------------

    public function __get(string $name): mixed
    {
        return $this->_ctx->activeRow?->__get($name);
    }

    public function __set(string $name, mixed $value): void
    {
        // Write to modified overlay — does NOT propagate to activeRow.
        // Generated property hooks use $this->_ctx->values / $this->_ctx->modified directly.
        $this->_ctx->values[$name]   = $value;
        $this->_ctx->modified[$name] = true;
    }

    public function __isset(string $name): bool
    {
        return isset($this->_ctx->modified[$name]) || $this->_ctx->activeRow?->__isset($name);
    }

    public function __unset(string $name): void
    {
        unset($this->_ctx->values[$name], $this->_ctx->modified[$name]);
    }


    // -------------------------------------------------------------------------
    // Data export
    // -------------------------------------------------------------------------

    /**
     * Returns all properties as an associative array keyed by property name.
     * Modified (unsaved) values take precedence over DB values — each
     * property's own get hook is the single source of truth for this.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [];

        foreach ($this->_ctx->meta->properties as $propMeta) {
            // Skip virtual properties and relations (BelongsTo/HasMany) — only
            // plain DB-backed scalar columns belong in a flat array export.
            if ($propMeta->virtual || $propMeta->col === null || $propMeta->ref !== null || $propMeta->related !== null) {
                continue;
            }
            $result[$propMeta->name] = $this->{$propMeta->name};
        }

        return $result;
    }

    /**
     * Returns the raw DB column → value array from the underlying ActiveRow.
     * Does not apply property-name mapping or modified tracking.
     *
     * @return array<string, mixed>
     */
    public function toArrayDb(): array
    {
        return $this->_ctx->activeRow?->toArray() ?? [];
    }


    // -------------------------------------------------------------------------
    // Persistence
    // -------------------------------------------------------------------------

    /**
     * Persists every property changed via a typed setter since the entity was
     * fetched (or since the last successful saveModified()) as a single
     * UPDATE, scoped by primary key. Clears the dirty state on success.
     *
     * Only works on an entity that came from the database — for a new row,
     * use Selection::insert() instead (which already returns an Entity).
     *
     * @return bool  False when there was nothing modified to save.
     * @throws EntityException When the entity has no underlying ActiveRow.
     */
    public function saveModified(): bool
    {
        $activeRow = $this->requireActiveRow('saveModified');

        if (!$this->_ctx->modified) {
            return false;
        }

        $data = [];
        foreach (array_keys($this->_ctx->modified) as $propName) {
            $propMeta = $this->_ctx->meta->byProp[$propName] ?? null;
            if ($propMeta === null || $propMeta->virtual || $propMeta->col === null) {
                continue; // virtual properties and relations aren't real columns
            }
            $data[$propMeta->col] = EntityHelpers::encodeForStorage($propMeta, $this->{$propName});
        }

        $result = $activeRow->update($data);

        if ($result) {
            $this->_ctx->modified = [];
        }

        return $result;
    }

    /**
     * Deletes the underlying row.
     *
     * @return int Number of deleted rows (0 or 1).
     * @throws EntityException When the entity has no underlying ActiveRow.
     */
    public function delete(): int
    {
        return $this->requireActiveRow('delete')->delete();
    }

    // -------------------------------------------------------------------------
    // Delegation to ActiveRow for Nette Database methods (ref, related, …)
    // -------------------------------------------------------------------------

    public function __call(string $name, array $args): mixed
    {
        $activeRow = $this->requireActiveRow($name);
        $result    = $activeRow->{$name}(...$args);
        return $result === $activeRow ? $this : $result;
    }

    private function requireActiveRow(string $method): ActiveRow
    {
        return $this->_ctx->activeRow ?? throw new EntityException(
            "Cannot call '{$method}()' on an entity without an underlying ActiveRow.",
            EntityException::INVALID_CALL,
        );
    }
}
