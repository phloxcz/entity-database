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

use Nette\Database\Table\GroupedSelection as NGroupedSelection;
use Phlox\EntityDatabase\Entity\EntityFactory;

/**
 * Wrapper around Nette GroupedSelection (lazy-loaded HasMany relations).
 * Returns typed Entity instances instead of ActiveRow.
 *
 * @template T of \Phlox\EntityDatabase\Entity\Entity
 * @extends Selection<T>
 */
class GroupedSelection extends Selection
{
    public function __construct(NGroupedSelection $nSelection, EntityFactory $entityFactory)
    {
        parent::__construct($nSelection, $entityFactory);
    }

    /**
     * A row of a relation belongs to its parent, which Nette only knows from within.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function prepareInsert(array $data): array
    {
        $parent = (fn(): array => [$this->column => $this->active])->call($this->nSelection);

        return $parent + $data;
    }
}
