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

namespace Phlox\EntityDatabase\Database\Table;

use Nette;
use Nette\Database\Table\ActiveRow;
use Nette\Database\Table\GroupedSelection as NGroupedSelection;
use Nette\Database\Table\Selection as NSelection;
use Phlox\EntityDatabase\Database\Search\GenericSearchAdapter;
use Phlox\EntityDatabase\Database\Search\SearchAdapter;
use Phlox\EntityDatabase\Entity\Entity;
use Phlox\EntityDatabase\Entity\EntityFactory;
use Phlox\EntityDatabase\Exception\SearchException;
use Phlox\EntityDatabase\Metadata\MetaStorage;

/**
 * Wrapper around Nette Selection that returns typed Entity instances
 * instead of ActiveRow objects.
 *
 * T is the table's entity class: $explorer->table('user') is a
 * Selection<\App\Entity\User> for PHPStan with the bundled extension
 * (extension.neon, see README › PHPStan). The fluent methods of Nette's
 * Selection reach it through __call() and are declared below.
 *
 * @template T of Entity
 * @implements \IteratorAggregate<int|string, T>
 *
 * @method static select(string $columns, mixed ...$params)
 * @method static wherePrimary(mixed $key)
 * @method static where(string|array<int|string, mixed> $condition, mixed ...$params)
 * @method static joinWhere(string $tableChain, string $condition, mixed ...$params)
 * @method static whereOr(array<string, mixed> $parameters)
 * @method static order(string $columns, mixed ...$params)
 * @method static limit(?int $limit, ?int $offset = null)
 * @method static page(int $page, int $itemsPerPage, ?int &$numOfPages = null)
 * @method static group(string $columns, mixed ...$params)
 * @method static having(string $having, mixed ...$params)
 * @method static alias(string $tableChain, string $alias)
 * @method static setPrimarySequence(string $sequence)
 * @method ?string getPrimarySequence()
 * @method string getSql()
 * @method mixed fetchField(?string $column = null)
 * @method array<mixed> fetchAssoc(string $path)
 * @method mixed aggregation(string $function, ?string $groupFunction = null)
 * @method mixed min(string $column)
 * @method mixed max(string $column)
 * @method mixed sum(string $column)
 * @method \Nette\Database\Explorer getExplorer()
 */
class Selection implements \IteratorAggregate, \Countable
{
    public readonly EntityFactory $entityFactory;
    public readonly NSelection $nSelection;

    public function __construct(NSelection $nSelection, EntityFactory $entityFactory)
    {
        $this->nSelection    = $nSelection;
        $this->entityFactory = $entityFactory;
    }

    /**
     * Iterates the underlying Selection lazily (query runs on first access),
     * yielding typed Entity instances instead of ActiveRow.
     *
     * @return \Generator<int|string, T>
     */
    public function getIterator(): \Generator
    {
        foreach ($this->nSelection as $key => $activeRow) {
            yield $key => $this->entityFactory->create($activeRow, $this);
        }
    }

    /**
     * Without $column: counts already-fetched rows in memory (executing the
     * query first if it hasn't run yet) — also what makes count($selection)
     * work via the Countable interface. With $column: runs a SQL COUNT(),
     * mirroring Nette's own Selection::count($column) exactly.
     */
    public function count(?string $column = null): int
    {
        return $this->nSelection->count($column);
    }

    /**
     * Returns the entity class name for the current table.
     */
    public function getName(): string
    {
        return $this->entityFactory->getEntityName($this->nSelection->getName());
    }

    /**
     * Returns the underlying DB table name.
     */
    public function getTableName(): string
    {
        return $this->nSelection->getName();
    }

    /**
     * Returns primary key column name(s).
     */
    public function getPrimary(bool $throw = true): string|array|null
    {
        return $this->nSelection->getPrimary($throw);
    }

    /**
     * Separators folded to spaces before splitting $term into words — so e.g.
     * "BDP-International" also matches a DB value stored as "BDP International"
     * (the hyphen would otherwise force an exact, unlikely-to-match substring).
     */
    private const SearchSpecialChars = [
        ':',';','-','.',',','_','[',']',
        '(',')','{','}','/','*','+','=',
        '|','\\','\'','"','<','>','?','!',
        '@','&','~','`','–','—',
        '„','“','‘','’','”','‚'
    ];

