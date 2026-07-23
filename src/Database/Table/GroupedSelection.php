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
 */
class GroupedSelection extends Selection
{
    public function __construct(NGroupedSelection $nSelection, EntityFactory $entityFactory)
    {
        parent::__construct($nSelection, $entityFactory);
    }
}
