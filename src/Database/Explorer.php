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

namespace Phlox\EntityDatabase\Database;

use JetBrains\PhpStorm\Language;
use Nette\Database\Conventions;
use Nette\Database\Connection;
use Nette\Database\Explorer as NExplorer;
use Nette\Database\IStructure;
use Nette\Database\ResultSet;
use Phlox\EntityDatabase\Database\Table\Selection;
use Phlox\EntityDatabase\Entity\EntityFactory;

/**
 * Drop-in replacement for Nette Explorer that returns typed Entity instances
 * instead of plain ActiveRow objects.
 *
 * Methods are wrapped explicitly (not via __call) so IDEs keep full type
 * information, and so transaction() can pass *this* wrapper — not the raw
 * NExplorer — into your callback (see below).
 */
class Explorer
{
    /**
     * Escape hatch to the underlying Nette Explorer, for anything not
     * wrapped explicitly below — mirrors Selection::$nSelection.
     */
    public readonly NExplorer $nExplorer;

    public function __construct(
        private readonly string        $connectionName,
        private readonly object        $config,
        private readonly EntityFactory $entityFactory,
        NExplorer                      $nExplorer,
    ) {
        $this->nExplorer = $nExplorer;
    }

    public function table(string $table): Selection
    {
        return new Selection($this->nExplorer->table($table), $this->entityFactory);
    }

    /**
     * Executes $callback in a transaction. $callback receives *this* Explorer
     * (not the raw Nette one), so entity mapping keeps working inside it.
     */
    public function transaction(callable $callback): mixed
    {
        return $this->nExplorer->transaction(fn() => $callback($this));
    }

    /**
     * Executes raw SQL and returns Nette's ResultSet (no entity mapping —
     * there's no single table/entity to map an arbitrary query onto).
     */
    public function query(#[Language('SQL')] string $sql, #[Language('GenericSQL')] mixed ...$params): ResultSet
    {
        return $this->nExplorer->query($sql, ...$params);
    }

    public function getConnection(): Connection
    {
        return $this->nExplorer->getConnection();
    }

    public function getStructure(): IStructure
    {
        return $this->nExplorer->getStructure();
    }

    public function getConventions(): Conventions
    {
        return $this->nExplorer->getConventions();
    }
}