    /**
     * Filters the selection to rows where every word of $term appears
     * (case-insensitively) in the same `searchable: true` column — columns
     * matched this way are then OR'd together. So "Jan Novák" matches a
     * "full_name" column holding "Novák Jan" or "Jan Petr Novák" too, not
     * just an exact "...Jan Novák..." substring.
     *
     * $term is first split into words on whitespace and on the separator
     * characters in self::SearchSpecialChars (so "BDP-International" and
     * "BDP International" search identically) — unless $term consists
     * entirely of such characters, in which case it's kept as one literal
     * word (so searching for a literal "@" or "-" still works).
     *
     * $ignoreDiacritics additionally folds accents (e.g. "e" matches "é"),
     * but requires DB-side support: MySQL 8+, Postgres with the `unaccent`
     * extension enabled (`CREATE EXTENSION IF NOT EXISTS unaccent;`), or
     * SQL Server. Throws SearchException on drivers without it (e.g. SQLite)
     * rather than silently ignoring the request.
     *
     * Note: this typically can't use a plain index (LOWER()/COLLATE coercion
     * defeats a standard B-tree index) unless you add a matching expression
     * index — a query-cost tradeoff you should make deliberately, not one
     * this method can make for you.
     */
    public function search(string $term, bool $ignoreDiacritics = false): static
    {
        $meta = MetaStorage::get($this->getName());
        if (!$meta->searchables) {
            throw new SearchException(
                "Entity '{$meta->class}' has no searchable properties (mark columns with searchable: true in entities.neon).",
                SearchException::NoSearchableProperties,
            );
        }

        $words = $this->tokenizeSearchTerm($term);
        if (!$words) {
            return $this;
        }

        $adapter    = $this->resolveSearchAdapter();
        $driver     = $this->nSelection->getExplorer()->getConnection()->getDriver();
        $conditions = [];
        $params     = [];

        foreach ($meta->searchables as $column) {
            $columnDb       = $driver->delimite($column);
            $wordConditions = [];
            foreach ($words as $word) {
                [$sql, $conditionParams] = $adapter->buildCondition($columnDb, $word, $ignoreDiacritics);
                $wordConditions[] = $sql;
                array_push($params, ...$conditionParams);
            }
            $conditions[] = '(' . implode(' AND ', $wordConditions) . ')';
        }

        $this->nSelection->where('(' . implode(' OR ', $conditions) . ')', ...$params);

        return $this;
    }

    /** @return string[] */
    private function tokenizeSearchTerm(string $term): array
    {
        if (preg_match('#^[' . preg_quote(implode('', self::SearchSpecialChars), '#') . ']+$#u', $term)) {
            return [$term];
        }

        $normalized = str_replace(self::SearchSpecialChars, ' ', $term);

        return array_values(array_filter(explode(' ', $normalized), static fn(string $w) => $w !== ''));
    }

    private function resolveSearchAdapter(): SearchAdapter
    {
        $type  = explode(':', $this->nSelection->getExplorer()->getConnection()->getDsn())[0];
        $class = 'Phlox\\EntityDatabase\\Database\\Search\\' . ucfirst($type) . 'SearchAdapter';

        return class_exists($class) ? new $class() : new GenericSearchAdapter();
    }

    /**
     * Returns a single Entity by primary key, or null.
     *
     * @return T|null
     */
    public function get(mixed $key): ?Entity
    {
        $clone = clone $this;
        return $clone->wherePrimary($key)->fetch();
    }

    /**
     * Same as get(), but returns the raw ActiveRow — skips entity mapping.
     */
    public function rawGet(mixed $key): ?ActiveRow
    {
        $clone = clone $this;
        return $clone->wherePrimary($key)->rawFetch();
    }

    /**
     * Fetches the next row as a typed Entity, or null when exhausted.
     *
     * @return T|null
     */
    public function fetch(): ?Entity
    {
        $row = $this->nSelection->fetch();
        return $row instanceof ActiveRow
            ? $this->entityFactory->create($row, $this)
            : null;
    }

    /**
     * Same as fetch(), but returns the raw ActiveRow — skips entity mapping.
     * Useful when a query doesn't map cleanly onto a single entity (custom
     * aggregates, a SELECT spanning columns from a JOIN, ...).
     */
    public function rawFetch(): ?ActiveRow
    {
        return $this->nSelection->fetch();
    }

    /**
     * Fetches all rows as an array of typed Entities, keyed by the primary key.
     *
     * @return array<int|string, T>
     */
    public function fetchAll(): array
    {
        return array_map(
            fn(ActiveRow $activeRow) => $this->entityFactory->create($activeRow, $this),
            $this->nSelection->fetchAll()
        );
    }

    /**
     * Same as fetchAll(), but returns raw ActiveRow instances — skips entity mapping.
     *
     * @return ActiveRow[]
     */
    public function rawFetchAll(): array
    {
        return $this->nSelection->fetchAll();
    }

    /**
     * Fetches key-value pairs.
     * Both key and value are passed as DB column names (not property names).
     *
     * @return array<mixed, mixed>
     */
    public function fetchPairs(string|int|\Closure|null $keyOrCallback = null, string|int|null $value = null): array
    {
        return Nette\Database\Helpers::toPairs($this->fetchAll(), $keyOrCallback, $value);
    }

    /**
     * Same as fetchPairs(), but built from raw ActiveRow instances — skips entity mapping.
     *
     * @return array<mixed, mixed>
     */
    public function rawFetchPairs(string|int|\Closure|null $keyOrCallback = null, string|int|null $value = null): array
    {
        return Nette\Database\Helpers::toPairs($this->rawFetchAll(), $keyOrCallback, $value);
    }

    /**
     * Inserts a row and returns the created Entity (or affected row count / the
     * inserted data for tables without a usable primary key — see Nette's own
     * Selection::insert() for the exact edge cases).
     *
     * One row (an array keyed by column names) into a table with a primary
     * key gives its Entity — the usual case; several rows give how many.
     *
     * @param  iterable<mixed>  $data  Keyed by DB column names.
     * @return ($data is array<string, mixed> ? T : T|int|array<mixed>)
     */
    public function insert(iterable $data): Entity|int|array
    {
        $result = $this->nSelection->insert($data);
        return $result instanceof ActiveRow
            ? $this->entityFactory->create($result, $this)
            : $result;
    }

    /**
     * Updates rows matching the current selection.
     *
     * @param  iterable<mixed>  $data  Keyed by DB column names.
     * @return int  Number of affected rows.
     */
    public function update(iterable $data): int
    {
        return $this->nSelection->update($data);
    }

    /**
     * Deletes rows matching the current selection.
     *
     * @return int  Number of deleted rows.
     */
    public function delete(): int
    {
        return $this->nSelection->delete();
    }

    /**
     * Creates an Entity instance from an already-fetched ActiveRow.
     * Useful when you have a raw ActiveRow from Nette and want to wrap it.
     *
     * @return T
     */
    public function createEntity(ActiveRow $activeRow): Entity
    {
        return $this->entityFactory->create($activeRow, $this);
    }

    /**
     * Wraps a Nette GroupedSelection in our typed GroupedSelection.
     * Called by Entity property hooks for HasMany relations (their @var names the entity).
     *
     * @return GroupedSelection<Entity>
     */
    public function createGroupedSelection(NGroupedSelection $nGroupedSelection): GroupedSelection
    {
        return new GroupedSelection($nGroupedSelection, $this->entityFactory);
    }

    /**
     * Delegates unknown method calls to the underlying Nette Selection (fluent interface).
     * If NSelection returns itself, we return $this so the wrapper stays in place.
     *
     * @param array<mixed> $args
     */
    public function __call(string $name, array $args): mixed
    {
        $result = $this->nSelection->$name(...$args);
        return $result === $this->nSelection ? $this : $result;
    }
}
